<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PublishedShift extends Model
{
    protected $fillable = [
        'shift_schedule_id',
        'user_id',
        'work_date',
        'sequence',
        'pattern_code',
        'work_hours',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'sequence' => 'integer',
            'work_hours' => 'decimal:2',
            'published_at' => 'datetime',
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
}
