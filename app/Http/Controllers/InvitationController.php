<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Access\Enums\Role;
use App\Domain\Organizations\Actions\AcceptInvitation;
use App\Domain\Organizations\Exceptions\InvitationException;
use App\Domain\Organizations\Models\OrganizationMembership;
use App\Http\Middleware\EstablishTenantContext;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Accepting an invitation.
 *
 * Deliberately reachable while signed out: open registration is off, so this
 * is the only route to an account in a default deployment, and the recipient
 * usually has none yet.
 */
final class InvitationController extends Controller
{
    public function __construct(
        private readonly AcceptInvitation $acceptInvitation,
    ) {}

    public function show(Request $request, string $token): Response|RedirectResponse
    {
        try {
            $membership = $this->acceptInvitation->find($token);
        } catch (InvitationException $e) {
            // A bad or spent link is a dead end, not an error page.
            return redirect()->route('login')->with('error', $e->getMessage());
        }

        $organization = $membership->organization;
        $invitedEmail = (string) $membership->invited_email;
        $signedInUser = $request->user();

        return Inertia::render('Invitations/Accept', [
            'token' => $token,
            'organization' => $organization?->name,
            'invitedEmail' => $invitedEmail,
            'role' => Role::tryFrom($membership->role)?->label() ?? $membership->role,
            'roleDescription' => Role::tryFrom($membership->role)?->description(),
            'expiresAt' => $membership->invitation_expires_at?->toDayDateTimeString(),

            /*
             * Which of the three situations the visitor is in decides which
             * form they see: create an account, accept as themselves, or sign
             * out because they are signed in as somebody else.
             */
            'mode' => match (true) {
                $signedInUser === null => $this->hasAccount($invitedEmail) ? 'sign-in' : 'register',
                mb_strtolower($signedInUser->email) === mb_strtolower($invitedEmail) => 'accept',
                default => 'wrong-account',
            },
            'signedInAs' => $signedInUser?->email,
        ]);
    }

    /**
     * Accept as the signed-in user.
     */
    public function accept(Request $request, string $token): RedirectResponse
    {
        $user = $request->user();

        if ($user === null) {
            return redirect()->route('login');
        }

        try {
            $membership = $this->acceptInvitation->accept($token, $user);
        } catch (InvitationException $e) {
            return back()->with('error', $e->getMessage());
        }

        return $this->enter($request, $membership);
    }

    /**
     * Accept by creating the account the invitation was addressed to.
     */
    public function register(Request $request, string $token): RedirectResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        try {
            $membership = $this->acceptInvitation->acceptAndRegister(
                $token,
                $request->string('name')->toString(),
                $request->string('password')->toString(),
            );
        } catch (InvitationException $e) {
            return back()->withErrors(['name' => $e->getMessage()]);
        }

        $user = $membership->user;

        if ($user !== null) {
            Auth::login($user);
            $request->session()->regenerate();
        }

        return $this->enter($request, $membership);
    }

    private function enter(Request $request, OrganizationMembership $membership): RedirectResponse
    {
        // Make the organisation they just joined the active one, so they land
        // inside it rather than on a chooser.
        $request->session()->put(
            EstablishTenantContext::SESSION_KEY,
            $membership->organization_id,
        );

        $name = $membership->organization->name ?? 'your new organisation';

        return redirect()
            ->route('dashboard')
            ->with('success', "Welcome to {$name}.");
    }

    private function hasAccount(string $email): bool
    {
        if ($email === '') {
            return false;
        }

        return User::query()->where('email', $email)->exists();
    }
}
