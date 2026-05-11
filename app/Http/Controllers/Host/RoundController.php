<?php

namespace App\Http\Controllers\Host;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Round;
use App\Services\GroupingService;
use Illuminate\Http\Request;

class RoundController extends Controller
{
    protected GroupingService $grouping;

    public function __construct(GroupingService $grouping)
    {
        $this->grouping = $grouping;
    }

    // POST /api/host/events/{id}/rounds/start
    public function start(Request $request, $id)
    {
        $event = Event::where('host_id', $request->user()->id)
            ->findOrFail($id);

        // Check if there's already an open round
        $alreadyOpen = $event->rounds()->where('status', 'open')->exists();
        if ($alreadyOpen) {
            return response()->json(['message' => 'A round is already open.'], 422);
        }

        $roundNumber = $event->rounds()->count() + 1;

        if ($roundNumber > $event->rounds_count) {
            return response()->json(['message' => 'All rounds completed.'], 422);
        }

        $round = Round::create([
            'event_id'     => $event->id,
            'round_number' => $roundNumber,
            'status'       => 'open',
            'opened_at'    => now(),
            'closed_at'    => null,
        ]);

        return response()->json($round, 201);
    }

    // POST /api/host/events/{id}/rounds/{roundId}/end
    public function end(Request $request, $id, $roundId)
    {
        $event = Event::where('host_id', $request->user()->id)
            ->findOrFail($id);

        $round = Round::where('event_id', $event->id)
            ->findOrFail($roundId);

        if ($round->status !== 'open') {
            return response()->json(['message' => 'Round is not open.'], 422);
        }

        // Close the round
        $round->update([
            'status'    => 'closed',
            'closed_at' => now(),
        ]);

        // Run grouping algorithm — randomly assigns all bidders into groups
        //$groups = $this->grouping->formGroups($round, $event->group_size);

        return response()->json([
            'round'  => $round,
            //'groups' => $groups,
        ]);
    }

    // GET /api/host/events/{id}/rounds
    public function index(Request $request, $id)
    {
        $event = Event::where('host_id', $request->user()->id)
            ->findOrFail($id);

        $rounds = $event->rounds()
            ->with(['bids.user', 'groups.members.bid.user'])
            ->get();

        return response()->json($rounds);
    }
}