<?php

declare(strict_types=1);

namespace App\Domain\Banking\Models;

use App\Domain\Banking\Enums\StatementFormat;
use App\Domain\Organizations\Concerns\BelongsToOrganization;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One statement file, as it was imported.
 *
 * Recorded even when every row in it was already known. "I imported June
 * twice and nothing appeared" is a question people ask, and an import history
 * showing 42 rows read, 0 imported, 42 already present is the answer.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $bank_account_id
 * @property StatementFormat $format
 * @property string $filename
 * @property string $file_hash
 * @property Carbon|null $statement_start
 * @property Carbon|null $statement_end
 * @property int $rows_total
 * @property int $rows_imported
 * @property int $rows_duplicate
 * @property string|null $imported_by
 * @property Carbon $created_at
 */
final class BankStatementImport extends Model
{
    use BelongsToOrganization;
    use HasUuids;

    protected $fillable = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'format' => StatementFormat::class,
            'statement_start' => 'date',
            'statement_end' => 'date',
            'rows_total' => 'integer',
            'rows_imported' => 'integer',
            'rows_duplicate' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<BankAccount, $this>
     */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    /**
     * @return HasMany<BankStatementLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(BankStatementLine::class, 'import_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function importer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }

    /** Rows the file contained that we could not read at all. */
    public function rowsRejected(): int
    {
        return $this->rows_total - $this->rows_imported - $this->rows_duplicate;
    }
}
