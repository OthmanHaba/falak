<?php

namespace Falak\Identity\Application\Notifications;

use Falak\Identity\Contracts\Role;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class OrganizationInvitation extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $organizationName,
        public readonly string $inviterName,
        public readonly Role $role,
        public readonly string $url,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("You've been invited to {$this->organizationName} on ".config('app.name'))
            ->line("{$this->inviterName} invited you to join {$this->organizationName} as {$this->role->label()}.")
            ->action('Accept invitation', $this->url)
            ->line('This invitation expires in 7 days. If you were not expecting it, you can ignore this email.');
    }
}
