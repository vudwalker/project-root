<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class StoreShiftPattern extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'store_id',
        'code',
        'work_minutes',
        'display_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'work_minutes' => 'integer',
            'display_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function shifts(): HasMany
    {
        return $this->hasMany(Shift::class);
    }
}
