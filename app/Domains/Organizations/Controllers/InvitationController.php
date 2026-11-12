<?php

namespace App\Domains\Organizations\Controllers;

use App\Domains\Organizations\Enums\Role;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Models\OrganizationInvitation;
use App\Domains\Organizations\Services\InvitationService;
use App\Domains\Users\DTOs\CreateUserData;
use App\Domains\Users\Models\User;
use App\Domains\Users\Services\UserService;
use App\Http\Controllers\Controller;
use Illuminate\Auth\Events\Registered;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Invite users to an organization and accept/revoke invitations.
 */
class InvitationController extends Controller
{
    public function __construct(
        private readonly InvitationService $invitationService,
    ) {}

    public function store(Request $request, Organization $organization): RedirectResponse
    {
        $this->authorize('manageUsers', $organization);

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', new Enum(Role::class)],
        ]);

        $role = Role::from($validated['role']);

        // Only owners can invite as owner
        if ($role === Role::Owner) {
            $currentUserRole = $organization->users()
                ->where('users.id', $request->user()->id)
                ->first()?->pivot?->role;

            if ($currentUserRole !== 'owner') {
                abort(403, __('app.only_owners_can_assign_owner'));
            }
        }

        $this->invitationService->invite(
            $organization,
            $validated['email'],
            $role,
            $request->user(),
        );

        return redirect()->route('organizations.show', $organization)
            ->with('success', __('app.invitation_sent'));
    }

    /**
     * The link in the invitation mail. Reachable without a session: the
     * invited person may have no account yet, and the token is their only
     * credential. Accepts for the signed-in invitee, asks an existing account
     * to sign in, or sends a new address to the password form.
     */
    public function accept(Request $request, string $token): RedirectResponse
    {
        $invitation = $this->invitationService->findByToken($token);
        $user = $request->user();

        if ($invitation === null || $invitation->isExpired()) {
            $message = __($invitation === null ? 'app.invitation_invalid' : 'app.invitation_expired');

            return redirect()->route($user ? 'dashboard' : 'login')->with('error', $message);
        }

        if ($user === null) {
            if (! $this->accountExists($invitation->email)) {
                return redirect()->route('invitations.register', $token);
            }

            $request->session()->put('url.intended', route('invitations.accept', $token));

            return redirect()->route('login')
                ->with('info', __('app.invitation_sign_in_to_accept', ['email' => $invitation->email]));
        }

        abort_if(
            ! InvitationService::sameAddress($user->email, $invitation->email),
            403,
            __('app.invitation_wrong_account'),
        );

        $organization = $this->invitationService->accept($token);

        $user->switchOrganization($organization);

        return redirect()->route('dashboard')
            ->with('success', __('app.invitation_accepted', ['name' => $organization->name]));
    }

    /**
     * Password form for an invited address without an account. Not gated by
     * FEATURE_SELF_REGISTRATION: it can only create the one address an admin
     * invited.
     */
    public function createRegistration(Request $request, string $token): Response|RedirectResponse
    {
        $invitation = $this->registrableInvitation($request, $token);
        if ($invitation instanceof RedirectResponse) {
            return $invitation;
        }

        // The language of the invitation mail, unless the visitor picked one.
        if ($invitation->organization->locale && ! $request->session()->has('guest_locale')) {
            App::setLocale($invitation->organization->locale);
        }

        return Inertia::render('Auth/InvitationRegister', [
            'token' => $token,
            'email' => $invitation->email,
            'organizationName' => $invitation->organization->name,
        ]);
    }

    /**
     * Create the account for the invited address and join the organization.
     * The address comes from the invitation, never from the request, and
     * counts as verified: the token only reached that mailbox.
     */
    public function storeRegistration(Request $request, string $token, UserService $userService): RedirectResponse
    {
        $invitation = $this->registrableInvitation($request, $token);
        if ($invitation instanceof RedirectResponse) {
            return $invitation;
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $locale = $request->session()->get('guest_locale') ?: ($invitation->organization->locale ?: app()->getLocale());

        // Account and membership together, or neither.
        try {
            [$user, $organization] = DB::transaction(function () use ($userService, $validated, $invitation, $locale, $token): array {
                $user = $userService->create(new CreateUserData(
                    name: $validated['name'],
                    email: $invitation->email,
                    password: $validated['password'],
                    locale: $locale,
                    emailVerifiedAt: now(),
                ));
                // Joining an organization someone else set up: no owner onboarding.
                $user->forceFill(['onboarding_completed_at' => now()])->save();

                return [$user, $this->invitationService->accept($token)];
            });
        } catch (UniqueConstraintViolationException) {
            // A second submit (double click, two tabs) lost the race to the first:
            // the account exists now, so the accept link asks to sign in.
            return redirect()->route('invitations.accept', $token);
        }

        event(new Registered($user));
        Auth::login($user);
        $request->session()->regenerate();
        App::setLocale($user->locale);

        $user->switchOrganization($organization);

        return redirect()->route('dashboard')
            ->with('success', __('app.invitation_accepted', ['name' => $organization->name]));
    }

    /**
     * The pending invitation behind a sign-up token, or where to send the
     * visitor instead (signed in, unknown or expired token, address that
     * already has an account).
     */
    private function registrableInvitation(Request $request, string $token): OrganizationInvitation|RedirectResponse
    {
        if ($request->user()) {
            return redirect()->route('invitations.accept', $token);
        }

        $invitation = $this->invitationService->findByToken($token);

        if ($invitation === null || $invitation->isExpired()) {
            return redirect()->route('login')
                ->with('error', __($invitation === null ? 'app.invitation_invalid' : 'app.invitation_expired'));
        }

        if ($this->accountExists($invitation->email)) {
            return redirect()->route('invitations.accept', $token);
        }

        return $invitation;
    }

    /** Case-insensitive, so an invitation cannot create a second account for a known address. */
    private function accountExists(string $email): bool
    {
        return User::query()->whereRaw('LOWER(email) = ?', [mb_strtolower($email)])->exists();
    }

    public function destroy(Organization $organization, OrganizationInvitation $invitation): RedirectResponse
    {
        $this->authorize('manageUsers', $organization);

        // Ensure invitation belongs to this organization
        if ($invitation->organization_id !== $organization->id) {
            abort(404);
        }

        $this->invitationService->cancel($invitation);

        return redirect()->route('organizations.show', $organization)
            ->with('success', __('app.invitation_cancelled'));
    }

    public function resend(Organization $organization, OrganizationInvitation $invitation): RedirectResponse
    {
        $this->authorize('manageUsers', $organization);

        if ($invitation->organization_id !== $organization->id) {
            abort(404);
        }

        $this->invitationService->resend($invitation);

        return redirect()->route('organizations.show', $organization)
            ->with('success', __('app.invitation_resent'));
    }
}
