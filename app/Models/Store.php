<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Store extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'code',
        'name',
        'status',
        'display_order',
        'staffing_check_mode',
        'required_staff_count',
    ];

    protected function casts(): array
    {
        return [
            'display_order' => 'integer',
            'required_staff_count' => 'integer',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function staffMembers(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot(['display_order', 'is_active', 'started_on', 'ended_on'])
            ->withTimestamps();
    }

    public function shiftManagers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'store_shift_manager')
            ->withPivot(['is_active', 'started_on', 'ended_on'])
            ->withTimestamps();
    }

    public function shiftPatterns(): HasMany
    {
        return $this->hasMany(StoreShiftPattern::class);
    }

    public function shiftSchedules(): HasMany
    {
        return $this->hasMany(ShiftSchedule::class);
    }

    public function staffingRequirements(): HasMany
    {
        return $this->hasMany(StoreStaffingRequirement::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
