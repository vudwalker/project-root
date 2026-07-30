<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class StoreStaffingRequirement extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'store_id',
        'work_date',
        'weekday',
        'effective_from',
        'effective_to',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'weekday' => 'integer',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(StoreStaffingRequirementOption::class)
            ->orderBy('display_order')
            ->orderBy('id');
    }
}
