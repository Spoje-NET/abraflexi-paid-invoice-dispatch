# abraflexi-paid-invoice-dispatch

MultiFlexi application started by an **event rule** (`faktura-vydana` + operation `settled`, produced by `abraflexi-webhook-acceptor`).
It reads the paid invoice and schedules the MultiFlexi run-templates of the ordered services **if they exist** (e.g. `abraflexi-domain-credit` for `KREDIT_DOMENA`, `abraflexi-voip-credit` for `KREDIT_VOIP`).

1. `INVOICE_ID` (or `EVENT_RECORD_ID` / `--invoice`); the invoice must be paid and carry the `API` label.
2. Each product code on the invoice is mapped to a target: `PRODUCT_APPS` JSON map first, then the AbraFlexi price list attribute `API` (`valString`).
3. A target matches an **active** run-template whose application executable (or run-template name) equals it. Exactly one match is required; none = skipped, several = error (narrow with `DISPATCH_COMPANY_ID`).
4. `multiflexi-cli run-template:schedule --id=N --env INVOICE_ID=... --env PRODUCT_CODE=...`

Exit codes: `0` ok/nothing to do, `1` scheduling failed or ambiguous, `2` missing invoice id.

## Event rule

```
multiflexi-cli event-rule:create --event_source_id=<webhook acceptor source> --evidence=faktura-vydana \
  --operation=settled --runtemplate_id=<this app's run-template> --env_mapping='{"INVOICE_ID":"recordid"}'
```

## Development

```
composer install
vendor/bin/phpunit
```
