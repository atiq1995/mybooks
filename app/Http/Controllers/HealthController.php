<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Console\Commands\HealthCommand;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;

/**
 * Dependency health, for load balancers and uptime monitoring.
 *
 * Unauthenticated, and therefore deliberately detail-free: it reports whether
 * the application can serve traffic, never which dependency failed or why.
 * A probe endpoint that names its internals is reconnaissance.
 *
 * Operators get the detail from `php artisan my-books:health --json`.
 */
final class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $healthy = Artisan::call(HealthCommand::class, ['--quiet' => true]) === 0;

        return response()->json(
            ['status' => $healthy ? 'ok' : 'degraded'],
            $healthy ? 200 : 503,
        );
    }
}
