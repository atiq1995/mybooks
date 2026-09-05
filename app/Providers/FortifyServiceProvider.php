<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Laravel\Fortify\Fortify;

/**
 * Authentication, wired to Inertia pages.
 *
 * Fortify supplies the routes and the security-sensitive plumbing —
 * throttling, session regeneration, two-factor challenge flow, password
 * reset tokens. We supply the screens and the credential check.
 */
final class FortifyServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->registerViews();
        $this->registerAuthentication();
        $this->registerRateLimiting();

        Fortify::updateUserProfileInformationUsing(UpdateUserProfileInformation::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
    }

    private function registerViews(): void
    {
        Fortify::loginView(fn (): mixed => Inertia::render('Auth/Login', [
            'status' => session('status'),
            'canResetPassword' => true,
        ]));

        Fortify::requestPasswordResetLinkView(fn (): mixed => Inertia::render('Auth/ForgotPassword', [
            'status' => session('status'),
        ]));

        Fortify::resetPasswordView(fn (Request $request): mixed => Inertia::render('Auth/ResetPassword', [
            'email' => $request->input('email'),
            'token' => $request->route('token'),
        ]));

        Fortify::verifyEmailView(fn (): mixed => Inertia::render('Auth/VerifyEmail', [
            'status' => session('status'),
        ]));

        Fortify::twoFactorChallengeView(fn (): mixed => Inertia::render('Auth/TwoFactorChallenge'));

        Fortify::confirmPasswordView(fn (): mixed => Inertia::render('Auth/ConfirmPassword'));
    }

    private function registerAuthentication(): void
    {
        Fortify::authenticateUsing(function (Request $request): ?User {
            $user = User::query()
                ->where('email', $request->string('email')->trim()->lower()->toString())
                ->first();

            /*
             * Hash the supplied password even when no user was found.
             *
             * Returning early on an unknown email makes the response
             * measurably faster than one for a known email, which turns the
             * login form into an account-enumeration oracle. Doing the work
             * regardless keeps the timing flat.
             */
            $password = $request->string('password')->toString();

            if ($user === null) {
                Hash::check($password, '$2y$12$'.Str::random(53));

                return null;
            }

            if (! Hash::check($password, $user->password)) {
                return null;
            }

            // A suspended account keeps its history and its audit trail but
            // cannot sign in.
            if ($user->isSuspended()) {
                return null;
            }

            $user->forceFill([
                'last_login_at' => now(),
                'last_login_ip' => $request->ip(),
            ])->save();

            return $user;
        });
    }

    private function registerRateLimiting(): void
    {
        /*
         * Throttled per email AND per IP together, so one attacker cannot
         * spray many accounts from one address, and a shared office NAT
         * cannot lock out a whole floor because one person mistyped.
         */
        RateLimiter::for('login', function (Request $request): Limit {
            $key = Str::transliterate(
                $request->string('email')->lower()->toString().'|'.$request->ip()
            );

            return Limit::perMinutes(
                config()->integer('my-books.login.decay_minutes', 15),
                config()->integer('my-books.login.max_attempts', 5),
            )->by($key);
        });

        RateLimiter::for('two-factor', function (Request $request): Limit {
            $loginId = $request->session()->get('login.id');

            return Limit::perMinute(5)->by(is_scalar($loginId) ? (string) $loginId : '');
        });
    }
}
