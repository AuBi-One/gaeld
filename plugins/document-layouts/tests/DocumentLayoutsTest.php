<?php

namespace Plugins\DocumentLayouts\Tests;

require_once __DIR__.'/DocumentLayoutsTestCase.php';

use App\Domains\Accounting\Models\VatRate;
use App\Domains\Banking\Models\BankAccount;
use App\Domains\Contacts\Models\Contact;
use App\Domains\Invoicing\Actions\GenerateQrInvoicePdfAction;
use App\Domains\Invoicing\Enums\InvoiceLineType;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Invoicing\Models\InvoiceLine;
use App\Domains\Payroll\Models\DeductionRate;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Payroll\Models\SalarySlip;
use App\Support\Pdf\PdfLayouts;
use Plugins\DocumentLayouts\Layouts\WordModelSalarySlipLayout;
use Plugins\DocumentLayouts\Support\Format;
use Plugins\DocumentLayouts\Support\Letterhead;

class DocumentLayoutsTest extends DocumentLayoutsTestCase
{
    public function test_both_layouts_are_offered_in_the_settings(): void
    {
        $settings = app(PdfLayouts::class)->forSettings($this->org);

        $this->assertSame(['invoice', 'salary_slip'], array_column($settings, 'document'));
        foreach ($settings as $document) {
            $this->assertSame('word-model', $document['options'][0]['key']);
            $this->assertNull($document['current']);
        }
    }

    public function test_the_letterhead_takes_e_mail_and_phone_from_the_organisation(): void
    {
        $this->org->update(['legal_name' => 'Exemple SA', 'address' => 'Rue du Test 1', 'postal_code' => '1000', 'city' => 'Lausanne', 'country' => 'CH', 'vat_number' => 'CHE-123.456.789', 'invoice_email' => 'info@exemple.test', 'invoice_phone' => '+41 21 000 00 00']);

        $letterhead = Letterhead::for($this->org->fresh());

        $this->assertSame('Exemple SA', $letterhead->name);
        $this->assertSame(['Rue du Test 1', '1000 Lausanne'], $letterhead->addressLines);
        $this->assertSame('info@exemple.test', $letterhead->email);
        $this->assertSame('+41 21 000 00 00', $letterhead->phone);
        $this->assertSame('CHE-123.456.789 TVA', $letterhead->vatNumberWithSuffix('fr'));
        $this->assertSame('CHE-123.456.789 MWST', $letterhead->vatNumberWithSuffix('de'));
    }

    public function test_the_invoice_layout_renders_its_pages_and_core_the_qr_bill_page(): void
    {
        $invoice = $this->invoice(lines: 3);
        $this->org->update(['pdf_layouts' => [PdfLayouts::INVOICE => 'word-model']]);

        $pdf = app(GenerateQrInvoicePdfAction::class)->execute($invoice, $this->org->fresh(), 'fr');

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertSame(2, $this->pageCount($pdf));
        $this->assertStringContainsString('Carlito', $pdf, 'The layout embeds Carlito');
        $this->assertStringContainsString('Helvetica', $pdf, 'The payment part keeps its standard font');
    }

    public function test_a_long_invoice_continues_on_further_pages_before_the_qr_bill_page(): void
    {
        $invoice = $this->invoice(lines: 60);
        $this->org->update(['pdf_layouts' => [PdfLayouts::INVOICE => 'word-model']]);

        $pdf = app(GenerateQrInvoicePdfAction::class)->execute($invoice, $this->org->fresh(), 'de');

        $this->assertGreaterThanOrEqual(3, $this->pageCount($pdf));
    }

