<?php

namespace App\Actions\Auth;

use App\Concerns\PasswordValidationRules;
use App\Models\User;
use Illuminate\Support\Facades\Validator;

final class CompleteInvitation
{
    use PasswordValidationRules;

    /** @param array<string, mixed> $input */
    public function complete(User $user, array $input): void
    {
        Validator::make($input, [
            'password' => $this->passwordRules(),
        ])->validate();

        $user->forceFill([
            'password' => $input['password'],
            'invitation_status' => 'accepted',
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();
    }
}
