<?php

namespace App\Domains\Payroll\Controllers;

use App\Domains\Payroll\Actions\GenerateSalaryCertificateAction;
use App\Domains\Payroll\Exceptions\IncompleteSalaryCertificateException;
use App\Domains\Payroll\Models\Employee;
use App\Http\Controllers\Controller;
use App\Support\PdfExportService;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

final class SalaryCertificateController extends Controller
{
    public function download(
        Employee $employee,
        int $year,
        GenerateSalaryCertificateAction $action,
        PdfExportService $pdf,
    ): HttpResponse|RedirectResponse {
        $this->authorize('view', $employee);

        try {
            $certificate = $action->execute($employee, $year);
        } catch (IncompleteSalaryCertificateException $e) {
            return redirect()->route('payroll.employees.show', $employee)->with('error', $e->getMessage());
        }

        return $pdf->download(
            'exports.salary-certificate',
            ['certificate' => $certificate, 'organization' => $employee->organization],
            "salary-certificate-{$employee->last_name}-{$year}.pdf",
        );
    }
}
