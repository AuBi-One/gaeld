# AuBi-One changelog

What changed for users of AuBi-One's Gäld, version by version. Each version is
built on an upstream Gäld release; what upstream changed is in
[CHANGELOG.md](CHANGELOG.md), under the version named at the top of each section.

The format loosely follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
A version is dated the day it reached production. Versions not listed
(3.8.1-aubi.7, 3.8.28-aubi.1) failed their build and were never released.

## [3.8.28-aubi.2] - Unreleased (on test)

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
