<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Event extends Model
{
    protected $fillable = [
        'host_id',
        'name',
        'charity_name',
        'description',
        'target_amount',
        'rounds_count',
        'group_size',
        'started_at',
        'duration',        // HH:MM — how long each round lasts
        'round_time',      // seconds — waiting period between rounds
        'ended_at',
        'charity_link',
        'logo',
        'images',
        'join_code',
        'qr_code',
        'status',
    ];

    protected $casts = [
        'images'        => 'array',
        'target_amount' => 'integer',
        'rounds_count'  => 'integer',
        'group_size'    => 'integer',
        'round_time'    => 'integer',
        'started_at'    => 'datetime',
        'ended_at'      => 'datetime',
    ];

    public function rounds(): HasMany
    {
        return $this->hasMany(Round::class);
    }

    public function groups(): HasMany
    {
        return $this->hasMany(Group::class);
    }

    /**
     * Current active round — prefers 'open' over 'waiting',
     * and lowest round_number if multiple exist.
     */
    public function currentRound()
    {
        return $this->hasOne(Round::class)
            ->whereIn('status', ['waiting', 'open'])
            ->orderByRaw("FIELD(status, 'open', 'waiting')")
            ->orderBy('round_number', 'asc');
    }

    public function donors(): HasMany
    {
        return $this->hasMany(GroupMember::class);
    }

    /**
     * Computed: sum of all bids across all closed rounds.
     */
    public function getTotalRaisedAttribute(): int
    {
        return $this->rounds()
            ->with('bids')
            ->get()
            ->sum(fn($round) => $round->bids->sum('amount'));
    }
}