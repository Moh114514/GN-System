<?php

namespace App\Modules\Auth\Infrastructure\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

final class UserPasswordResetNotification extends ResetPassword
{
    /** @return array<int, string> */
    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $url = url(route('account.password-reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ], false));

        return (new MailMessage)
            ->subject(__('auth.mail.password_reset.subject'))
            ->line(__('auth.mail.password_reset.greeting', ['name' => $notifiable->name]))
            ->line(__('auth.mail.password_reset.body'))
            ->action(__('auth.mail.password_reset.action'), $url)
            ->line(__('auth.mail.password_reset.expiration', ['count' => config('auth.passwords.'.config('auth.defaults.passwords').'.expire')]));
    }
}
