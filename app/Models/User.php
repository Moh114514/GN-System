<?php

namespace App\Models;

use App\Infrastructure\Localization\SupportedLocale;
use App\Modules\Auth\Domain\UserRole;
use App\Modules\Auth\Infrastructure\Models\BusinessGroupMembership;
use App\Modules\Auth\Infrastructure\Notifications\InternalUserInvitationNotification;
use App\Modules\Auth\Infrastructure\Notifications\UserPasswordResetNotification;
// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string|null $dingtalk_mention_type
 * @property string|null $dingtalk_mention_value
 * @property string $preferred_locale
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property bool $is_super_admin
 * @property UserRole $role
 * @property bool $is_active
 * @property int $session_version
 * @property string $invitation_status
 * @property Carbon|null $invitation_sent_at
 * @property Carbon|null $disabled_at
 * @property int|null $disabled_by
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'name', 'email', 'dingtalk_mention_type', 'dingtalk_mention_value', 'password', 'preferred_locale', 'is_super_admin', 'role', 'is_active', 'invitation_status',
    'invitation_sent_at', 'disabled_at', 'disabled_by', 'remember_token', 'session_version',
])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements HasLocalePreference
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable;

    protected $attributes = [
        'is_active' => true,
        'session_version' => 1,
        'invitation_status' => 'accepted',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'is_super_admin' => 'boolean',
            'role' => UserRole::class,
            'is_active' => 'boolean',
            'session_version' => 'integer',
            'invitation_sent_at' => 'datetime',
            'disabled_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $user): void {
            if ($user->isDirty('role')) {
                $roleAttribute = $user->getAttribute('role');
                $role = $roleAttribute instanceof UserRole
                    ? $roleAttribute
                    : UserRole::tryFrom((string) $roleAttribute);

                if ($role !== null) {
                    // During the RC compatibility period, an old caller may
                    // override only is_super_admin while the factory supplies
                    // the new default role. Let that explicit legacy flag win
                    // only for the default customer-service value.
                    if ($user->isDirty('is_super_admin')
                        && (bool) $user->is_super_admin
                        && $role === UserRole::CustomerService) {
                        $user->role = UserRole::SuperAdmin;
                    } else {
                        $user->is_super_admin = $role === UserRole::SuperAdmin;
                    }
                }
            } elseif ($user->isDirty('is_super_admin')) {
                $user->role = (bool) $user->is_super_admin
                    ? UserRole::SuperAdmin
                    : UserRole::CustomerService;
            }
        });
    }

    public function roleValue(): UserRole
    {
        $role = $this->getAttribute('role');

        return $role instanceof UserRole
            ? $role
            : (UserRole::tryFrom((string) $role)
                ?? ((bool) $this->is_super_admin ? UserRole::SuperAdmin : UserRole::CustomerService));
    }

    public function isSuperAdmin(): bool
    {
        return $this->roleValue() === UserRole::SuperAdmin || (bool) $this->is_super_admin;
    }

    public function isBdManager(): bool
    {
        return $this->roleValue() === UserRole::BdManager && ! $this->isSuperAdmin();
    }

    public function isCustomerService(): bool
    {
        return $this->roleValue() === UserRole::CustomerService && ! $this->isSuperAdmin();
    }

    public function canManageConfiguration(): bool
    {
        return $this->isSuperAdmin();
    }

    /** @return HasMany<BusinessGroupMembership, $this> */
    public function businessGroupMemberships(): HasMany
    {
        return $this->hasMany(BusinessGroupMembership::class);
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }

    public function preferredLocale(): string
    {
        $locale = SupportedLocale::fromCandidate($this->preferred_locale);

        return $locale instanceof SupportedLocale
            ? $locale->value
            : SupportedLocale::default()->value;
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify($this->invitation_status === 'accepted'
            ? new UserPasswordResetNotification($token)
            : new InternalUserInvitationNotification($token));
    }
}
