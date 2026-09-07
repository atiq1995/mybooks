<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Theme and density, as a settings screen.
 *
 * The top bar already has a theme toggle for the common case; this page exists
 * for density, which is a considered choice rather than something to flip on a
 * whim. Compact fits roughly a third more rows on screen, which matters when
 * scanning a long ledger; comfortable is easier for occasional use.
 *
 * Both save through the same tiny endpoint the toggle uses, so a preference
 * change never re-renders the page underneath it.
 */
final class AppearancePreferencesController extends Controller
{
    public function show(Request $request): Response
    {
        $user = $request->user();
        abort_if($user === null, 403);

        return Inertia::render('Settings/Appearance', [
            'appearance' => [
                'theme' => $user->theme,
                'density' => $user->density,
            ],
        ]);
    }
}
