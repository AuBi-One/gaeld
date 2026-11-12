<?php

namespace Tests\Feature\Accounting;

use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Support\SystemReferencePrefixes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Traits\WithAuthenticatedOrganization;

/**
 * Hand-entered references cannot take a prefix Gäld uses for its own
 * entries (a manual REV-VAT-SETTLEMENT-… would unlock a settled VAT period),
 * and a line text cannot exceed its varchar(255) column.
 */
class SystemReferenceTest extends TestCase
{
    use RefreshDatabase, WithAuthenticatedOrganization;

    private Account $bank;

    private Account $revenue;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();
        $this->bank = Account::create(['organization_id' => $this->organization->id, 'code' => '1020', 'name' => 'Bank', 'type' => AccountType::Asset->value]);
        $this->revenue = Account::create(['organization_id' => $this->organization->id, 'code' => '3000', 'name' => 'Revenue', 'type' => AccountType::Revenue->value]);
    }

    /** @return array<string, array{string}> */
    public static function reservedReferences(): array
    {
        return [
            'reversal' => ['REV-VAT-SETTLEMENT-2025-01-01-2025-06-30'],
            'settlement' => ['VAT-SETTLEMENT-2025-07-01-2025-12-31'],
            'opening' => ['OPENING-2026'],
            'disposal' => ['DISP-123'],
            'lower case' => ['rev-JE-1'],
        ];
    }

    #[DataProvider('reservedReferences')]
    public function test_a_manual_entry_cannot_take_a_system_prefix(string $reference): void
    {
        $this->actAsOrg()
            ->post(route('accounting.journal-entries.store'), $this->payload($reference))
            ->assertSessionHasErrors('reference');

        $this->assertSame(0, JournalEntry::query()->count());
    }

    public function test_an_ordinary_reference_is_accepted(): void
    {
        $this->actAsOrg()
            ->post(route('accounting.journal-entries.store'), $this->payload('JE-2026-001'))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, JournalEntry::query()->where('reference', 'JE-2026-001')->count());
    }

    public function test_a_draft_keeps_the_system_reference_it_already_has(): void
    {
        $draft = JournalEntry::create([
            'organization_id' => $this->organization->id,
            'date' => '2026-03-01',
            'reference' => 'REV-JE-1',
            'description' => 'reversal draft',
            'is_posted' => false,
        ]);
        $draft->lines()->create(['account_id' => $this->revenue->id, 'debit' => '10.00', 'credit' => '0']);
        $draft->lines()->create(['account_id' => $this->bank->id, 'debit' => '0', 'credit' => '10.00']);

        $this->actAsOrg()
            ->put(route('accounting.journal-entries.update', $draft), $this->payload('REV-JE-1'))
            ->assertSessionHasNoErrors();

        $this->actAsOrg()
            ->put(route('accounting.journal-entries.update', $draft), $this->payload('REV-JE-2'))
            ->assertSessionHasErrors('reference');
    }

    public function test_a_plugin_prefix_is_reserved_too(): void
    {
        app(SystemReferencePrefixes::class)->extend('PLUG-');

        $this->actAsOrg()
            ->post(route('accounting.journal-entries.store'), $this->payload('PLUG-1'))
            ->assertSessionHasErrors('reference');
    }

    public function test_a_line_text_longer_than_its_column_is_refused(): void
    {
        $payload = $this->payload('JE-LONG');
        $payload['lines'][0]['description'] = str_repeat('b', 256);

        $this->actAsOrg()
            ->post(route('accounting.journal-entries.store'), $payload)
            ->assertSessionHasErrors('lines.0.description');

        $payload['lines'][0]['description'] = str_repeat('b', 255);
        $this->actAsOrg()
            ->post(route('accounting.journal-entries.store'), $payload)
            ->assertSessionHasNoErrors();
    }

    /** @return array<string, mixed> */
    private function payload(string $reference): array
    {
        return [
            'date' => '2026-03-01',
            'reference' => $reference,
            'description' => 'manual',
            'lines' => [
                ['account_id' => $this->bank->id, 'debit' => '10.00', 'credit' => '0', 'description' => 'in'],
                ['account_id' => $this->revenue->id, 'debit' => '0', 'credit' => '10.00', 'description' => 'out'],
            ],
        ];
    }
}
