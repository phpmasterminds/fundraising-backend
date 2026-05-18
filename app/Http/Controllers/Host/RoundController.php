<?php

namespace App\Http\Controllers\Host;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Round;
use App\Models\Bid;
use App\Services\GroupingService;
use Illuminate\Http\Request;

class RoundController extends Controller
{
    protected GroupingService $grouping;

    public function __construct(GroupingService $grouping)
    {
        $this->grouping = $grouping;
    }

    /**
     * POST /api/host/events/{id}/rounds/start
     *
     * Host opens the next waiting round.
     * Since all rounds are pre-created as 'waiting', this simply
     * updates the next waiting round to 'open' and releases
     * any pending bids donors placed during the waiting period.
     */
    public function start(Request $request, $id)
    {
        $event = Event::where('host_id', $request->user()->id)
            ->findOrFail($id);

        // Prevent opening if a round is already open
        $alreadyOpen = $event->rounds()->where('status', 'open')->exists();
        if ($alreadyOpen) {
            return response()->json(['message' => 'A round is already open.'], 422);
        }

        // Get the next waiting round
        $round = $event->rounds()
            ->where('status', 'waiting')
            ->orderBy('round_number')
            ->first();

        if (!$round) {
            return response()->json(['message' => 'All rounds completed.'], 422);
        }

        // Open it
        $round->update([
            'status'    => 'open',
            'opened_at' => now(),
            'closed_at' => null,
        ]);

        // Release all pending bids donors placed for this round during waiting period
        $released = $this->releasePendingBids($round);

        return response()->json([
            'round'         => $round->fresh(),
            'bids_released' => $released,
        ], 200);
    }

    /**
     * POST /api/host/events/{id}/rounds/{roundId}/end
     *
     * Host closes the currently open round.
     * All other rounds remain in their current status.
     */
    public function end(Request $request, $id, $roundId)
    {
        $event = Event::where('host_id', $request->user()->id)
            ->findOrFail($id);

        $round = Round::where('event_id', $event->id)
            ->findOrFail($roundId);

        if ($round->status !== 'open') {
            return response()->json(['message' => 'Round is not open.'], 422);
        }

        $round->update([
            'status'    => 'closed',
            'closed_at' => now(),
        ]);

        // Check if all rounds are now closed
        $allDone = !$event->rounds()->whereIn('status', ['waiting', 'open'])->exists();

        if ($allDone) {
            $event->update(['status' => 'finished']);
        }

        return response()->json([
            'round'    => $round->fresh(),
            'all_done' => $allDone,
        ]);
    }

    /**
     * GET /api/host/events/{id}/rounds
     */
    public function index(Request $request, $id)
    {
        $event = Event::where('host_id', $request->user()->id)
            ->findOrFail($id);

        $rounds = $event->rounds()
            ->orderBy('round_number')
            ->with(['bids.user', 'groups.members.bid.user'])
            ->get();

        return response()->json($rounds);
    }

    /**
     * Release all pending bids for a round that just opened.
     * Activates them and assigns donors to groups.
     */
    private function releasePendingBids(Round $round): int
    {
        $pendingBids = Bid::where('round_id', $round->id)
            ->where('bid_status', 'pending')
            ->get();

        $released = 0;

        foreach ($pendingBids as $bid) {
            $bid->update(['bid_status' => 'active']);
            $this->grouping->assignOnBid($round, $bid->user_id, $bid->id);
            $released++;
        }

        return $released;
    }
}