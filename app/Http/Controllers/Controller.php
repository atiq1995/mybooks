<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

/**
 * Base controller.
 *
 * Carries AuthorizesRequests so `$this->authorize('accounting.post')` works,
 * which resolves through the same Gate as every other permission check.
 * Laravel's slim skeleton leaves this trait off by default.
 *
 * Controllers stay thin: resolve input, authorize, call ONE Action, respond.
 */
abstract class Controller
{
    use AuthorizesRequests;
}
