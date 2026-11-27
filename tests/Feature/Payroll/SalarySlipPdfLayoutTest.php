<?php

namespace Tests\Feature\Payroll;

use App\Domains\Organizations\Models\Organization;
use App\Domains\Payroll\Contracts\SalarySlipPdfLayoutInterface;
use App\Domains\Payroll\Mail\SalarySlipReadyMail;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Payroll\Models\SalarySlip;
use App\Domains\Payroll\Services\PayrollCalculator;
use App\Support\Pdf\PdfLayouts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Attachment;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\WithAuthenticatedOrganization;

class SalarySlipPdfLayoutTest extends TestCase
{
    use RefreshDatabase, WithAuthenticatedOrganization;

    private SalarySlip $slip;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();

        $employee = Employee::create([
            'organization_id' => $this->org->id,
            'first_name' => 'Max',
            'last_name' => 'Muster',
            'email' => 'max@example.com',
            'entry_date' => '2025-01-01',
            'gross_salary' => '6000.00',
            'is_active' => true,
        ]);
        $this->slip = app(PayrollCalculator::class)->calculate($employee, 3, 2026);
        $this->slip->save();

        app(PdfLayouts::class)->register(new class implements SalarySlipPdfLayoutInterface
        {
            public function key(): string
            {
                return 'test-slip';
            }

            public function label(): string
            {
                return 'Test slip';
            }

            public function renderSalarySlip(SalarySlip $slip, Organization $organization): string
            {
                return '%PDF-test-layout '.$slip->period_month.'/'.$slip->period_year.' '.$organization->id;
            }
        });
    }

    #[Test]
    public function the_download_uses_the_standard_view_until_a_layout_is_chosen(): void
    {
        $response = $this->actAsOrg()->get(route('payroll.salarySlips.pdf', $this->slip));

        $response->assertOk();
        $this->assertStringNotContainsString('%PDF-test-layout', (string) $response->getContent());
    }

    #[Test]
    public function the_download_uses_the_chosen_layout(): void
    {
        $this->org->update(['pdf_layouts' => [PdfLayouts::SALARY_SLIP => 'test-slip']]);

        $response = $this->actAsOrg()->get(route('payroll.salarySlips.pdf', $this->slip));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringContainsString('Salary_2026-03-31_Max_Muster.pdf', (string) $response->headers->get('content-disposition'));
        $this->assertSame('%PDF-test-layout 3/2026 '.$this->org->id, $response->getContent());
    }

    #[Test]
    public function the_e_mail_attachment_uses_the_chosen_layout(): void
    {
        $this->org->update(['pdf_layouts' => [PdfLayouts::SALARY_SLIP => 'test-slip']]);

        $attachments = (new SalarySlipReadyMail($this->slip->fresh()))->attachments();

        $this->assertCount(1, $attachments);
        $this->assertTrue($attachments[0]->isEquivalent(
            Attachment::fromData(fn (): string => '%PDF-test-layout 3/2026 '.$this->org->id, 'Salary_2026-03-31_Max_Muster.pdf')->withMime('application/pdf'),
        ));
    }
}
