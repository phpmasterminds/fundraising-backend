<?php

namespace App\Services;

use App\Models\Round;
use App\Models\Group;
use App\Models\GroupMember;

class GroupingService
{
    public function formGroups(Round $round, int $groupSize): array
    {
        // Get all bids for this round sorted by amount descending
        // is_quit check removed — bids table may not have is_quit column
        $bids = $round->bids()
            ->orderByDesc('amount')
            ->get();

        if ($bids->isEmpty()) {
            return [];
        }

        $chunks = $bids->chunk($groupSize);
        $groups = [];
        $letter = 'A';

        foreach ($chunks as $chunk) {
            $minAmount   = (int) $chunk->min('amount');
            $totalAmount = $chunk->count() * $minAmount; // matched total (min × group size)
            $count       = $chunk->count();
            $matchRatio  = '1:' . ($count - 1);

            // Create the group record
            $group = Group::create([
                'event_id'    => $round->event_id,   // ✅ required for donor lookup
                'round_id'    => $round->id,
                'group_name'  => 'Group ' . $letter,
                'min_amount'  => $minAmount,
                'match_ratio' => $matchRatio,
                'total_amount'=> $totalAmount,
            ]);

            // ✅ Update EXISTING group_member rows (don't create new ones)
            // group_members already exist from when donors joined the event
            foreach ($chunk as $bid) {
                GroupMember::where('event_id', $round->event_id)
                    ->where('user_id', $bid->user_id)
                    ->update([
                        'group_id' => $group->id,
                        'bid_id'   => $bid->id,
                    ]);
            }

            $groups[] = $group->load('members');
            $letter++;
        }

        return $groups;
    }
}