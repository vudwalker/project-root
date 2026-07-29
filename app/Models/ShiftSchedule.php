<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShiftSchedule extends Model
{
    protected $fillable = [
        'store_id',
        'target_month',
        'draft_version',
        'published_version',
        'shift_updated_at',
        'published_at',
        'published_by',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'target_month' => 'date',
            'draft_version' => 'integer',
            'published_version' => 'integer',
            'shift_updated_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function shifts(): HasMany
    {
        return $this->hasMany(Shift::class)
            ->orderBy('work_date')
            ->orderBy('sequence')
            ->orderBy('id');
    }

    public function publishedShifts(): HasMany
    {
        return $this->hasMany(PublishedShift::class)
            ->orderBy('work_date')
            ->orderBy('sequence');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }
}
