# AuBi-One changelog

What changed for users of AuBi-One's Gäld, version by version. Each version is
built on an upstream Gäld release; what upstream changed is in
[CHANGELOG.md](CHANGELOG.md), under the version named at the top of each section.

The format loosely follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
A version is dated the day it reached production. Versions not listed
(3.8.1-aubi.7, 3.8.28-aubi.1, 3.8.28-aubi.3, 3.8.28-aubi.4) failed their build and were never released.
3.8.28-aubi.2 was tested only; its changes reached production with 3.8.28-aubi.5.

## [Unreleased]

### Changed
- Payroll: salary slips are no longer e-mailed to employees when posted. To send them
  automatically, tick "Envoyer les fiches de salaire par e-mail à la comptabilisation"
  under Paramètres > Général.

## [3.8.28-aubi.6] - 2026-11-27

Based on Gäld 3.8.28.

### Added
- Payroll: after posting, a "Payer" step downloads the salary payment file for
  e-banking; unpaid salaries are also listed under Banque > Paiements à émettre. The
  file is separate from supplier payments and marked as salaries, so the bank books
  it as one amount. A salary can be put back on the list if the bank rejects the file.

### Changed
- Payroll: "Salaires" in the menu opens "Calculer les salaires"; the month proposed
  is the one after the last salary slip (else the current month), and the preview names the
  period, e.g. "Aperçu – novembre 2026".
- Salary slip PDFs are named like `Salaire_2026-11-30_David_Robson.pdf`.
- Security updates of the web framework components and the passkey libraries.

### Fixed
- An employee could not be set to "Inactif".
- Settings > Expenses listed the default categories by internal names such as
  `cat_office_supplies`; they show their names in each user's language again.

## [3.8.28-aubi.5] - 2026-11-17

Based on Gäld 3.8.28. Includes everything listed under 3.8.28-aubi.2.

### Added
- Expense claims: a trip can go from or to any contact with an address, or to
  any other address (searched in the Swiss address directory, or typed as is).
  A contact used this way becomes a client place; correct its address under
  Settings > Expense claims. Everyone who enters claims sees contacts by name
  and city, also without access to Contacts.
- Addresses typed by an employee are offered again to that employee; managers
  see all of them.

### Changed
- Contacts: the default expense category is chosen from the list of expense
  categories.
- Contacts: the salutation is only asked for a person; an organisation has none.
- Security update of the Laravel framework (13.43).

### Fixed
- A contact created from an expense ("new supplier") stored the category as a
  code, which the contact form did not recognise; such codes are replaced by the
  category name.
- Migrated invoices showed their import date as the last reminder sent.

## [3.8.28-aubi.2] - Not released (included in 3.8.28-aubi.5)

Based on Gäld 3.8.28.

### Added
- The dashboard shows how many months the cash on hand covers.
- "Write off rest" on an invoice books the unpaid remainder as a loss, with the
  VAT the invoice reported. If payments and credit notes already cover the
  invoice, it is marked paid and nothing is booked.
- Journal entries can be corrected in a guided dialog, also entries of a closed
  year (the correction is dated in the open year).
- An invoice that was reverted can be finalized again; its new booking gets a
  reference such as INV-2026-001-v2.
- A payment entered by mistake can be removed; the invoice is open again.
- Bank matching finds a part-paid invoice for its next instalment.
- Payroll: set the payday under Settings > General; a run or slip can set its own
  date within the month, and salary slips are booked on that date.
- Salary bookings are described in the organisation's language.

### Fixed
- Cancelling a credit note left its invoice marked as paid; the invoice now
  reopens.
- A cancelled invoice or expense still showed in the VAT report; every reversal
  now also takes back the VAT.
- Closing a year failed with half-yearly VAT settlements.
- Reports for a closed year showed a profit and loss of zero.
- The dashboard ignored manual journal entries.
- Lines of archived journal entries could still be edited.
- Employee AHV number, IBAN and date of birth were written to the activity log.
- Invitations did not work for people without an account yet.
- Emptying a contact field (email, IBAN, BIC) did not clear it on save.

### Changed
- Payment reminders are sent by hand from the invoice page; the automatic
  per-invoice reminders are gone.
- Manual entries can no longer use references starting with REV-, PAY-, EXP-,
  EC- and the other prefixes kept for automatic bookings.
- An invoice with payments can no longer be cancelled: issue a credit note, or
  remove the payments first.
- The closing entry of a year can no longer be reversed by hand: reopen the year
  and close it again.
- A reverted invoice (back to draft) can no longer be deleted once one of its
  earlier bookings lies in a settled VAT period or a closed year.

## [3.8.1-aubi.9] - 2026-11-10

Based on Gäld 3.8.1.

### Added
- Invoice and offer numbers advance by a step set in the settings (AuBi-One: 6).
- Offer templates have a PDF preview. A first template, "Offre standard", is set
  up from the migrated offers.
- An invoice's PDF is stored when it is finalized, so download, email and journal
  all show the same document.

## [3.8.1-aubi.8] - 2026-11-05

Based on Gäld 3.8.1.

### Added
- Expenses can be approved and posted from the expense list.

### Fixed
- The bank refused the payment file. The file now carries every element the
  bank's example has.
- Payment files for EUR payments to Swiss creditors, and for unknown Swiss banks,
  were wrong.
- The same expense could be posted twice from two browser tabs.

## [3.8.1-aubi.6] - 2026-11-05

Based on Gäld 3.8.1.

### Added
- Contacts have an "Account holder" field next to the IBAN, for suppliers whose
  bank account name differs from their trading name. Empty means the contact
  name.

## [3.8.1-aubi.5] - 2026-11-05

Based on Gäld 3.8.1.

### Fixed
- An edited expense could not be set to "no VAT".
- The checkboxes on "Payments to issue" did not change the selection.
- Payment files lacked the addresses Swiss banks require. A supplier without a
  town or country is now flagged on the payments page.

## [3.8.1-aubi.4] - 2026-11-05

Based on Gäld 3.8.1.

### Fixed
- The dashboard's "close the year" button led to a missing page. It is now shown
  only to users who may close the year.

## [3.8.1-aubi.3] - 2026-11-05

Based on Gäld 3.8.1.

### Fixed
- A renamed account showed its original name on some pages.

## [3.8.1-aubi.2] - 2026-11-03

Based on Gäld 3.8.1.

### Fixed
- A debt repayment could be posted while the debt was still a draft; posting the
  debt now posts its repayments with it.

## [3.8.1-aubi.1] - 2026-11-03

First AuBi-One version, based on Gäld 3.8.1.

### Added
- Expense claims: claims and kilometre allowances per person, reimbursed later or
  paid at once, with approval and balances.
- Offers: templates, PDF after AuBi-One's Word model, tracking, and invoices
  created from an offer's positions.
- Invoice and salary slip PDFs after AuBi-One's Word models.
- Supporting documents on journal entries, viewable from the journal list.
- Invoices addressed to a named contact person ("Attention: …").
- Social-charge rates (AVS, LPP, LAA and others) are set per period under
  Payroll > Social charges; a missing rate gives an error instead of a silent
  default.
- Salary certificate box 15 and the employee's address on the certificate.

### Fixed
- The trial balance included draft entries and hid some account balances.
- Revenue accounts 3000 and 3400 were labelled the wrong way round; invoices now
  book their revenue to the account set for the organisation.
