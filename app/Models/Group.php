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
        'event_id',     // ✅ added — needed for donor lookup
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
            'group_id', // FK on group_members
            'id',       // FK on bids
            'id',       // local key on groups
            'bid_id'    // local key on group_members
        );
    }
}