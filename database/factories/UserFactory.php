<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
final class UserFactory extends Factory
{
    protected $model = User::class;

    /**
     * A single hash, computed once for the whole suite.
     *
     * argon2id is intentionally slow. Hashing a fresh password per factory
     * call turns a hundred-user fixture into several seconds of pure key
     * derivation, which is time the test suite spends proving nothing.
     */
    private static ?string $passwordHash = null;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->name(),
            'email' => $this->faker->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => self::$passwordHash ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'locale' => 'en',
            'timezone' => 'Asia/Karachi',
            'theme' => 'system',
            'density' => 'compact',
        ];
    }

    /**
     * Re-read the row after creation.
     *
     * A freshly created model only carries the attributes the factory set;
     * columns the database defaulted (suspended_at, last_organization_id,
     * two_factor_confirmed_at) are absent from the in-memory instance. With
     * Model::preventAccessingMissingAttributes() on — as it is outside
     * production — touching one of them throws. Refreshing gives tests the
     * same fully-hydrated model a real request loads.
     */
    public function configure(): static
    {
        return $this->afterCreating(fn (User $user) => $user->refresh());
    }

    public function unverified(): static
    {
        return $this->state(fn (): array => ['email_verified_at' => null]);
    }

    public function suspended(): static
    {
        return $this->state(fn (): array => ['suspended_at' => now()]);
    }

    public function withTwoFactor(): static
    {
        return $this->state(fn (): array => [
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
            'two_factor_recovery_codes' => ['code-one', 'code-two'],
            'two_factor_confirmed_at' => now(),
        ]);
    }
}
