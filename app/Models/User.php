<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable([
    'organization_id',
    'primary_store_id',
    'name',
    'email',
    'password',
    'status',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, SoftDeletes;

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function primaryStore(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'primary_store_id');
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)->withTimestamps();
    }

    public function stores(): BelongsToMany
    {
        return $this->belongsToMany(Store::class)
            ->withPivot(['display_order', 'is_active', 'started_on', 'ended_on'])
            ->withTimestamps();
    }

    public function managedStores(): BelongsToMany
    {
        return $this->belongsToMany(Store::class, 'store_shift_manager')
            ->withPivot(['is_active', 'started_on', 'ended_on'])
            ->withTimestamps();
    }

    public function shifts(): HasMany
    {
        return $this->hasMany(Shift::class);
    }

    public function publishedShifts(): HasMany
    {
        return $this->hasMany(PublishedShift::class);
    }

    public function hasRole(string ...$codes): bool
    {
        if ($codes === []) {
            return false;
        }

        if ($this->relationLoaded('roles')) {
            return $this->roles->whereIn('code', $codes)->isNotEmpty();
        }

        return $this->roles()->whereIn('code', $codes)->exists();
    }

    public function canAccessAdmin(): bool
    {
        return $this->status === 'active'
            && $this->hasRole('shift_manager', 'system_admin');
    }

    public function managesStore(Store|int $store): bool
    {
        if ($this->hasRole('system_admin')) {
            return true;
        }

        if (! $this->hasRole('shift_manager')) {
            return false;
        }

        $storeId = $store instanceof Store ? $store->getKey() : $store;

        return $this->managedStores()
            ->whereKey($storeId)
            ->wherePivot('is_active', true)
            ->exists();
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
            'password' => 'hashed',
            'deleted_at' => 'datetime',
        ];
    }
}
