<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreStaffingRequirementOptionPattern extends Model
{
    protected $fillable = [
        'store_staffing_requirement_option_id',
        'store_shift_pattern_id',
        'required_count',
    ];

    protected function casts(): array
    {
        return [
            'required_count' => 'integer',
        ];
    }

    public function option(): BelongsTo
    {
        return $this->belongsTo(
            StoreStaffingRequirementOption::class,
            'store_staffing_requirement_option_id',
        );
    }

    public function shiftPattern(): BelongsTo
    {
        return $this->belongsTo(StoreShiftPattern::class, 'store_shift_pattern_id');
    }
}
