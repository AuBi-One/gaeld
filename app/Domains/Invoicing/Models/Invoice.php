<?php

namespace App\Domains\Invoicing\Models;

use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Contacts\Models\Contact;
use App\Domains\Contacts\Models\ContactPerson;
use App\Domains\Invoicing\Enums\InvoiceStatus;
use App\Domains\Invoicing\Enums\InvoiceTaxTreatment;
use App\Domains\Invoicing\Enums\InvoiceType;
use App\Domains\Organizations\Models\Organization;
use App\Support\Money;
use App\Support\Traits\Auditable;
use App\Support\Traits\BelongsToOrganization;
use Database\Factories\Domains\Invoicing\Models\InvoiceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Laravel\Scout\Searchable;

/**
 * Sales invoice (or credit note) issued to a customer.
 *
 * Tracks status lifecycle (draft → sent → paid / overdue / cancelled),
 * Swiss QR payment data, reminder history, and links to the accounting
 * journal entry created on posting.
 *
 * @property string $id
 * @property string|null $number
 * @property InvoiceStatus $status
 * @property InvoiceTaxTreatment $tax_treatment
 * @property InvoiceType $type
 * @property string|null $contact_person_id
 * @property array{name: string, attention?: string|null, email: string|null, address: string|null, postal_code: string|null, city: string|null, country: string, country_name?: string|null, salutation?: string|null, vat_number: string|null}|null $customer_snapshot
 * @property Carbon $issue_date
 * @property Carbon|null $due_date
 * @property string $subtotal
 * @property string $vat_amount
 * @property string $total
 * @property string $currency
 * @property string|null $notes
 * @property string|null $introduction
 * @property string|null $payment_terms
 * @property string|null $qr_reference
 * @property string|null $qr_type
 * @property string|null $qr_iban
 * @property string|null $justificatif_path
 * @property int $reminder_count
 * @property Carbon|null $last_reminded_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Contact|null $customer
 * @property-read ContactPerson|null $contactPerson
 * @property-read Organization $organization
 * @property-read Collection<int, InvoiceLine> $lines
 * @property-read Collection<int, InvoicePayment> $payments
 */
class Invoice extends Model
{
    /** @use HasFactory<InvoiceFactory> */
    use Auditable, BelongsToOrganization, HasFactory, HasUuids, Searchable, SoftDeletes;

    /**
     * Always eager-load the customer + organization to avoid N+1 in lists
     * (invoice cards/tables in the dashboard always render the customer name).
     *
     * @var list<string>
     */
    protected $with = ['customer', 'contactPerson'];

    protected $attributes = [
        'tax_treatment' => InvoiceTaxTreatment::Standard->value,
    ];

