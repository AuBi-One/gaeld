<?php

namespace App\Console\Commands;

use App\Domains\Invoicing\Actions\StoreInvoicePdfAction;
use App\Domains\Invoicing\Enums\InvoiceStatus;
use App\Domains\Invoicing\Enums\InvoiceType;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Organizations\Services\CurrentOrganization;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

/**
 * Stores the PDF of finalised invoices that have no justificatif yet (finalised before the PDF
 * was stored on finalising, or whose PDF could not be rendered then). Only sent, overdue and
 * paid invoices; credit notes have no PDF.
 *
 * Imported invoices (e.g. migrated from another system) carry no marker of their own: they
 * look like invoices finalised in Gäld. So one of --invoice or --since is required, to target
 * invoices finalised in Gäld only; --since takes invoices both created and issued since then.
 * Prints counts and invoice numbers only.
 */
class StoreInvoicePdfsCommand extends Command
{
    protected $signature = 'invoices:store-pdf
        {--invoice= : One invoice (id, or number with --organization)}
        {--since= : Invoices created and issued on or after this date (Y-m-d)}
        {--organization= : Limit to one organization (id)}
        {--dry-run : List the invoice numbers without storing anything}';

    protected $description = 'Store the PDF of finalised invoices without a justificatif as their justificatif';

    public function handle(StoreInvoicePdfAction $storePdf, CurrentOrganization $currentOrg): int
    {
        $invoiceOption = $this->option('invoice');
        $sinceOption = $this->option('since');
        if (! $invoiceOption && ! $sinceOption) {
            $this->error('Give --invoice or --since: imported invoices cannot be told apart from invoices finalised in Gäld.');

            return self::INVALID;
        }
        if ($invoiceOption && ! Str::isUuid($invoiceOption) && ! $this->option('organization')) {
            $this->error('An invoice number needs --organization: numbers are unique per organization only.');

            return self::INVALID;
        }
        $since = null;
        if ($sinceOption) {
            try {
                $since = Carbon::createFromFormat('Y-m-d', $sinceOption)?->startOfDay();
            } catch (Throwable) {
            }
            if ($since === null || $since->format('Y-m-d') !== $sinceOption) {
                $this->error('--since must be a date as Y-m-d.');

                return self::INVALID;
            }
        }

        $invoices = Invoice::query()
            ->withoutGlobalScope('organization')
            ->when($this->option('organization'), fn ($query, $organizationId) => $query->where('organization_id', $organizationId))
            ->when($invoiceOption, fn ($query, $value) => Str::isUuid($value) ? $query->whereKey($value) : $query->where('number', $value))
            // Both: an imported invoice is created at the import, but issued before it.
            ->when($since, fn ($query, Carbon $date) => $query->where('created_at', '>=', $date)->whereDate('issue_date', '>=', $date->toDateString()))
            ->whereIn('status', [InvoiceStatus::Sent, InvoiceStatus::Overdue, InvoiceStatus::Paid])
            ->where('type', '!=', InvoiceType::CreditNote)
            ->whereNotNull('journal_entry_id')
            ->whereNull('justificatif_path')
            ->orderBy('created_at')
            ->get(['id', 'number']);

        $this->info("Invoices without a justificatif: {$invoices->count()}");
        if ($this->option('dry-run')) {
            foreach ($invoices as $invoice) {
                $this->line((string) ($invoice->number ?? $invoice->id));
            }

            return self::SUCCESS;
        }
        if ($invoices->isEmpty()) {
            return self::SUCCESS;
        }

        $counts = [StoreInvoicePdfAction::STORED => 0, StoreInvoicePdfAction::SKIPPED => 0, StoreInvoicePdfAction::FAILED => 0];
        foreach ($invoices as $row) {
            // One by one: a fresh model per invoice (no lazy loading across a collection).
            $invoice = Invoice::query()->withoutGlobalScope('organization')->find($row->id);
            if ($invoice !== null) {
                $currentOrg->set($invoice->organization); // as in a request: layouts and scoped queries see the invoice's organisation
                $counts[$storePdf->execute($invoice)]++;
            }
        }

        $this->info("Stored: {$counts[StoreInvoicePdfAction::STORED]}, skipped: {$counts[StoreInvoicePdfAction::SKIPPED]}, failed: {$counts[StoreInvoicePdfAction::FAILED]} (see the log)");

        return $counts[StoreInvoicePdfAction::FAILED] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
