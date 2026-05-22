<?php

namespace App\Services;

use App\Models\Round;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Bid;

class GroupingService
{
    /**
     * EXISTING METHOD — called when host closes a round.
     * Shuffles all bids and forms final groups.
     * Kept exactly as-is.
     */
    public function formGroups(Round $round, int $groupSize): array
    {
        $bids = $round->bids()
            ->get()
            ->shuffle()
            ->values();

        if ($bids->isEmpty()) {
            return [];
        }

        $chunks = $bids->chunk($groupSize);
        $groups = [];
        $letter = 'A';

        foreach ($chunks as $chunk) {
            $minAmount   = (int) $chunk->min('amount');
            $totalAmount = (int) $chunk->sum('amount');
            $count       = $chunk->count();
            $matchRatio  = '1:' . ($count - 1);

            $group = Group::create([
                'event_id'    => $round->event_id,
                'round_id'    => $round->id,
                'group_name'  => 'Group ' . $letter,
                'min_amount'  => $minAmount,
                'match_ratio' => $matchRatio,
                'total_amount'=> $totalAmount,
            ]);

            foreach ($chunk as $bid) {
                GroupMember::updateOrCreate(
                    [
                        'event_id' => $round->event_id,
                        'user_id'  => $bid->user_id,
                    ],
                    [
                        'group_id'  => $group->id,
                        'bid_id'    => $bid->id,
                        'pseudonym' => $bid->pseudonym ?? null,
                    ]
                );
            }

            $groups[] = $group->load('members');
            $letter++;
        }

        return $groups;
    }

    /**
     * NEW — called immediately after a donor places their FIRST bid.
     * Assigns them to a live preview group sequentially:
     *   Donors 1 to group_size  → Group A
     *   Next group_size donors  → Group B  etc.
     *
     * This is a LIVE PREVIEW shown during bidding.
     * formGroups() will re-shuffle and finalise when round closes.
     */
    public function assignOnBid(Round $round, int $userId, int $bidId): void
    {
        $event     = $round->event;
        $groupSize = (int) $event->group_size;

        // Get ordered bid positions for this round (chronological)
        $orderedUserIds = Bid::where('round_id', $round->id)
            ->orderBy('created_at', 'asc')
            ->pluck('user_id')
            ->values();

        // Find this donor's 0-based position in the bid order
        $position = $orderedUserIds->search($userId);
        if ($position === false) {
            $position = max(0, $orderedUserIds->count() - 1);
        }

        $groupIndex  = (int) floor($position / $groupSize);
        $groupLetter = chr(65 + $groupIndex); // 0→A, 1→B, 2→C...
        $groupName   = 'Group ' . $groupLetter;

        // Find or create a preview group for this round+letter
        $group = Group::firstOrCreate(
            [
                'round_id'   => $round->id,
                'group_name' => $groupName,
            ],
            [
                'event_id'    => $event->id,
                'min_amount'  => 0,
                'match_ratio' => '1:' . ($groupSize - 1),
                'total_amount'=> 0,
            ]
        );

        $bid = Bid::find($bidId);

        // Scope lookup to this round's groups only — avoids hitting stale rows from other rounds
        $roundGroupIds = Group::where('round_id', $round->id)->pluck('id');

        $existingMember = GroupMember::where('event_id', $event->id)
            ->where('user_id', $userId)
            ->whereIn('group_id', $roundGroupIds)
            ->first();

        if ($existingMember) {
            // Move to new group if reassigned, update bid reference
            $existingMember->update([
                'group_id'  => $group->id,
                'bid_id'    => $bidId,
                'pseudonym' => $bid?->pseudonym ?? $existingMember->pseudonym,
            ]);
        } else {
            // Check for NULL-group_id row (pre-existing member from join flow)
            $nullMember = GroupMember::where('event_id', $event->id)
                ->where('user_id', $userId)
                ->whereNull('group_id')
                ->first();

            if ($nullMember) {
                $nullMember->update([
                    'group_id'  => $group->id,
                    'bid_id'    => $bidId,
                    'pseudonym' => $bid?->pseudonym ?? $nullMember->pseudonym,
                ]);
            } else {
                // Create fresh member row
                GroupMember::create([
                    'event_id'  => $event->id,
                    'user_id'   => $userId,
                    'group_id'  => $group->id,
                    'bid_id'    => $bidId,
                    'pseudonym' => $bid?->pseudonym ?? null,
                ]);
            }
        }

        // Recalculate live min_amount for this preview group
        $this->recalcGroupMin($group, $round->id);
    }

    /**
     * Repair existing bids that have no GroupMember assignment.
     * Call this once via a route or tinker to fix historical data.
     */
    public function repairRound(Round $round): void
    {
        $event     = $round->event;
        $groupSize = (int) $event->group_size;

        $bids = Bid::where('round_id', $round->id)
            ->orderBy('created_at', 'asc')
            ->get();

        foreach ($bids as $index => $bid) {
            $groupIndex  = (int) floor($index / $groupSize);
            $groupLetter = chr(65 + $groupIndex);
            $groupName   = 'Group ' . $groupLetter;

            $group = Group::firstOrCreate(
                ['round_id' => $round->id, 'group_name' => $groupName],
                [
                    'event_id'    => $event->id,
                    'min_amount'  => 0,
                    'match_ratio' => '1:' . ($groupSize - 1),
                    'total_amount'=> 0,
                ]
            );

            // Fix the NULL-group_id row for this donor
            GroupMember::where('event_id', $event->id)
                ->where('user_id', $bid->user_id)
                ->update([
                    'group_id'  => $group->id,
                    'bid_id'    => $bid->id,
                    'pseudonym' => $bid->pseudonym ?? null,
                ]);
        }

        // Recalc min for all groups in this round
        Group::where('round_id', $round->id)->get()->each(function ($group) use ($round) {
            $this->recalcGroupMin($group, $round->id);
        });
    }

    /**
     * NEW — called when a donor updates their existing bid amount.
     * Recalculates min_amount on their current group.
     */
    public function updateGroupMin(Round $round, int $userId): void
    {
        $member = GroupMember::where('event_id', $round->event_id)
            ->where('user_id', $userId)
            ->first();

        if ($member && $member->group_id) {
            $group = Group::find($member->group_id);
            if ($group) {
                $this->recalcGroupMin($group, $round->id);

                // Keep bid_id reference current
                $bid = Bid::where('round_id', $round->id)
                    ->where('user_id', $userId)
                    ->first();
                if ($bid) {
                    $member->update(['bid_id' => $bid->id]);
                }
            }
        }
    }

    // ── Private helpers ───────────────────────────────────────────

    private function recalcGroupMin(Group $group, int $roundId): void
    {
        $memberUserIds = GroupMember::where('group_id', $group->id)
            ->pluck('user_id');

        $min = Bid::where('round_id', $roundId)
            ->whereIn('user_id', $memberUserIds)
            ->min('amount');

        if ($min !== null) {
            $group->update(['min_amount' => (int) $min]);
        }
    }
}