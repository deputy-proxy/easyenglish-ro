<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string|null $phone
 * @property Carbon|null $phone_verified_at
 * @property bool $is_staff
 * @property bool $is_admin
 * @property bool $is_teacher
 * @property bool $teacher_active
 * @property TeacherCalendar|null $teacherCalendar
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password', 'phone', 'phone_verified_at', 'is_staff', 'is_admin', 'is_teacher', 'teacher_active', 'english_level', 'session_goal', 'session_notes', 'teacher_photo_path', 'teacher_bio'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /** @var array<string, mixed> Temporary compatibility values for legacy callers. */
    protected array $pendingProfileAttributes = [];

    /** @var array<string, array{relation: string, field: string}> */
    private const LEGACY_PROFILE_ATTRIBUTES = [
        'english_level' => ['relation' => 'studentProfile', 'field' => 'english_level'],
        'session_goal' => ['relation' => 'studentProfile', 'field' => 'learning_goal'],
        'session_notes' => ['relation' => 'studentProfile', 'field' => 'notes'],
        'teacher_photo_path' => ['relation' => 'teacherProfile', 'field' => 'photo_path'],
        'teacher_bio' => ['relation' => 'teacherProfile', 'field' => 'bio'],
    ];

    public function setAttribute($key, $value): static
    {
        if (isset(self::LEGACY_PROFILE_ATTRIBUTES[$key])) {
            $this->pendingProfileAttributes[$key] = $value;

            return $this;
        }

        return parent::setAttribute($key, $value);
    }

    public function getAttribute($key): mixed
    {
        if (isset(self::LEGACY_PROFILE_ATTRIBUTES[$key])) {
            $mapping = self::LEGACY_PROFILE_ATTRIBUTES[$key];
            $profile = $this->getRelationValue($mapping['relation']);

            if ($profile !== null) {
                return $profile->{$mapping['field']} ?? $this->pendingProfileAttributes[$key] ?? null;
            }

            return $this->pendingProfileAttributes[$key] ?? null;
        }

        return parent::getAttribute($key);
    }

    protected static function booted(): void
    {
        static::created(function (User $user): void {
            $user->persistPendingProfileAttributes();
        });

        static::saved(function (User $user): void {
            $user->persistPendingProfileAttributes();
        });

        static::saving(function (User $user): void {
            if ($user->is_admin) {
                $user->is_staff = true;
            }
        });

        static::deleting(function (User $user): void {
            if ($user->isTeacher()) {
                if ($user->bookingsAsTeacher()->exists()) {
                    throw ValidationException::withMessages(['account' => 'Teacher accounts with booking history cannot be deleted. Deactivate the teacher account instead.']);
                }

                if ($user->teacherCalendar()->exists()) {
                    throw ValidationException::withMessages(['account' => 'Disconnect the teacher Google Calendar before deleting this account.']);
                }
            }

            Booking::query()->where('customer_user_id', $user->id)->update(['customer_ownership_revoked_at' => now()]);
            BundleEntitlement::query()->where('customer_user_id', $user->id)->update(['customer_ownership_revoked_at' => now()]);
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'is_staff' => 'boolean',
            'is_admin' => 'boolean',
            'is_teacher' => 'boolean',
            'teacher_active' => 'boolean',
            'password' => 'hashed',
        ];
    }

    /**
     * Get the user's initials
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return (bool) $this->is_staff;
    }

    /** Persist legacy mass-assigned profile values while callers transition to profile relations. */
    protected function persistPendingProfileAttributes(): void
    {
        if ($this->pendingProfileAttributes === []) {
            return;
        }

        $student = [];
        $teacher = [];
        foreach ($this->pendingProfileAttributes as $key => $value) {
            $mapping = self::LEGACY_PROFILE_ATTRIBUTES[$key];
            if ($mapping['relation'] === 'teacherProfile') {
                $teacher[$mapping['field']] = $value;
            } else {
                $student[$mapping['field']] = $value;
            }
        }

        if ($student !== [] && ! $this->is_teacher && ! $this->is_staff) {
            $this->studentProfile()->updateOrCreate([], $student);
        }
        if ($teacher !== [] && $this->is_teacher) {
            $this->teacherProfile()->updateOrCreate([], $teacher);
        }

        $this->pendingProfileAttributes = [];
    }

    /** @return HasOne<StudentProfile, $this> */
    public function studentProfile(): HasOne
    {
        return $this->hasOne(StudentProfile::class);
    }

    /** @return HasOne<TeacherProfile, $this> */
    public function teacherProfile(): HasOne
    {
        return $this->hasOne(TeacherProfile::class);
    }

    /** @return HasMany<AvailabilityRule, $this> */
    public function availabilityRules(): HasMany
    {
        return $this->hasMany(AvailabilityRule::class);
    }

    /** @return HasMany<AvailabilityException, $this> */
    public function availabilityExceptions(): HasMany
    {
        return $this->hasMany(AvailabilityException::class);
    }

    /** @return HasMany<Booking, $this> */
    public function customerBookings(): HasMany
    {
        return $this->hasMany(Booking::class, 'customer_user_id');
    }

    /** @return HasMany<BundleEntitlement, $this> */
    public function bundleEntitlements(): HasMany
    {
        return $this->hasMany(BundleEntitlement::class, 'customer_user_id');
    }

    /** @return HasMany<Booking, $this> */
    public function bookingsAsTeacher(): HasMany
    {
        return $this->hasMany(Booking::class, 'teacher_id');
    }

    /** @return HasOne<TeacherCalendar, $this> */
    public function teacherCalendar(): HasOne
    {
        return $this->hasOne(TeacherCalendar::class);
    }

    public function isTeacher(): bool
    {
        return (bool) $this->is_teacher;
    }

    public function isAdmin(): bool
    {
        return (bool) $this->is_admin;
    }

    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }
}
