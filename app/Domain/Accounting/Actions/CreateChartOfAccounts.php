<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Services\ChartOfAccountsTemplate;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Organizations\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Builds an organisation's opening chart of accounts.
 *
 * Idempotent: running it again adds only what is missing. An organisation that
 * already has accounts keeps them, because re-running this must never
 * duplicate a control account — two accounts both claiming to be "the" AR
 * account is unresolvable, and a unique index refuses it anyway.
 */
final readonly class CreateChartOfAccounts
{
    public function __construct(
        private AuditRecorder $audit,
    ) {}

    /**
     * @return int how many accounts were created
     */
    public function handle(Organization $organization, ?User $actor = null): int
    {
        $template = ChartOfAccountsTemplate::forJurisdiction($organization->jurisdiction);

        return DB::transaction(function () use ($template, $organization, $actor): int {
            $existing = Account::query()->pluck('code')->all();
            $created = 0;

            // Headers first, so a child can point at its parent in one pass.
            $parents = [];

            foreach ($template as $definition) {
                if (in_array($definition['code'], $existing, strict: true)) {
                    continue;
                }

                $type = $definition['type'];

                $account = new Account;

                $account->forceFill([
                    'id' => (string) Str::uuid7(),
                    'organization_id' => $organization->getKey(),
                    'code' => $definition['code'],
                    'name' => $definition['name'],
                    'type' => $type,
                    'subtype' => $definition['subtype'],
                    // An explicit normal balance marks a contra account; the
                    // type's own is the default.
                    'normal_balance' => $definition['normal_balance'] ?? $type->normalBalance(),
                    'system_role' => $definition['system_role'],
                    'is_header' => $definition['is_header'],
                    'is_active' => true,
                    // Headings group; the parent is whichever heading shares
                    // this account's thousands digit.
                    'parent_id' => $definition['is_header']
                        ? null
                        : ($parents[$definition['code'][0]] ?? null),
                ])->save();

                if ($definition['is_header']) {
                    $parents[$definition['code'][0]] = $account->getKey();
                }

                $created++;
            }

            if ($created > 0) {
                $this->audit->record(
                    action: 'accounting.chart_created',
                    description: "Created {$created} accounts for {$organization->jurisdiction}",
                    new: ['accounts_created' => $created, 'jurisdiction' => $organization->jurisdiction],
                    actor: $actor,
                );
            }

            return $created;
        });
    }
}
