<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Domain\Access\AccessControl;
use App\Domain\Access\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Password and two-factor authentication.
 *
 * The endpoints that actually change anything belong to Laravel Fortify —
 * enabling, confirming and disabling two-factor, regenerating recovery codes,
 * updating a password. This controller only renders the screen, because those
 * flows carry security-sensitive plumbing (password confirmation, recovery
 * code invalidation, session regeneration) that is not worth reimplementing.
 */
final class SecurityController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AccessControl $access,
    ) {}

    public function show(Request $request): Response
    {
        $user = $request->user();
        abort_if($user === null, 403);

        return Inertia::render('Settings/Security', [
            'twoFactor' => [
                'enabled' => $user->hasTwoFactorEnabled(),
                'confirmed_at' => $user->two_factor_confirmed_at?->toDayDateTimeString(),
                /*
                 * Whether THIS person's role depends on a second factor, so
                 * the screen can say why it matters to them rather than
                 * nagging everybody equally. Sixteen permissions require it:
                 * posting to the ledger, moving money, changing who has access.
                 */
                'required_for_your_role' => $this->requiredForRole($user),
                'enforced' => config()->boolean('my-books.security.enforce_two_factor'),
            ],
            'passkeys' => $this->passkeys($user),
            'session' => [
                'last_login_at' => $user->last_login_at?->toDayDateTimeString(),
                'last_login_ip' => $user->last_login_ip,
            ],
        ]);
    }

    /**
     * Does anything this user can do in the active organisation demand a
     * second factor?
     */
    private function requiredForRole(User $user): bool
    {
        $organization = $this->tenant->organizationOrNull();

        if ($organization === null) {
            return false;
        }

        foreach (Permission::requiringTwoFactor() as $permission) {
            if ($this->access->allows($user, $organization, $permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Registered passkeys.
     *
     * Read straight from the table rather than through a relation: the
     * passkeys package owns that model, and reaching for it dynamically buys
     * a `mixed` return type in exchange for nothing. Registration UI is not
     * built yet, but Fortify's passkey endpoints are live, so any that exist
     * should be listed.
     *
     * @return list<array{id: string, name: string, last_used_at: string|null}>
     */
    private function passkeys(User $user): array
    {
        $rows = DB::table('passkeys')
            ->where('user_id', $user->getKey())
            ->orderBy('created_at')
            ->get(['id', 'name', 'last_used_at']);

        $passkeys = [];

        foreach ($rows as $row) {
            $passkeys[] = [
                'id' => is_scalar($row->id) ? (string) $row->id : '',
                'name' => is_scalar($row->name) ? (string) $row->name : 'Passkey',
                'last_used_at' => is_string($row->last_used_at) ? $row->last_used_at : null,
            ];
        }

        return $passkeys;
    }
}
