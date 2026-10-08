<?php

declare(strict_types=1);

/**
 * This file is part of the AbraFlexi Paid Invoice Dispatch package
 *
 * https://github.com/Spoje-NET/abraflexi-paid-invoice-dispatch
 *
 * (c) Vítězslav Dvořák <http://spojenet.cz/>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace SpojeNet\AbraFlexiPaidInvoiceDispatch;

/**
 * Start MultiFlexi run-templates for the services ordered on a paid invoice.
 *
 * For every product code on the invoice a target application is resolved
 * (PRODUCT_APPS map first, then the AbraFlexi price list attribute "API").
 * When a matching active run-template exists in MultiFlexi it is scheduled
 * with INVOICE_ID / PRODUCT_CODE; products without a run-template are skipped.
 *
 * @author vitex
 */
class Dispatcher extends \Ease\Sand
{
    public const PENDING_LABEL = 'API';

    /**
     * Product code => application executable (or run-template name).
     */
    public const DEFAULT_PRODUCT_APPS = [
        'KREDIT_DOMENA' => 'abraflexi-domain-credit',
        'KREDIT_VOIP' => 'abraflexi-voip-credit',
    ];

    /**
     * @var callable(array<int, string>): ?array<mixed> runs multiflexi-cli with arguments, returns decoded JSON or null on failure
     */
    private $cli;

    /**
     * @var callable(string): ?string AbraFlexi attribute lookup (product code => value)
     */
    private $attributeLookup;

    /**
     * @param array<string, string> $productApps
     */
    public function __construct(
        private array $productApps = self::DEFAULT_PRODUCT_APPS,
        private ?int $companyId = null,
        private bool $dryRun = false,
        ?callable $cli = null,
        ?callable $attributeLookup = null,
    ) {
        $this->cli = $cli ?? [self::class, 'multiflexiCli'];
        $this->attributeLookup = $attributeLookup ?? [self::class, 'abraflexiAttribute'];
    }

    /**
     * @return array{status: string, message: string, invoice: string, dispatched: array<int, array<string, mixed>>, skipped: array<int, array<string, string>>, exitcode: int}
     */
    public function processInvoice(\AbraFlexi\FakturaVydana $invoice): array
    {
        $ident = (string) $invoice->getRecordIdent();
        $report = ['status' => 'skipped', 'message' => '', 'invoice' => $ident, 'dispatched' => [], 'skipped' => [], 'exitcode' => 0];

        if (!$this->isPaid($invoice)) {
            $report['message'] = sprintf(_('%s is not paid'), $ident);

            return $report;
        }

        if (!\in_array(self::PENDING_LABEL, array_map([\AbraFlexi\Functions::class, 'uncode'], array_keys(\AbraFlexi\Stitek::listToArray($invoice->getDataValue('stitky')))), true)) {
            $report['message'] = sprintf(_('%s: nothing pending (no %s label)'), $ident, self::PENDING_LABEL);

            return $report;
        }

        $runTemplates = null;

        foreach ($this->productCodes($invoice) as $product) {
            $target = $this->targetFor($product);

            if (null === $target) {
                $report['skipped'][] = ['product' => $product, 'reason' => 'no target application'];

                continue;
            }

            $runTemplates ??= $this->runTemplates();
            $matches = array_values(array_filter($runTemplates, static fn (array $rt): bool => $rt['target'] === $target));

            if (1 !== \count($matches)) {
                $report['skipped'][] = ['product' => $product, 'reason' => 0 === \count($matches) ? sprintf('no active run-template for %s', $target) : sprintf('%d run-templates for %s - ambiguous', \count($matches), $target)];

                if (\count($matches) > 1) {
                    $report['exitcode'] = 1;
                    $this->addStatusMessage(sprintf(_('%s: %d active run-templates for %s; set DISPATCH_COMPANY_ID'), $product, \count($matches), $target), 'error');
                }

                continue;
            }

            $id = $matches[0]['id'];

            if ($this->dryRun || null !== $this->schedule($id, $ident, $product)) {
                $report['dispatched'][] = ['product' => $product, 'runtemplate' => $id, 'application' => $target, 'dryrun' => $this->dryRun];
                $this->addStatusMessage(sprintf(_('%s: run-template #%d (%s) scheduled for %s'), $product, $id, $target, $ident), 'success');
            } else {
                $report['skipped'][] = ['product' => $product, 'reason' => sprintf('scheduling run-template #%d failed', $id)];
                $report['exitcode'] = 1;
                $this->addStatusMessage(sprintf(_('%s: scheduling run-template #%d failed'), $product, $id), 'error');
            }
        }

        $report['status'] = $report['exitcode'] ? 'error' : ($report['dispatched'] ? 'success' : 'skipped');
        $report['message'] = sprintf(_('%s: %d dispatched, %d skipped'), $ident, \count($report['dispatched']), \count($report['skipped']));

        return $report;
    }

