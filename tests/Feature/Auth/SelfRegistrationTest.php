<?php

namespace Tests\Feature\Auth;

use App\Domains\Organizations\Models\Organization;
use App\Domains\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * FEATURE_SELF_REGISTRATION closes public sign-up server-side while existing
 * logins and the first-run /setup keep working; /setup itself closes for good
 * once an organization exists, even a deleted one.
 *
 * Ported from Ideall-Engineering/Gaeld 80534d65 (config part).
 */
class SelfRegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['features.saas' => false]);
    }

    private function createOrganization(): Organization
    {
        return Organization::create(['name' => 'Test Org', 'currency' => 'CHF']);
    }

    public function test_self_registration_is_on_by_default(): void
    {
        $this->assertTrue(config('features.self_registration'));

        $this->createOrganization();

        $this->get('/register')->assertOk();
        $this->get('/login')->assertInertia(fn ($page) => $page->where('features.self_registration', true));
    }

    public function test_registration_form_is_closed_when_self_registration_is_off(): void
    {
        $this->createOrganization();
        config(['features.self_registration' => false]);

        $this->get('/register')->assertForbidden();
        $this->get('/login')->assertOk()->assertInertia(fn ($page) => $page->where('features.self_registration', false));
    }

    public function test_registration_post_is_refused_server_side_when_off(): void
    {
        $this->createOrganization();
        config(['features.self_registration' => false]);

        $this->post('/register', [
            'name' => 'Intruder',
            'email' => 'intruder@example.com',
            'password' => 'Correct-Horse-9-Battery',
            'password_confirmation' => 'Correct-Horse-9-Battery',
            'accepted_privacy' => true,
        ])->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'intruder@example.com']);
        $this->assertGuest();
    }

    public function test_existing_user_can_still_log_in_when_off(): void
    {
        $this->createOrganization();
        config(['features.self_registration' => false]);

        $user = User::factory()->create(['password' => 'password123']);

        $this->post('/login', ['email' => $user->email, 'password' => 'password123'])
            ->assertRedirect();

        $this->assertAuthenticatedAs($user);
    }

    public function test_setup_wizard_stays_available_on_an_empty_installation(): void
    {
        config(['features.self_registration' => false]);

        $this->get('/setup')->assertOk();
    }

    public function test_setup_wizard_is_closed_once_an_organization_exists(): void
    {
        $this->createOrganization();

        $this->get('/setup')->assertRedirect(route('dashboard'));
        $this->post('/setup', $this->setupPayload())->assertRedirect(route('dashboard'));

        $this->assertDatabaseMissing('users', ['email' => 'second-owner@example.com']);
    }

    public function test_setup_wizard_stays_closed_after_the_last_organization_was_deleted(): void
    {
        $this->createOrganization()->delete();
        $this->assertSame(0, Organization::count());

        $this->get('/setup')->assertRedirect(route('dashboard'));
        $this->post('/setup', $this->setupPayload())->assertRedirect(route('dashboard'));

        $this->assertDatabaseMissing('users', ['email' => 'second-owner@example.com']);
        $this->assertGuest();
    }

    /** @return array<string, string> */
    private function setupPayload(): array
    {
        return [
            'user_name' => 'Second Owner',
            'user_email' => 'second-owner@example.com',
            'user_password' => 'password123',
            'user_password_confirmation' => 'password123',
            'org_name' => 'Takeover Org',
            'currency' => 'CHF',
            'locale' => 'en',
        ];
    }
}