    public function test_the_salary_slip_groups_charges_per_insurance_with_both_rates(): void
    {
        $slip = $this->slip([
            // As the migration writes them: AANP paid by the employee, LAA by the employer.
            'avs_employee' => '265.00', 'avs_employer' => '265.00',
            'ac_employee' => '0.00', 'ac_employer' => '0.00',
            'aanp_employee' => '50.00', 'laa_employer' => '25.00',
            'lpp_employee' => '200.00', 'lpp_employer' => '200.00',
            'family_allowance_employer' => '120.00',
            'total_employee' => '515.00', 'total_employer' => '610.00',
        ], net: '4485.00');
        DeductionRate::query()->create(['organization_id' => $this->org->id, 'name' => 'AVS', 'code' => 'avs_employee', 'rate' => '5.3000', 'type' => 'employee', 'is_active' => true]);

        $html = (new WordModelSalarySlipLayout)->html($slip, $this->org->fresh());

        $this->assertStringContainsString('Décompte salaire', $html);
        $this->assertStringContainsString('Mars 2026', $html);
        $this->assertMatchesRegularExpression('#AVS/AI/APG</td>\s*<td class="num">5.3 %</td>\s*<td class="num">5.3 %</td>\s*<td class="num">265.00</td>#', $html);
        $this->assertMatchesRegularExpression('#LAA</td>\s*<td class="num">0.5 %</td>\s*<td class="num">1 %</td>\s*<td class="num">50.00</td>#', $html);
        $this->assertMatchesRegularExpression('#Alloc. familiales</td>\s*<td class="num">2.4 %</td>\s*<td class="num"></td>\s*<td class="num"></td>#', $html);
        $this->assertStringNotContainsString('>AC<', $html, 'Insurances without amounts are left out');
        $this->assertMatchesRegularExpression('#Total charges</td>\s*<td class="num">12.2 %</td>\s*<td class="num">10.3 %</td>\s*<td class="num">515.00</td>#', $html);
        $this->assertStringContainsString('4&#039;485.00', $html);
        $this->assertStringContainsString('CH93 0076 2011 6238 5295 7', $html);
    }

    public function test_a_configured_code_without_suffix_is_classified_by_its_type_and_rows_add_up(): void
    {
        $slip = $this->slip(['avs_employee' => '265.00', 'ktg' => '30.00', 'total_employee' => '295.00'], net: '4705.00');
        DeductionRate::query()->create(['organization_id' => $this->org->id, 'name' => 'Indemnité maladie', 'code' => 'ktg', 'rate' => '0.6000', 'type' => 'employee', 'is_active' => true]);

        $html = (new WordModelSalarySlipLayout)->html($slip, $this->org->fresh());

        $this->assertMatchesRegularExpression('#Indemnité maladie</td>\s*<td class="num"></td>\s*<td class="num">0.6 %</td>\s*<td class="num">30.00</td>#', $html);
        $this->assertMatchesRegularExpression('#Total charges</td>\s*<td class="num">0 %</td>\s*<td class="num">5.9 %</td>\s*<td class="num">295.00</td>#', $html);
    }

    public function test_a_description_taller_than_a_page_continues_on_the_next_pages(): void
    {
        $invoice = $this->invoice(lines: 1);
        $invoice->lines()->first()->update(['description' => implode("\n", array_map(fn (int $i): string => 'Ligne '.$i, range(1, 150)))]);
        $this->org->update(['pdf_layouts' => [PdfLayouts::INVOICE => 'word-model']]);

        $pdf = app(GenerateQrInvoicePdfAction::class)->execute($invoice->fresh(), $this->org->fresh(), 'fr');

        $this->assertGreaterThanOrEqual(4, $this->pageCount($pdf), '150 lines of 8 pt need three pages, plus the QR-bill page');
    }

    public function test_the_salary_slip_shows_reimbursements_and_source_tax(): void
    {
        $slip = $this->slip(['avs_employee' => '265.00', 'total_employee' => '265.00', 'source_tax' => '300.00'], net: '4600.00', adjustments: [
            'reimbursement_amount' => '165.00',
            'reimbursement_items' => [['id' => 'x', 'date' => '2026-03-02', 'label' => 'Trajet client', 'amount' => '120.00', 'account_code' => '6640']],
        ]);

        $html = (new WordModelSalarySlipLayout)->html($slip->fresh(), $this->org->fresh());

        $this->assertMatchesRegularExpression('#Impôt à la source</td><td></td><td class="num"></td><td class="num">300.00</td>#', $html);
        $this->assertStringContainsString('Trajet client', $html);
        $this->assertMatchesRegularExpression('#Remboursement de frais</td><td></td><td></td><td class="num">45.00</td>#', $html);
    }

    public function test_the_salary_slip_download_uses_the_layout_when_chosen(): void
    {
        $slip = $this->slip(['avs_employee' => '265.00', 'total_employee' => '265.00'], net: '4735.00');
        $this->org->update(['pdf_layouts' => [PdfLayouts::SALARY_SLIP => 'word-model']]);

        $response = $this->actAsOrg()->get(route('payroll.salarySlips.pdf', $slip));

        $response->assertOk();
        $this->assertStringStartsWith('%PDF-', (string) $response->getContent());
        $this->assertStringContainsString('Carlito', (string) $response->getContent());
    }

