<?php

declare(strict_types=1);

/**
 * This file is part of the AbraFlexi Paid Invoice Dispatch package
 *
 * (c) Vítězslav Dvořák <http://spojenet.cz/>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace SpojeNet\AbraFlexiPaidInvoiceDispatch\Tests;

use PHPUnit\Framework\TestCase;
use SpojeNet\AbraFlexiPaidInvoiceDispatch\Dispatcher;

class DispatcherTest extends TestCase
{
    /** @var array<int, array<int, string>> */
    private array $calls = [];

    /**
     * @param array<string, mixed> $extra
     */
    private function invoice(array $extra = [], string $kod = 'code:KREDIT_DOMENA'): \AbraFlexi\FakturaVydana
    {
        $data = $extra + [
            'stitky' => 'API',
            'stavUhrK' => 'stavUhr.uhrazeno',
            'polozkyFaktury' => [['kod' => $kod], ['kod' => $kod], ['kod' => 'code:KREDIT_VOIP']],
        ];
        $invoice = $this->getMockBuilder(\AbraFlexi\FakturaVydana::class)->disableOriginalConstructor()->onlyMethods(['getDataValue', 'getRecordIdent'])->getMock();
        $invoice->method('getDataValue')->willReturnCallback(static fn ($k) => $data[$k] ?? null);
        $invoice->method('getRecordIdent')->willReturn('code:ZAL1/2026');

        return $invoice;
    }

    /**
     * @param array<int, array<string, mixed>> $runTemplates
     */
    private function dispatcher(array $runTemplates, bool $scheduleOk = true, bool $dryRun = false, ?int $company = null): Dispatcher
    {
        $cli = function (array $args) use ($runTemplates, $scheduleOk): ?array {
            $this->calls[] = $args;

            return match ($args[0]) {
                'application:list' => [['id' => 1, 'executable' => '/usr/bin/abraflexi-domain-credit'], ['id' => 2, 'executable' => '/usr/bin/abraflexi-voip-credit']],
                'run-template:list' => $runTemplates,
                'run-template:schedule' => $scheduleOk ? ['scheduled' => true] : null,
                default => [],
            };
        };

        return new Dispatcher(Dispatcher::DEFAULT_PRODUCT_APPS, $company, $dryRun, $cli, static fn (string $p): ?string => null);
    }

    private function scheduled(): int
    {
        return \count(array_filter($this->calls, static fn (array $c): bool => 'run-template:schedule' === $c[0]));
    }

    public function testExistingRunTemplateIsScheduledOncePerProduct(): void
    {
        $report = $this->dispatcher([['id' => 7, 'app_id' => 1, 'name' => 'Domain', 'active' => 1]])->processInvoice($this->invoice());
        $this->assertSame('success', $report['status']);
        $this->assertSame(1, $this->scheduled());
        $this->assertSame(7, $report['dispatched'][0]['runtemplate']);
        $this->assertSame('KREDIT_VOIP', $report['skipped'][0]['product']);
    }

    public function testScheduleCarriesInvoiceAndProduct(): void
    {
        $this->dispatcher([['id' => 7, 'app_id' => 1, 'name' => 'x', 'active' => 1]])->processInvoice($this->invoice());
        $schedule = array_values(array_filter($this->calls, static fn (array $c): bool => 'run-template:schedule' === $c[0]))[0];
        $this->assertContains('INVOICE_ID=code:ZAL1/2026', $schedule);
        $this->assertContains('PRODUCT_CODE=KREDIT_DOMENA', $schedule);
        $this->assertContains('--id=7', $schedule);
    }

    public function testInactiveRunTemplateIsSkipped(): void
    {
        $report = $this->dispatcher([['id' => 7, 'app_id' => 1, 'name' => 'x', 'active' => 0]])->processInvoice($this->invoice());
        $this->assertSame('skipped', $report['status']);
        $this->assertSame(0, $this->scheduled());
        $this->assertSame(0, $report['exitcode']);
    }

    public function testAmbiguousRunTemplatesAreNotScheduled(): void
    {
        $report = $this->dispatcher([['id' => 7, 'app_id' => 1, 'name' => 'a', 'active' => 1], ['id' => 8, 'app_id' => 1, 'name' => 'b', 'active' => 1]])->processInvoice($this->invoice());
        $this->assertSame(0, $this->scheduled());
        $this->assertSame(1, $report['exitcode']);
    }

    public function testCompanyFilterIsPassedToMultiflexi(): void
    {
        $this->dispatcher([], true, false, 3)->processInvoice($this->invoice());
        $list = array_values(array_filter($this->calls, static fn (array $c): bool => 'run-template:list' === $c[0]))[0];
        $this->assertContains('--company_id=3', $list);
    }

    public function testUnpaidOrNoLabelNothingHappens(): void
    {
        $d = $this->dispatcher([['id' => 7, 'app_id' => 1, 'name' => 'x', 'active' => 1]]);
        $this->assertSame('skipped', $d->processInvoice($this->invoice(['stavUhrK' => 'stavUhr.neuhrazeno', 'zbyvaUhradit' => '300']))['status']);
        $this->assertSame('skipped', $d->processInvoice($this->invoice(['stitky' => '']))['status']);
        $this->assertSame([], $this->calls);
    }

    public function testScheduleFailureIsReported(): void
    {
        $report = $this->dispatcher([['id' => 7, 'app_id' => 1, 'name' => 'x', 'active' => 1]], false)->processInvoice($this->invoice());
        $this->assertSame(1, $report['exitcode']);
        $this->assertSame('error', $report['status']);
    }

    public function testDryRunSchedulesNothing(): void
    {
        $report = $this->dispatcher([['id' => 7, 'app_id' => 1, 'name' => 'x', 'active' => 1]], true, true)->processInvoice($this->invoice());
        $this->assertSame(0, $this->scheduled());
        $this->assertTrue($report['dispatched'][0]['dryrun']);
    }
}
