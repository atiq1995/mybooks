<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use App\Domain\Audit\Models\AuditLog;
use App\Models\User;
use App\Support\Money\MoneyCast;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Writes the audit trail.
 *
 * Deliberately performs a plain insert and nothing else: no queue, no event,
 * no deferred write. It is called from inside the caller's transaction so the
 * audit row commits with the change it describes, or not at all. An audit
 * trail that can be committed separately is an audit trail that can be lost,
 * and the moments it would be lost are exactly the interesting ones.
 *
 * Rows are append-only — enforced by a database trigger and again by the model.
 *
 * @see AuditLog
 * @see SECURITY.md section 6
 */
final class AuditRecorder
{
    /**
     * Attribute names whose VALUES must never reach the audit trail.
     *
     * The fact that a password changed is worth recording. The password is
     * not. Matched case-insensitively as a substring, so `password`
     * also covers `password_confirmation` and `current_password`.
     *
     * @var list<string>
     */
    private const array REDACTED = [
        'password',
        'token',
        'secret',
        'api_key',
        'authorization',
        'two_factor',
        'recovery_code',
        'card',
        'cvv',
        'remember_token',
    ];

    /**
     * Record an event.
     *
     * @param  string  $action  dotted, past tense: 'invoice.posted', 'role.changed'
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    public function record(
        string $action,
        ?Model $subject = null,
        ?string $description = null,
        ?array $old = null,
        ?array $new = null,
        ?Money $amount = null,
        ?User $actor = null,
    ): AuditLog {
        $actor ??= Auth::user();

        $attributes = [
            'action' => $action,
            'description' => $description,
            'old_values' => $old === null ? null : $this->redact($old),
            'new_values' => $new === null ? null : $this->redact($new),
            'actor_type' => $this->actorType($actor),
            'channel' => $this->channel(),
        ];

        if ($actor instanceof User) {
            // Name and email are denormalised on purpose: if the user is later
            // removed, the trail must still say who did this.
            $attributes['user_id'] = $actor->getKey();
            $attributes['actor_name'] = $actor->name;
            $attributes['actor_email'] = $actor->email;
        }

        if ($subject instanceof Model) {
            $attributes['auditable_type'] = $subject::class;
            $attributes['auditable_id'] = $subject->getKey();
        }

        if ($amount instanceof Money) {
            // Stored alongside so a reviewer can ask "everything over a
            // million" without joining out to each document type.
            //
            // Normalised to the column's scale so the in-memory model and the
            // persisted row agree — otherwise a freshly created record reads
            // back "1.50" where the database holds "1.5000".
            $attributes['amount'] = (string) $amount->getAmount()->toScale(MoneyCast::SCALE);
            $attributes['currency'] = $amount->getCurrency()->getCurrencyCode();
        }

        if (! $this->runningInConsole()) {
            $request = request();
            $attributes['ip_address'] = $request->ip();
            $attributes['user_agent'] = Str::limit((string) $request->userAgent(), 500, '');
            $attributes['request_id'] = $this->requestId();
        }

        // organization_id is filled by the BelongsToOrganization trait from the
        // active tenant context — it is not fillable, so no caller can spoof it.
        return AuditLog::query()->create($attributes);
    }

    /**
     * Record a model change, diffing only what actually changed.
     *
     * Recording every attribute on every update makes the trail unreadable;
     * the question an auditor asks is "what changed", not "what was there".
     */
    public function recordChange(
        string $action,
        Model $subject,
        ?string $description = null,
        ?User $actor = null,
    ): AuditLog {
        $changed = $subject->getChanges();

        // getOriginal() holds the pre-save values; narrow it to the keys that
        // actually moved.
        $before = array_intersect_key($subject->getOriginal(), $changed);

        return $this->record(
            action: $action,
            subject: $subject,
            description: $description,
            old: $before === [] ? null : $before,
            new: $changed === [] ? null : $changed,
            actor: $actor,
        );
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function redact(array $values): array
    {
        $clean = [];

        foreach ($values as $key => $value) {
            $clean[$key] = $this->isSensitive((string) $key)
                ? '[redacted]'
                : $this->normalise($value);
        }

        return $clean;
    }

    private function isSensitive(string $key): bool
    {
        $lower = mb_strtolower($key);

        foreach (self::REDACTED as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reduce a value to something that survives a JSON round trip intact.
     * Money becomes its decimal string — never a float.
     */
    private function normalise(mixed $value): mixed
    {
        return match (true) {
            $value instanceof Money => (string) $value->getAmount(),
            $value instanceof \BackedEnum => $value->value,
            $value instanceof \DateTimeInterface => $value->format(DATE_ATOM),
            is_object($value) => method_exists($value, '__toString') ? (string) $value : null,
            default => $value,
        };
    }

    private function actorType(?User $actor): string
    {
        if ($actor instanceof User) {
            return $this->runningInConsole() ? 'console' : 'user';
        }

        return $this->runningInConsole() ? 'system' : 'api';
    }

    private function channel(): string
    {
        if ($this->runningInConsole()) {
            return 'console';
        }

        return request()->is('api/*') ? 'api' : 'web';
    }

    /**
     * Ties an audit row to the request that produced it, and to the application
     * log lines from that same request.
     */
    private function requestId(): ?string
    {
        $header = request()->header('X-Request-Id');

        return is_string($header) && Str::isUuid($header) ? $header : null;
    }

    private function runningInConsole(): bool
    {
        return app()->runningInConsole();
    }
}
