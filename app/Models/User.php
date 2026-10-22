<?php

namespace App\Models;

use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Wave\Traits\HasProfileKeyValues;
use Wave\User as WaveUser;

class User extends WaveUser
{
    use HasFactory, HasProfileKeyValues, Notifiable;

    public $guard_name = 'web';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'username',
        'avatar',
        'password',
        'provider',
        'provider_id',
        'verification_code',
        'verified',
        'email_verified_at',
        'trial_ends_at',
        'locale',
        'group_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    protected function casts(): array
    {
        return [
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /** Whether the user has completed (confirmed) TOTP two-factor enrollment. */
    public function hasTwoFactorEnabled(): bool
    {
        return ! is_null($this->two_factor_confirmed_at);
    }

    /**
     * Return the user's primary role (first assigned via Spatie permissions).
     * Replaces the commented-out belongsTo in wave/src/User.php.
     */
    public function getRoleAttribute(): ?Role
    {
        $this->loadMissing('roles');

        return $this->roles->first();
    }

    /**
     * Return the primary role's ID so VaultTools role_id lookups work.
     */
    public function getRoleIdAttribute(): ?int
    {
        $this->loadMissing('roles');

        return $this->roles->first()?->id;
    }

    protected static function boot()
    {
        parent::boot();

        // Listen for the creating event of the model
        static::creating(function ($user) {
            // Appliance build: open-core baseline allows exactly ONE admin
            // when no license is installed (the seeder-planted operator
            // account). With a license, enforce the seat cap normally.
            if (isAppliance()) {
                $license = LocalLicense::current();
                if (! $license) {
                    if (self::query()->count() > 0) {
                        throw new RuntimeException(__('licensing.user_creating_single_admin'));
                    }
                    // First user is allowed without a license — that's the
                    // open-core single-admin baseline.
                } elseif (self::query()->count() >= $license->seats) {
                    throw new RuntimeException("Seat limit reached: the installed license allows {$license->seats} user(s). Renew or upgrade the license to add more.");
                }
            }

            // Check if the username attribute is empty
            if (empty($user->username)) {
                // Use the name to generate a slugified username
                $username = Str::slug($user->name, '');
                $i = 1;
                while (self::where('username', $username)->exists()) {
                    $username = Str::slug($user->name, '').$i;
                    $i++;
                }
                $user->username = $username;
            }
        });

        // Listen for the created event of the model
        static::created(function ($user) {
            // Remove all roles
            $user->syncRoles([]);
            // Assign the default role
            $user->assignRole(setting('auth.default_role', 'free'));
        });
    }

    public function vault(): HasOne
    {
        return $this->hasOne(Vault::class, 'owner');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function isTeamManager(): bool
    {
        return Group::where('owner_id', $this->id)->exists();
    }

    /**
     * Suppress Laravel's default verification email.
     * sos-vault sends its own branded verification email via the SendUserEmail
     * event in RegisterController::create(). Leaving this as a no-op prevents
     * a second generic "Verify Email Address" email from being sent whenever the
     * Registered event fires and SendEmailVerificationNotification runs.
     */
    public function sendEmailVerificationNotification(): void
    {
        // Intentionally empty — custom email dispatched in RegisterController.
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return match ($panel->getId()) {
            'admin' => $this->hasRole('admin'),
            default => false,
        };
    }
}
