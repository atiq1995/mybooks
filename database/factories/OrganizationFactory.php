<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Organizations\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Organization>
 */
final class OrganizationFactory extends Factory
{
    protected $model = Organization::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = $this->faker->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'legal_name' => $name.' (Private) Limited',
            'base_currency' => 'PKR',
            'country_code' => 'PK',
            'jurisdiction' => 'PK',
            // Pakistan's tax year runs July to June.
            'fiscal_year_start_month' => 7,
            'rounding_mode' => 'HALF_UP',
            'timezone' => 'Asia/Karachi',
            'locale' => 'en',
            'date_format' => 'd M Y',
            'onboarding_completed_at' => now(),
        ];
    }

    /**
     * Re-read after creation so database-defaulted columns (archived_at and
     * friends) are present on the instance. See UserFactory::configure().
     */
    public function configure(): static
    {
        return $this->afterCreating(fn (Organization $organization) => $organization->refresh());
    }

    /** An organisation still in the onboarding wizard. */
    public function onboarding(): static
    {
        return $this->state(fn (): array => ['onboarding_completed_at' => null]);
    }

    public function archived(): static
    {
        return $this->state(fn (): array => ['archived_at' => now()]);
    }

    /** For multi-currency tests: a second base currency. */
    public function inCurrency(string $currency, string $country = 'AE'): static
    {
        return $this->state(fn (): array => [
            'base_currency' => $currency,
            'country_code' => $country,
        ]);
    }
}
