<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Group extends Model
{
    use HasFactory;

    protected $fillable = [
        'event_id',     // ← added
        'round_id',
        'group_name',
        'min_amount',
        'match_ratio',
        'total_amount',
    ];

    protected $casts = [
        'min_amount'   => 'integer',
        'total_amount' => 'integer',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function round(): BelongsTo
    {
        return $this->belongsTo(Round::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(GroupMember::class);
    }

    public function bids(): HasManyThrough
    {
        return $this->hasManyThrough(
            Bid::class,
            GroupMember::class,
            'group_id',
            'id',
            'id',
            'bid_id'
        );
    }
}