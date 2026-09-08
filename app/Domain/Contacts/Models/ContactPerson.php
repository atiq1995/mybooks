<?php

declare(strict_types=1);

namespace App\Domain\Contacts\Models;

use App\Domain\Organizations\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Somebody who works at a contact.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $contact_id
 * @property string $name
 * @property string|null $role
 * @property string|null $email
 * @property string|null $phone
 * @property bool $is_primary
 */
final class ContactPerson extends Model
{
    use BelongsToOrganization;
    use HasUuids;

    protected $fillable = ['name', 'role', 'email', 'phone', 'is_primary'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_primary' => 'boolean'];
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
