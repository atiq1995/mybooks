<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Organizations\Models\Organization;
use App\Domain\Sales\Actions\GenerateRecurringInvoices;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Generate the invoices that recurring templates are due to produce.
 *
 * Runs nightly, per organisation, inside that organisation's tenant context —
 * a scheduled command has none of its own, and the second isolation layer
 * would return zero rows without it.
 *
 * Every organisation is attempted even when one fails. A chart missing its
 * revenue account in one set of books must not stop another business being
 * billed, and the summary at the end says which ones had trouble.
 *
 * Safe to run twice: the unique index on (template, scheduled date) is what
 * makes an occurrence generate once, whatever the scheduler does.
 *
 * @see GenerateRecurringInvoices
 */
final class GenerateRecurringInvoicesCommand extends Command
{
    protected $signature = 'my-books:generate-recurring-invoices
                            {--organization= : Only this organisation, by slug}
                            {--on= : Treat this date as today, for catching up}
                            {--json : Machine-readable output}';

    protected $description = 'Generate invoices from recurring templates that are due';

    public function handle(TenantContext $tenant, GenerateRecurringInvoices $generate): int
    {
        $on = is_string($date = $this->option('on')) && $date !== ''
            ? Carbon::parse($date)
            : Carbon::now();

        $organizations = $tenant->runUnscoped(function (): array {
            $query = Organization::query()->whereNull('archived_at')->orderBy('name');

            if (is_string($slug = $this->option('organization')) && $slug !== '') {
                $query->where('slug', $slug);
            }

            return $query->get()->all();
        });

        /** @var list<array{organization: string, generated: int, failed: int, error?: string}> $results */
        $results = [];

        $generated = 0;
        $failed = 0;

        foreach ($organizations as $organization) {
            try {
                /** @var array{generated: int, failed: int, templates: int} $result */
                $result = $tenant->runAs(
                    $organization,
                    fn (): array => $generate->handle($on),
                );

                $generated += $result['generated'];
                $failed += $result['failed'];

                if ($result['templates'] > 0) {
                    $results[] = [
                        'organization' => $organization->slug,
                        'generated' => $result['generated'],
                        'failed' => $result['failed'],
                    ];
                }
            } catch (\Throwable $exception) {
                /*
                 * One organisation's problem is not another's. A chart
                 * missing an account here must not stop a different business
                 * being billed tonight.
                 */
                $failed++;

                $results[] = [
                    'organization' => $organization->slug,
                    'generated' => 0,
                    'failed' => 1,
                    'error' => $exception->getMessage(),
                ];
            }
        }

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'as_of' => $on->toDateString(),
                'generated' => $generated,
                'failed' => $failed,
                'organizations' => $results,
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } else {
            foreach ($results as $result) {
                $this->line(sprintf(
                    '%s: %d generated, %d failed%s',
                    $result['organization'],
                    $result['generated'],
                    $result['failed'],
                    isset($result['error']) ? " — {$result['error']}" : '',
                ));
            }

            $this->info(sprintf(
                '%d invoice%s generated as at %s.',
                $generated,
                $generated === 1 ? '' : 's',
                $on->toDateString(),
            ));

            if ($failed > 0) {
                $this->warn(sprintf(
                    '%d occurrence%s could not be generated. Each is recorded against its '.
                    'template with the reason — fix the cause and run again, and the '.
                    'schedule picks up where it stopped.',
                    $failed,
                    $failed === 1 ? '' : 's',
                ));
            }
        }

        /*
         * Non-zero on any failure, so a scheduler notices. A recurring
         * invoice that silently did not go out is money not asked for, and
         * nobody finds it by reading logs.
         */
        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
