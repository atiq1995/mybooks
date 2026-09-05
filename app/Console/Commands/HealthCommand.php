<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Dependency health check.
 *
 * Used by the container HEALTHCHECK and by /health. Checks that each backing
 * service is actually reachable, not merely configured — a container that
 * reports healthy while it cannot reach PostgreSQL will happily accept
 * traffic it can do nothing with.
 */
final class HealthCommand extends Command
{
    protected $signature = 'my-books:health {--json : Output machine-readable results}';

    protected $description = 'Verify that PostgreSQL, Redis and object storage are reachable';

    public function handle(): int
    {
        /** @var array<string, array{ok: bool, detail: string}> $results */
        $results = [
            'database' => $this->check(function (): string {
                $row = DB::selectOne('select version() as v');
                $version = is_object($row) && property_exists($row, 'v') ? $row->v : null;

                // "PostgreSQL 17.6 on x86_64-pc-linux-musl, compiled by …" → "PostgreSQL 17.6"
                return is_string($version)
                    ? (string) preg_replace('/\s+\(.*|\s+on\s+.*/', '', $version)
                    : 'connected';
            }),

            'redis' => $this->check(function (): string {
                Redis::connection()->set('my-books:health', (string) time());

                return 'connected';
            }),

            'storage' => $this->check(function (): string {
                // A read is a weaker assertion than a write, but a health
                // check that writes on every probe is a health check that
                // fills a bucket.
                Storage::disk(config()->string('filesystems.default'))->directories();

                return 'reachable';
            }),
        ];

        $healthy = ! in_array(false, array_column($results, 'ok'), strict: true);

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'status' => $healthy ? 'ok' : 'degraded',
                'checks' => $results,
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return $healthy ? self::SUCCESS : self::FAILURE;
        }

        foreach ($results as $name => $result) {
            $this->line(sprintf(
                '  %s  %-10s %s',
                $result['ok'] ? '<fg=green>OK  </>' : '<fg=red>FAIL</>',
                $name,
                $result['detail'],
            ));
        }

        return $healthy ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  callable(): string  $probe
     * @return array{ok: bool, detail: string}
     */
    private function check(callable $probe): array
    {
        try {
            return ['ok' => true, 'detail' => $probe()];
        } catch (Throwable $e) {
            // The message can carry a DSN or credentials, so it is truncated
            // and never logged at this level. SECURITY.md §8.
            return [
                'ok' => false,
                'detail' => mb_substr($e::class.': '.$e->getMessage(), 0, 120),
            ];
        }
    }
}