    protected $fillable = [
        'organization_id',
        'customer_id',
        'contact_person_id',
        'customer_snapshot',
        'journal_entry_id',
        'number',
        'status',
        'tax_treatment',
        'type',
        'related_invoice_id',
        'issue_date',
        'due_date',
        'subtotal',
        'vat_amount',
        'total',
        'currency',
        'notes',
        'introduction',
        'payment_terms',
        'qr_reference',
        'qr_type',
        'qr_iban',
        'justificatif_path',
        'reminder_count',
        'last_reminded_at',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date:Y-m-d',
            'due_date' => 'date:Y-m-d',
            'subtotal' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'status' => InvoiceStatus::class,
            'tax_treatment' => InvoiceTaxTreatment::class,
            'type' => InvoiceType::class,
            'customer_snapshot' => 'array',
            'reminder_count' => 'integer',
            'last_reminded_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    // STATUS_* constants removed — use InvoiceStatus enum: InvoiceStatus::Draft, ::Sent, ::Paid, ::Overdue, ::Cancelled

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Contact, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /** @return BelongsTo<ContactPerson, $this> */
    public function contactPerson(): BelongsTo
    {
        return $this->belongsTo(ContactPerson::class);
    }

    /**
     * The customer block of the invoice documents. A draft shows the customer as it is today,
     * with the invoice's contact person while that is still one of the customer's; finalizing
     * freezes that as the snapshot (FinalizeInvoiceAction), which an issued invoice keeps. The
     * live contact also serves an old invoice without a snapshot.
     *
     * @return array{name: string, attention?: string|null, email: string|null, address: string|null, postal_code: string|null, city: string|null, country: string, country_name?: string|null, salutation?: string|null, vat_number: string|null}|null
     */
    public function customerDetailsForDocument(): ?array
    {
        if ($this->status === InvoiceStatus::Draft && $this->customer !== null) {
            $person = $this->contact_person_id === null
                ? null
                : $this->customer->contactPersons()->whereKey($this->contact_person_id)->first();

            return $this->customer->toInvoiceSnapshot($person);
        }

        return $this->customer_snapshot ?? $this->customer?->toInvoiceSnapshot($this->contactPerson);
    }

    /** @return BelongsTo<JournalEntry, $this> */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /**
     * Every posting of this invoice with its reversal, oldest first: the current journal entry and
     * those of earlier finalizations that were reverted to draft. Those carry the invoice number
     * as reference, or {number}-v2, -v3, ... (InvoiceAccountingService::postToLedger()); a
     * reversal carries "REV-" + the reference of the entry it reverses. An invoice renumbered
     * between two finalizations keeps its earlier, reversed postings under the old number.
     *
     * @return list<array{entry: JournalEntry, reversal: JournalEntry|null}>
     */
    public function postings(): array
    {
        if ($this->journal_entry_id === null) {
            return [];
        }

        $entries = JournalEntry::query()->where('organization_id', $this->organization_id);
        $current = (clone $entries)->find($this->journal_entry_id);
        $number = (string) $this->number;
        $earlier = $number === '' ? collect() : (clone $entries)
            ->where('reference', 'like', addcslashes($number, '%_\\').'%')
            ->whereKeyNot($this->journal_entry_id)
            ->get()
            ->filter(fn (JournalEntry $entry): bool => preg_match('/^'.preg_quote($number, '/').'(-v\d+)?$/', (string) $entry->reference) === 1);

        $originals = $earlier->sortBy('created_at')->values();
        if ($current !== null) {
            $originals->push($current);
        }

        /** @var array<string, JournalEntry> $reversals */
        $reversals = $originals->isEmpty() ? [] : (clone $entries)
            ->whereIn('reference', $originals->map(fn (JournalEntry $entry): string => 'REV-'.$entry->reference)->all())
            ->get()
            ->keyBy('reference')
            ->all();

        $postings = [];
        foreach ($originals as $entry) {
            $postings[] = ['entry' => $entry, 'reversal' => $reversals['REV-'.$entry->reference] ?? null];
        }

        return $postings;
    }

    /** @return HasMany<InvoiceLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class);
    }

    /** @return HasMany<InvoicePayment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(InvoicePayment::class);
    }

    /** @return BelongsTo<self, $this> */
    public function relatedInvoice(): BelongsTo
    {
        return $this->belongsTo(self::class, 'related_invoice_id');
    }

    /** @return HasMany<self, $this> */
    public function creditNotes(): HasMany
    {
        return $this->hasMany(self::class, 'related_invoice_id');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOfType(Builder $query, InvoiceType $type): Builder
    {
        return $query->where('type', $type->value);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [InvoiceStatus::Sent, InvoiceStatus::Overdue]);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeByAmountRange(Builder $query, string $amount, string $tolerance): Builder
    {
        return $query->whereBetween('total', [
            Money::subtract($amount, $tolerance),
            Money::add($amount, $tolerance),
        ]);
    }

    /**
     * Invoices whose open amount (total less what is paid or written off) lies within $tolerance
     * of $amount, so a second instalment finds its invoice too.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeByOpenAmountRange(Builder $query, string $amount, string $tolerance): Builder
    {
        return $query->whereRaw(
            '(invoices.total - (SELECT COALESCE(SUM(invoice_payments.amount), 0) FROM invoice_payments WHERE invoice_payments.invoice_id = invoices.id)) BETWEEN ? AND ?',
            [Money::subtract($amount, $tolerance), Money::add($amount, $tolerance)],
        );
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query->whereIn('status', [InvoiceStatus::Sent, InvoiceStatus::Overdue])
            ->where('due_date', '<', now()->toDateString());
    }

    public function isOverdue(): bool
    {
        return in_array($this->status, [InvoiceStatus::Sent, InvoiceStatus::Overdue], true)
            && $this->due_date !== null
            && $this->due_date->isBefore(now()->startOfDay());
    }

    public function amountPaid(): string
    {
        if (isset($this->attributes['payments_sum_amount'])) {
            return (string) $this->attributes['payments_sum_amount'];
        }

        return (string) $this->payments()->sum('amount');
    }

    public function amountDue(): string
    {
        return Money::subtract((string) $this->total, $this->amountPaid());
    }

    public function isFullyPaid(): bool
    {
        return Money::compare($this->amountDue(), '0') <= 0;
    }

    // ──────────────────────────────────────────────────────────────
    //  Scout
    // ──────────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'number' => $this->number ?? '',
            'status' => $this->status->value,
            'customer_name' => $this->customer?->name ?? '',
            'total' => (float) $this->total,
            'currency' => $this->currency,
        ];
    }

    public function shouldBeSearchable(): bool
    {
        return ! $this->trashed();
    }

    public function recalculate(): void
    {
        $totals = $this->lines()
            ->selectRaw("SUM(CASE WHEN type = 'discount' THEN -amount ELSE amount END) as total_amount")
            ->selectRaw("SUM(CASE WHEN type = 'discount' THEN -vat_amount ELSE vat_amount END) as total_vat")
            ->first();

        $this->subtotal = $totals->total_amount ?? '0';
        $this->vat_amount = $totals->total_vat ?? '0';
        $this->total = Money::add($this->subtotal, $this->vat_amount);
        $this->save();
    }
}