    public function test_formats(): void
    {
        $this->assertSame("1'234.50", Format::money('1234.5'));
        $this->assertSame('1.5', Format::quantity('1.5000'));
        $this->assertSame('8.1 %', Format::rate('8.1000'));
        $this->assertSame('21 00000 00003 13947 14300 09017', Format::reference('210000000003139471430009017'));
        $this->assertSame('fr', Format::language('rm'));
        // Bank box: free text wins over the dates; a number of days (typed or from the dates) gets the phrase; 0 nothing.
        $this->assertSame(['text' => '30 jours net', 'days' => 0], Format::paymentTerms('30 jours net', 30));
        $this->assertSame(['text' => null, 'days' => 45], Format::paymentTerms(' 45 ', 30));
        $this->assertSame(['text' => null, 'days' => 30], Format::paymentTerms(null, 30));
        $this->assertSame(['text' => null, 'days' => 0], Format::paymentTerms('', -5));
        $this->assertSame(['text' => null, 'days' => 0], Format::paymentTerms('0', 30));
    }

    private function invoice(int $lines): Invoice
    {
        BankAccount::query()->create([
            'organization_id' => $this->org->id, 'name' => 'Bank', 'currency' => 'CHF', 'bank_name' => 'Banque Test',
            'iban' => 'CH9300762011623852957', 'is_default_for_invoicing' => true, 'is_active' => true,
        ]);
        $this->org->update(['legal_name' => 'Exemple SA', 'address' => 'Rue du Test 1', 'postal_code' => '1000', 'city' => 'Lausanne', 'country' => 'CH']);
        $customer = Contact::factory()->create(['organization_id' => $this->org->id, 'name' => 'Client SA', 'address' => 'Rue 2', 'postal_code' => '1003', 'city' => 'Lausanne', 'country' => 'CH']);
        $vat = VatRate::factory()->create(['organization_id' => $this->org->id, 'rate' => 8.10]);
        $invoice = Invoice::query()->create([
            'organization_id' => $this->org->id, 'customer_id' => $customer->id, 'number' => '2026-001', 'status' => 'sent',
            'issue_date' => '2026-06-01', 'due_date' => '2026-07-01', 'subtotal' => (string) (100 * $lines), 'vat_amount' => (string) (8.1 * $lines),
            'total' => (string) (108.1 * $lines), 'currency' => 'CHF', 'notes' => 'Mandat de conseil, juin 2026',
        ]);
        for ($i = 0; $i < $lines; $i++) {
            InvoiceLine::query()->create([
                'invoice_id' => $invoice->id, 'type' => InvoiceLineType::Item, 'description' => 'Prestation '.($i + 1),
                'quantity' => 1, 'unit_price' => '100.00', 'amount' => '100.00', 'vat_rate_id' => $vat->id, 'vat_amount' => '8.10', 'sort_order' => $i,
            ]);
        }

        return $invoice->fresh();
    }

    /**
     * @param  array<string, string>  $deductions
     * @param  array<string, mixed>  $adjustments
     */
    private function slip(array $deductions, string $net, array $adjustments = []): SalarySlip
    {
        $this->org->update(['locale' => 'fr']);
        $employee = Employee::query()->create([
            'organization_id' => $this->org->id, 'first_name' => 'Marie', 'last_name' => 'Exemple', 'entry_date' => '2025-01-01',
            'gross_salary' => '5000.00', 'is_active' => true, 'iban' => 'CH9300762011623852957', 'ahv_number' => '756.0000.0000.02',
        ]);

        return SalarySlip::query()->create([
            'organization_id' => $this->org->id, 'employee_id' => $employee->id, 'period_month' => 3, 'period_year' => 2026,
            'gross_salary' => '5000.00', 'net_salary' => $net, 'deductions' => $deductions,
            'adjustments' => ['base_salary' => '5000.00', 'thirteenth_salary' => '0.00', 'unpaid_leave_days' => 0, 'unpaid_leave_amount' => '0.00', 'reimbursement_amount' => '0.00', ...$adjustments],
        ]);
    }

    private function pageCount(string $pdf): int
    {
        return preg_match_all('#/Type\s*/Page[^s]#', $pdf);
    }
}
