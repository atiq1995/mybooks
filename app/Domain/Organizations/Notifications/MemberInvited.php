<?php

declare(strict_types=1);

namespace App\Domain\Organizations\Notifications;

use App\Domain\Access\Enums\Role;
use App\Domain\Organizations\Models\Organization;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The invitation email.
 *
 * Queued: sending happens over SMTP, and an invitation that fails to send
 * must be retried rather than taking the inviting request down with it.
 *
 * The link carries the only copy of the token that exists in plaintext — the
 * database holds a SHA-256 hash — so this email is not reproducible after the
 * fact. "Resend" issues a new token rather than resending this one.
 */
final class MemberInvited extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly Organization $organization,
        private readonly Role $role,
        private readonly User $invitedBy,
        private readonly string $token,
        private readonly string $email,
    ) {
        $this->onQueue('default');
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = url("/invitations/{$this->token}");

        $days = config()->integer('my-books.invitations.expire_after_days', 7);

        return (new MailMessage)
            ->subject("{$this->invitedBy->name} invited you to {$this->organization->name} on My Books")
            ->greeting('Hello')
            ->line(
                "{$this->invitedBy->name} has invited you to keep the books for ".
                "{$this->organization->name} on My Books, as a {$this->role->label()}."
            )
            ->line($this->role->description())
            ->action('Accept the invitation', $url)
            ->line("This link expires in {$days} days and can be used once.")
            ->line(
                'If you were not expecting this, you can ignore this email — '.
                'nothing happens until the link is opened.'
            )
            ->salutation('— My Books');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        // Deliberately excludes the token: this array can end up in the failed
        // jobs table, and a queue backup should not hand out invitations.
        return [
            'organization_id' => $this->organization->getKey(),
            'organization_name' => $this->organization->name,
            'role' => $this->role->value,
            'invited_email' => $this->email,
            'invited_by' => $this->invitedBy->getKey(),
        ];
    }
}
