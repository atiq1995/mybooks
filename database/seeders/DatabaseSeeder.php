<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Organizations\Enums\MembershipStatus;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Local development fixtures.
 *
 * Two organisations and one user who belongs to both, so the organisation
 * switcher has something to switch between and cross-tenant isolation can
 * be exercised from the first session.
 *
 * Refuses to run in production: there is no legitimate reason for a
 * development account with a known password to exist in real books.
 */
final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException(
                'DatabaseSeeder creates a development account with a known password '.
                'and must never run in production.'
            );
        }

        $owner = User::query()->firstOrCreate(
            ['email' => 'owner@my-books.local'],
            [
                'name' => 'Ayesha Khan',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
                'timezone' => 'Asia/Karachi',
            ],
        );

        $alpha = Organization::query()->firstOrCreate(
            ['slug' => 'alpha-traders'],
            [
                'name' => 'Alpha Traders',
                'legal_name' => 'Alpha Traders (Private) Limited',
                'base_currency' => 'PKR',
                'country_code' => 'PK',
                'jurisdiction' => 'PK',
                'fiscal_year_start_month' => 7,
                'timezone' => 'Asia/Karachi',
                'tax_registration_number' => '1234567-8',
                'sales_tax_registration_number' => '12-34-5678-901-23',
                'onboarding_completed_at' => now(),
            ],
        );

        $beta = Organization::query()->firstOrCreate(
            ['slug' => 'beta-foods'],
            [
                'name' => 'Beta Foods',
                'legal_name' => 'Beta Foods (SMC-Private) Limited',
                'base_currency' => 'PKR',
                'country_code' => 'PK',
                'jurisdiction' => 'PK',
                'fiscal_year_start_month' => 7,
                'timezone' => 'Asia/Karachi',
                'onboarding_completed_at' => now(),
            ],
        );

        foreach ([$alpha, $beta] as $organization) {
            OrganizationMembership::query()->firstOrCreate(
                [
                    'organization_id' => $organization->getKey(),
                    'user_id' => $owner->getKey(),
                ],
                [
                    'role' => 'owner',
                    'status' => MembershipStatus::Active,
                    'joined_at' => now(),
                ],
            );
        }

        $owner->forceFill(['last_organization_id' => $alpha->getKey()])->save();

        $this->command?->info('Development account: owner@my-books.local / password');
        $this->command?->info('Organisations: Alpha Traders, Beta Foods');
    }
}
