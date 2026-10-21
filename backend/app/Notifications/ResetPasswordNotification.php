<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;

/** Points the reset link at the React SPA route instead of a Blade view. */
class ResetPasswordNotification extends ResetPassword
{
    protected function resetUrl($notifiable): string
    {
        return url('/admin/reset-password?'.http_build_query([
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]));
    }
}
