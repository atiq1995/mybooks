<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Settings\UpdateAppearanceRequest;
use Illuminate\Http\RedirectResponse;

/**
 * Theme and density preference.
 *
 * Its own endpoint, returning back() with no props, so toggling the theme
 * never re-renders the invoice someone is halfway through editing.
 */
final class AppearanceController extends Controller
{
    public function __invoke(UpdateAppearanceRequest $request): RedirectResponse
    {
        $request->user()?->forceFill($request->validated())->save();

        return back();
    }
}
