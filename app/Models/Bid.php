<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Bid extends Model
{
    protected $fillable = [
        'event_id',
        'round_id',               // null for pending bids (round not yet open)
        'scheduled_round_number', // which round number this bid targets (1-based)
        'bid_status',             // 'pending' | 'active'
        'user_id',
        'amount',
        'pseudonym',
    ];

    protected $casts = [
        'amount'                 => 'integer',
        'scheduled_round_number' => 'integer',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function round(): BelongsTo
    {
        return $this->belongsTo(Round::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}