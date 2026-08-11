<?php

namespace App\Modules\Auth\Presentation\Http\Controllers;

use App\Actions\Fortify\ResetUserPassword;
use App\Concerns\PasswordValidationRules;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Symfony\Component\HttpFoundation\Response;

final class AccountPasswordResetController extends Controller
{
    use PasswordValidationRules;

    public function __construct(private readonly ResetUserPassword $resetUserPassword) {}

    public function create(Request $request, string $token): Response
    {
        $user = $this->resolveUser((string) $request->query('email'), $token);

        abort_unless($user->invitation_status === 'accepted', 404);

        return $this->renderForUser($user, 'pages::auth.account-password-reset', [
            'token' => $token,
            'email' => $user->email,
        ]);
    }

    public function store(Request $request, string $token): Response
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => $this->passwordRules(),
            'password_confirmation' => ['required', 'string'],
        ]);
        $user = $this->resolveUser($data['email'], $token);
        DB::transaction(function () use ($data, $token, $user): void {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            abort_unless($locked->invitation_status === 'accepted', 404);
            abort_unless(Password::broker(config('fortify.passwords'))->tokenExists($locked, $token), 404);

            $this->resetUserPassword->reset($locked, $data);
            Password::broker(config('fortify.passwords'))->deleteToken($locked);
        });

        return $this->renderForUser($user, 'pages::auth.account-password-reset-success', ['email' => $user->email]);
    }

    private function resolveUser(string $email, string $token): User
    {
        $user = Password::broker(config('fortify.passwords'))->getUser([
            'email' => mb_strtolower(trim($email)),
        ]);

        abort_unless($user instanceof User, 404);
        abort_unless(Password::broker(config('fortify.passwords'))->tokenExists($user, $token), 404);

        return $user;
    }

    /** @param array<string, mixed> $data */
    private function renderForUser(User $user, string $view, array $data): Response
    {
        $previousLocale = App::getLocale();
        App::setLocale($user->preferredLocale());

        try {
            return response(view($view, $data)->render());
        } finally {
            App::setLocale($previousLocale);
        }
    }
}
