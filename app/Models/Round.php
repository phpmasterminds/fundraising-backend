<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Round extends Model
{
    protected $fillable = [
        'event_id',
        'round_number',
        'status',       // waiting | open | closed
        'opened_at',
        'closed_at',
    ];

    protected $casts = [
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function bids(): HasMany
    {
        return $this->hasMany(Bid::class);
    }

    // ✅ Added — groups formed when this round closes
    public function groups(): HasMany
    {
        return $this->hasMany(Group::class);
    }
}