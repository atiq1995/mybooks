<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Domain\Organizations\Support\OrganizationOptions;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Personal profile.
 *
 * These settings follow the person between organisations — unlike the
 * organisation's own locale and timezone, which govern its documents.
 * Updates go through Fortify's profile-information endpoint.
 */
final class ProfileController extends Controller
{
    public function show(Request $request): Response
    {
        $user = $request->user();
        abort_if($user === null, 403);

        return Inertia::render('Settings/Profile', [
            'profile' => [
                'name' => $user->name,
                'email' => $user->email,
                'email_verified' => $user->hasVerifiedEmail(),
                'timezone' => $user->timezone,
                'locale' => $user->locale,
                'created_at' => $user->created_at?->toFormattedDateString(),
            ],
            'options' => [
                'timezones' => OrganizationOptions::timezones(),
            ],
        ]);
    }
}
