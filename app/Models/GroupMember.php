<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GroupMember extends Model
{
    protected $fillable = [
        'event_id',
        'group_id',
        'bid_id',        // ✅ added — set during grouping
        'user_id',
        'pseudonym',
        'emoji',
        'payment_status',
        'is_quit',
    ];

    protected $casts = [
        'is_quit' => 'boolean',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ✅ Added — links group_member to the bid placed in that round
    public function bid(): BelongsTo
    {
        return $this->belongsTo(Bid::class);
    }
}