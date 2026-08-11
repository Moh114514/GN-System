<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

class ResetUserPassword implements ResetsUserPasswords
{
    use PasswordValidationRules;

    /**
     * Validate and reset the user's forgotten password.
     *
     * @param  array<string, string>  $input
     */
    public function reset(User $user, array $input): void
    {
        Validator::make($input, [
            'password' => $this->passwordRules(),
        ])->validate();

        DB::transaction(function () use ($input, $user): void {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            $attributes = ['password' => $input['password']];

            // Temporary compatibility for invitations issued before the dedicated invitation route.
            // Remove this branch after the legacy Password Broker tokens have expired in production.
            if ($locked->invitation_status !== 'accepted') {
                $attributes += [
                    'invitation_status' => 'accepted',
                    'email_verified_at' => $locked->email_verified_at ?? now(),
                ];
            } else {
                $attributes += [
                    'session_version' => $locked->session_version + 1,
                    'remember_token' => null,
                ];
            }

            $locked->forceFill($attributes)->save();
            DB::table('sessions')->where('user_id', $locked->id)->delete();
        });
    }
}
