<?php

namespace Tests\Feature\Accounting;

use App\Domains\Accounting\DTOs\JournalEntryData;
use App\Domains\Accounting\DTOs\JournalLineData;
use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Services\LedgerService;
use App\Domains\Organizations\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Posting flushes the cached ledger data when the transaction commits, not
 * inside it: a report read before the commit (that still sees the old
 * balances) must not stay cached afterwards.
 */
class LedgerCacheFlushTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private LedgerService $ledger;

    /** @var array<string, Account> */
    private array $accounts = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledger = app(LedgerService::class);
        $this->org = Organization::create(['name' => 'Cache AG', 'currency' => 'CHF']);
        foreach (['bank' => ['1020', AccountType::Asset], 'revenue' => ['3200', AccountType::Revenue]] as $key => [$code, $type]) {
            $this->accounts[$key] = Account::create(['organization_id' => $this->org->id, 'code' => $code, 'name' => $key, 'type' => $type->value]);
        }
    }

    private function entry(string $reference): JournalEntryData
    {
        return new JournalEntryData(date: now()->toDateString(), reference: $reference, description: 'Sale', lines: [
            new JournalLineData(accountId: $this->accounts['bank']->id, debit: '100.00', credit: '0.00'),
            new JournalLineData(accountId: $this->accounts['revenue']->id, debit: '0.00', credit: '100.00'),
        ]);
    }

    /** What a report read inside the transaction would cache: the balances before the commit. */
    private function cacheStaleReport(): void
    {
        Cache::tags(["org:{$this->org->id}:reports"])->put('trial_balance', 'pre-commit balances', 1800);
    }

    private function cachedReport(): mixed
    {
        return Cache::tags(["org:{$this->org->id}:reports"])->get('trial_balance');
    }

    public function test_posting_an_entry_flushes_the_cache_after_the_commit(): void
    {
        DB::transaction(function (): void {
            $this->ledger->postEntry($this->org->id, $this->entry('CACHE-1'));
            $this->cacheStaleReport();
        });

        $this->assertNull($this->cachedReport());
    }

    public function test_posting_a_draft_flushes_the_cache_after_the_commit(): void
    {
        $draft = $this->ledger->createDraft($this->org->id, $this->entry('CACHE-2'));

        DB::transaction(function () use ($draft): void {
            $this->ledger->postDraft($draft);
            $this->cacheStaleReport();
        });

        $this->assertNull($this->cachedReport());
    }

    public function test_a_rolled_back_posting_leaves_the_cache_alone(): void
    {
        $this->cacheStaleReport();

        try {
            DB::transaction(function (): void {
                $this->ledger->postEntry($this->org->id, $this->entry('CACHE-3'));
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame('pre-commit balances', $this->cachedReport());
    }

    public function test_without_an_open_transaction_the_cache_is_flushed_at_once(): void
    {
        $this->cacheStaleReport();

        $this->ledger->postEntry($this->org->id, $this->entry('CACHE-4'));

        $this->assertNull($this->cachedReport());
    }
}
