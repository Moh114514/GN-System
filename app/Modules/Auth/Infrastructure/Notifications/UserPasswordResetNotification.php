<?php

namespace App\Modules\Auth\Infrastructure\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

final class UserPasswordResetNotification extends ResetPassword
{
    public function __construct($token, private readonly bool $initiatedByAdministrator = false)
    {
        parent::__construct($token);
    }

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
            ->subject(__($this->initiatedByAdministrator
                ? 'auth.mail.password_reset.admin_subject'
                : 'auth.mail.password_reset.subject'))
            ->greeting(__('auth.mail.password_reset.greeting', ['name' => $notifiable->name]))
            ->line(__($this->initiatedByAdministrator
                ? 'auth.mail.password_reset.admin_body'
                : 'auth.mail.password_reset.body'))
            ->action(__('auth.mail.password_reset.action'), $url)
            ->line(__('auth.mail.password_reset.expiration', ['count' => config('auth.passwords.'.config('auth.defaults.passwords').'.expire')]))
            ->salutation(__('auth.mail.salutation'));
    }
}
