<?php

namespace App\Http\Controllers\Host;

use App\Http\Controllers\Controller;
use App\Models\Bid;
use App\Models\Event;
use App\Models\Group;
use App\Models\GroupMember;
use Illuminate\Http\Request;

class GroupController extends Controller
{
    /**
     * POST /api/host/events/{eventId}/groups/{groupId}/move-members
     *
     * Move selected GroupMembers from one group to another.
     * Called from the Proposed Group Allocations screen after a round closes.
     *
     * Body:
     *   {
     *     "to_group_id":      <int>,
     *     "group_member_ids": [<int>, ...]
     *   }
     */
    public function moveMembers(Request $request, $eventId, $groupId)
    {
        $request->validate([
            'to_group_id'        => 'required|integer',
            'group_member_ids'   => 'required|array|min:1',
            'group_member_ids.*' => 'integer',
        ]);

        // Verify the host owns this event
        $event = Event::where('host_id', $request->user()->id)
            ->findOrFail($eventId);

        // Both groups must belong to this event
        $fromGroup = Group::where('event_id', $event->id)->findOrFail($groupId);
        $toGroup   = Group::where('event_id', $event->id)->findOrFail($request->to_group_id);

        if ($fromGroup->id === $toGroup->id) {
            return response()->json(['message' => 'Source and destination groups are the same.'], 422);
        }

        // Find members scoped to the source group + event (security guard)
        $members = GroupMember::whereIn('id', $request->group_member_ids)
            ->where('group_id', $fromGroup->id)
            ->where('event_id', $event->id)
            ->get();

        if ($members->isEmpty()) {
            return response()->json(['message' => 'No valid members found to move.'], 422);
        }

        // Move them
        GroupMember::whereIn('id', $members->pluck('id'))
            ->update(['group_id' => $toGroup->id]);

        // Recalculate min_amount for both affected groups
        $roundId = $fromGroup->round_id;
        if ($roundId) {
            $this->recalcGroupMin($fromGroup, $roundId);
            $this->recalcGroupMin($toGroup, $roundId);
        }

        return response()->json([
            'message'       => "{$members->count()} member(s) moved successfully.",
            'moved_count'   => $members->count(),
            'from_group_id' => $fromGroup->id,
            'to_group_id'   => $toGroup->id,
        ]);
    }

    /**
     * POST /api/host/events/{eventId}/groups/rebalance
     *
     * Redistribute all members from the most recent closed round
     * as evenly as possible across its groups.
     */
    public function rebalance(Request $request, $eventId)
    {
        $event = Event::where('host_id', $request->user()->id)
            ->findOrFail($eventId);

        // Most recent closed round that has groups
        $round = $event->rounds()
            ->where('status', 'closed')
            ->orderByDesc('round_number')
            ->with('groups.members')
            ->first();

        if (!$round || $round->groups->isEmpty()) {
            return response()->json(['message' => 'No closed round with groups found.'], 422);
        }

        $groups     = $round->groups->values();
        $allMembers = $groups->flatMap(fn($g) => $g->members)->values();
        $groupCount = $groups->count();

        // Round-robin redistribution
        foreach ($allMembers as $index => $member) {
            $targetGroup = $groups[$index % $groupCount];
            if ($member->group_id !== $targetGroup->id) {
                $member->update(['group_id' => $targetGroup->id]);
            }
        }

        // Recalc min for all groups
        foreach ($groups as $group) {
            $this->recalcGroupMin($group, $round->id);
        }

        return response()->json([
            'message'       => 'Groups rebalanced successfully.',
            'group_count'   => $groupCount,
            'total_members' => $allMembers->count(),
        ]);
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function recalcGroupMin(Group $group, int $roundId): void
    {
        $userIds = GroupMember::where('group_id', $group->id)->pluck('user_id');

        $min = Bid::where('round_id', $roundId)
            ->whereIn('user_id', $userIds)
            ->min('amount');

        if ($min !== null) {
            $group->update(['min_amount' => (int) $min]);
        }
    }
}