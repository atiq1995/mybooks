<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Organizations\Enums\MembershipStatus;
use App\Domain\Organizations\Models\OrganizationMembership;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Inertia\Middleware;
use Tighten\Ziggy\Ziggy;

/**
 * Props shared with every Inertia page.
 *
 * Kept deliberately small. Everything here is serialised into every response,
 * including a navigation that only needed one number — so anything expensive
 * or rarely used belongs on the page that needs it, not in here.
 */
final class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function __construct(
        private readonly TenantContext $tenant,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),

            'app' => [
                'name' => config('app.name'),
                'environment' => app()->environment(),
            ],

            'auth' => [
                'user' => $user === null ? null : [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'initials' => $this->initials($user->name),
                    'theme' => $user->theme,
                    'density' => $user->density,
                    'two_factor_enabled' => $user->hasTwoFactorEnabled(),
                    'email_verified' => $user->hasVerifiedEmail(),
                ],
            ],

            'organization' => $this->tenant->organizationOrNull() === null ? null : [
                'id' => $this->tenant->organization()->id,
                'name' => $this->tenant->organization()->name,
                'slug' => $this->tenant->organization()->slug,
                'base_currency' => $this->tenant->organization()->base_currency,
                'locale' => $this->tenant->organization()->locale,
                'date_format' => $this->tenant->organization()->date_format,
                'onboarding_complete' => $this->tenant->organization()->hasCompletedOnboarding(),
            ],

            /*
             * The organisation switcher's list. Lazily evaluated: it is only
             * computed when a page actually asks for it, so an invoice list
             * does not pay for the switcher's query on every keystroke.
             */
            'organizations' => fn (): array => $user === null ? [] : $user->memberships()
                ->where('status', MembershipStatus::Active)
                ->whereHas('organization', fn ($query) => $query->whereNull('archived_at'))
                ->with('organization')
                ->get()
                ->map(function (OrganizationMembership $membership): ?array {
                    $organization = $membership->organization;

                    return $organization === null ? null : [
                        'id' => $organization->id,
                        'name' => $organization->name,
                        'slug' => $organization->slug,
                        'role' => $membership->role,
                    ];
                })
                ->filter()
                ->sortBy('name')
                ->values()
                ->all(),

            /*
             * Flash messages. Read once and cleared, so a message cannot
             * reappear on a back navigation.
             */
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'warning' => fn () => $request->session()->get('warning'),
                'info' => fn () => $request->session()->get('info'),
            ],

            // Named routes for the frontend, so no URL is ever hard-coded.
            'ziggy' => fn (): array => [
                ...(new Ziggy)->toArray(),
                'location' => $request->url(),
            ],
        ];
    }

    /**
     * Version string for Inertia's asset refresh. When the compiled assets
     * change, clients are told to do a full reload rather than running new
     * pages against old JavaScript.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    private function initials(string $name): string
    {
        $words = preg_split('/\s+/', trim($name)) ?: [];
        $letters = array_map(
            static fn (string $word): string => mb_strtoupper(mb_substr($word, 0, 1)),
            array_slice(array_filter($words), 0, 2),
        );

        return implode('', $letters) ?: '?';
    }
}
