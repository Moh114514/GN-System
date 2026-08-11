<?php

namespace App\Modules\Auth\Infrastructure\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

final class InternalUserInvitationNotification extends ResetPassword
{
    /** @return array<int, string> */
    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $url = url(route('account.invitation', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ], false));

        return (new MailMessage)
            ->subject(__('auth.mail.invitation.subject'))
            ->line(__('auth.mail.invitation.greeting', ['name' => $notifiable->name]))
            ->line(__('auth.mail.invitation.body'))
            ->action(__('auth.mail.invitation.action'), $url)
            ->line(__('auth.mail.invitation.expiration', ['count' => config('auth.passwords.'.config('auth.defaults.passwords').'.expire')]));
    }
}
