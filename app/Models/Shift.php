<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Shift extends Model
{
    protected $fillable = [
        'shift_schedule_id',
        'user_id',
        'work_date',
        'store_shift_pattern_id',
        'sequence',
        'entry_uuid',
        'pattern_code',
        'work_hours',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'sequence' => 'integer',
            'work_hours' => 'decimal:2',
        ];
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(ShiftSchedule::class, 'shift_schedule_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function pattern(): BelongsTo
    {
        return $this->belongsTo(StoreShiftPattern::class, 'store_shift_pattern_id');
    }
}
