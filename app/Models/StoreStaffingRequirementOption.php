<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StoreStaffingRequirementOption extends Model
{
    protected $fillable = [
        'store_staffing_requirement_id',
        'code',
        'display_order',
    ];

    protected function casts(): array
    {
        return [
            'display_order' => 'integer',
        ];
    }

    public function requirement(): BelongsTo
    {
        return $this->belongsTo(
            StoreStaffingRequirement::class,
            'store_staffing_requirement_id',
        );
    }

    public function patterns(): HasMany
    {
        return $this->hasMany(StoreStaffingRequirementOptionPattern::class)
            ->orderBy('id');
    }
}