    /**
     * Fully paid invoice? Exact state match (note "stavUhr.neuhrazeno" contains "uhrazeno").
     */
    public function isPaid(\AbraFlexi\FakturaVydana $invoice): bool
    {
        $state = (string) $invoice->getDataValue('stavUhrK');
        $remaining = (string) $invoice->getDataValue('zbyvaUhradit');

        if ('' !== $remaining && (float) $remaining > 0.0) {
            return false;
        }

        return \in_array($state, ['stavUhr.uhrazeno', 'stavUhr.uhrazenoRucne'], true) || ('' !== $remaining && 0.0 === (float) $remaining);
    }

    /**
     * @return array<int, string> distinct product codes (without "code:")
     */
    public function productCodes(\AbraFlexi\FakturaVydana $invoice): array
    {
        $codes = [];
        $items = $invoice->getDataValue('polozkyFaktury');

        foreach (\is_array($items) ? $items : [] as $item) {
            $kod = $item['kod'] ?? '';
            $kod = \is_array($kod) ? (string) ($kod['kod'] ?? reset($kod)) : (string) $kod;
            $kod = \AbraFlexi\Functions::uncode($kod);

            if ('' !== $kod) {
                $codes[$kod] = $kod;
            }
        }

        return array_values($codes);
    }

    public function targetFor(string $product): ?string
    {
        if (isset($this->productApps[$product])) {
            return $this->productApps[$product];
        }

        $attribute = ($this->attributeLookup)($product);

        return empty($attribute) ? null : (string) $attribute;
    }

    /**
     * Active run-templates with their resolved target (app executable basename or run-template name).
     *
     * @return array<int, array{id: int, target: string, name: string}>
     */
    public function runTemplates(): array
    {
        $apps = [];

        foreach (($this->cli)(['application:list', '--fields=id,executable']) ?? [] as $app) {
            $apps[(int) $app['id']] = basename((string) ($app['executable'] ?? ''));
        }

        $args = ['run-template:list', '--fields=id,app_id,name,active,company_id'];

        if (null !== $this->companyId) {
            $args[] = '--company_id='.$this->companyId;
        }

        $found = [];

        foreach (($this->cli)($args) ?? [] as $rt) {
            if (!(int) ($rt['active'] ?? 0)) {
                continue;
            }

            foreach ([$apps[(int) ($rt['app_id'] ?? 0)] ?? '', (string) ($rt['name'] ?? '')] as $target) {
                if ('' !== $target) {
                    $found[] = ['id' => (int) $rt['id'], 'target' => $target, 'name' => (string) ($rt['name'] ?? '')];
                }
            }
        }

        return $found;
    }

    private function schedule(int $runTemplateId, string $invoice, string $product): ?array
    {
        return ($this->cli)(['run-template:schedule', '--id='.$runTemplateId, '--env', 'INVOICE_ID='.$invoice, '--env', 'PRODUCT_CODE='.$product]);
    }

    /**
     * Run multiflexi-cli and decode its JSON output.
     *
     * @param array<int, string> $arguments
     *
     * @return null|array<mixed>
     */
    public static function multiflexiCli(array $arguments): ?array
    {
        $command = 'multiflexi-cli '.implode(' ', array_map('escapeshellarg', $arguments)).' --format=json 2>/dev/null';
        exec($command, $output, $code);

        if (0 !== $code) {
            return null;
        }

        $decoded = json_decode(implode("\n", $output), true);

        return \is_array($decoded) ? $decoded : [];
    }

    public static function abraflexiAttribute(string $product): ?string
    {
        $atributor = new \AbraFlexi\RW(null, ['evidence' => 'atribut']);
        $atribut = $atributor->getColumnsFromAbraFlexi(['id', 'valString'], ['cenik' => \AbraFlexi\Functions::code($product), 'typAtributu' => 'code:API']);

        return empty($atribut) ? null : (string) $atribut[0]['valString'];
    }
}
