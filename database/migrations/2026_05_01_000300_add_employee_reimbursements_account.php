<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Give every existing organisation the Employee Reimbursements account.
 *
 * The chart template now includes it, which covers organisations created from
 * here on. Anything created before this migration has a chart without it, and
 * the first reimbursable expense would fail at posting with "no account for
 * role employee_reimbursements" — an error about the chart, raised at the
 * worst possible moment, to somebody who did nothing wrong.
 *
 * Forward-only and idempotent: an organisation that already has the role, or
 * already has an account at 2150, is left alone.
 *
 * @see ACCOUNTING_RULES.md §3
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Directly in SQL rather than through the model.
         *
         * A migration runs as the schema owner with no tenant context, so the
         * global Eloquent scope would filter every organisation out — and the
         * model's own guards are about application traffic, not about a
         * one-off backfill of a structural account.
         */
        /** @var list<object{id: string}> $organizations */
        $organizations = DB::table('organizations')->select('id')->get()->all();

        foreach ($organizations as $organization) {
            $organizationId = $organization->id;

            $exists = DB::table('accounts')
                ->where('organization_id', $organizationId)
                ->where(function (Builder $query): void {
                    $query->where('system_role', 'employee_reimbursements')
                        ->orWhere('code', '2150');
                })
                ->exists();

            if ($exists) {
                continue;
            }

            // Under the same heading as the other liabilities, so the chart
            // does not gain an orphan at the top level.
            $parentId = DB::table('accounts')
                ->where('organization_id', $organizationId)
                ->where('is_header', true)
                ->where('code', '2000')
                ->value('id');

            DB::table('accounts')->insert([
                'id' => (string) Str::uuid7(),
                'organization_id' => $organizationId,
                'code' => '2150',
                'name' => 'Employee Reimbursements',
                'type' => 'liability',
                'subtype' => 'payable',
                'normal_balance' => 'credit',
                'system_role' => 'employee_reimbursements',
                'is_header' => false,
                'is_active' => true,
                'parent_id' => is_string($parentId) ? $parentId : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Only where nothing has been posted to it. An account with history
        // is not removable, and the ledger's own trigger says so.
        $ids = DB::table('accounts')
            ->where('system_role', 'employee_reimbursements')
            ->pluck('id');

        foreach ($ids as $id) {
            $used = DB::table('journal_lines')->where('account_id', $id)->exists();

            if (! $used) {
                DB::table('accounts')->where('id', $id)->delete();
            }
        }
    }
};
