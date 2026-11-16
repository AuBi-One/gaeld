# Expense Claims plugin

Expense claims for employees and for owners/organs who are not on payroll:
kilometric allowances (rate per vehicle type and validity period), meals,
accommodation and other costs; booking at approval; reimbursement with the
salary, by bank, or grouped at period end into a debt record of the company
towards the person.

## What it adds

| Where | What |
|---|---|
| Expenses › Expense claims | list, create/edit drafts, approve (books Dr 6640 · Cr 2210/2260), pay by bank, receipts; km lines go from/to a place, a contact with an address (becomes a client place that follows the contact's address) or any address (saved as a place of kind other, reused) |
| Expenses › Expense balances | unpaid claims and debts per person; convert to a debt record at a closing date; repay a debt by bank |
| Organisation settings › Expense claims | people (employee link, owner/organ flag, home), places (office, homes, clients) with federal geocoding, km rates, accounts |
| Payroll › Run payroll | each employee's approved claims and open debts, ticked by default, paid with the salary from their own liability account |
| Accounting › Year-end closing | step 2 warns about draft and unpaid claims of the year |

Accounting follows the Swiss SME chart (Banana): 6640 travel expenses; 2210 owed to
staff; 2260/2560 owed to owners and organs, short/long term (CO 959a). All codes are
settings; a missing account is created, an existing one is never renamed.

Access: viewing needs `expenses.view`; creating, approving, paying and settings need
`expenses.approve` (the ledger is written at approval).

## Configuration (.env)

```
PLUGINS_ENABLED=true
EXPENSE_CLAIMS_ROUTING=ors            # ors | osrm | none
EXPENSE_CLAIMS_ORS_KEY=               # free key from openrouteservice.org
EXPENSE_CLAIMS_OSRM_URL=              # when ROUTING=osrm, e.g. a self-hosted server
```

Geocoding uses `api3.geo.admin.ch` (no key): addresses of places, of contacts chosen
as destinations and typed in the claim form. Only coordinates are sent to the
routing service. Without routing, km are entered by hand.

Rebuild the frontend after enabling the plugin (`pnpm build`), run migrations.

## Airtable migration

```
php artisan expense-claims:import-airtable Représentation.json --employees=Employé.json --org=<uuid>
php artisan expense-claims:import-airtable … --commit --post-adjustment=2025-12-31
```

Dry run by default: prints the claims, the person each goes to and the difference
between Airtable's amount and the recomputed one. Set up people (names as in
Airtable's Employé table) and places first.

## Core extension points used

- `App\Support\Plugins\PluginNavigation` — sidebar entries
- `App\Domains\Payroll\Contracts\ReimbursementSourceInterface` — payroll items
- `App\Domains\Accounting\Contracts\ClosingCheckInterface` (tag `gaeld.closing_checks`)

## Tests

```
php artisan test --testsuite=Plugins
./vendor/bin/phpstan analyse -c plugins/expense-claims/phpstan.neon
```
