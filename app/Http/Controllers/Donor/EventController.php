<?php

namespace App\Http\Controllers\Donor;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Round;
use App\Models\Bid;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class EventController extends Controller
{
    // GET /donor/events?tab=upcoming|finished
    public function index(Request $request)
    {
        $user = $request->user();
        $tab  = $request->query('tab', 'upcoming');

        $statuses = $tab === 'upcoming' ? ['live', 'draft'] : ['finished'];

        $events = Event::whereIn('status', $statuses)
            ->orderByRaw("FIELD(status, 'live', 'draft', 'finished')")
            ->orderBy('started_at', 'asc')
            ->get()
            ->map(fn($e) => $this->formatListItem($e, $user->id));

        return response()->json($events);
    }

    // GET /donor/events/{id}
    public function show(Request $request, $id)
    {
        $event  = Event::findOrFail($id);
        $user   = $request->user();
        $member = $this->findMember((int) $event->id, (int) $user->id);

        $images = $event->images;
        if (is_string($images)) $images = json_decode($images, true) ?? [];

        return response()->json([
            'id'           => $event->id,
            'name'         => $event->name,
            'charity_name' => $event->charity_name,
            'description'  => $event->description ?? '',
            'started_at'   => $event->started_at,
            'status'       => $event->status,
            'logo'         => $event->logo,
            'images'       => $images ?? [],
            'charity_link' => $event->charity_link,
            'rounds_count' => (int) $event->rounds_count,
            'group_size'   => (int) $event->group_size,
            'donors_count' => $this->countDonors((int) $event->id),
            'my_pseudonym' => $member?->pseudonym,
            'my_initial'   => $member ? mb_strtoupper(mb_substr($member->pseudonym, 0, 1)) : null,
            'my_emoji'     => $member?->emoji,
            'is_member'    => (bool) $member,
        ]);
    }

    // GET /donor/events/{id}/group
    public function myGroup(Request $request, int $id): JsonResponse
    {
        $event = Event::findOrFail($id);
        $user  = $request->user();
        $round = $event->currentRound()->first();

        if (!$round) {
            return response()->json([
                'id'             => null,
                'round_number'   => 1,
                'status'         => 'waiting',
                'seconds_left'   => null,
                'matched_amount' => null,
                'match_ratio'    => null,
                'group_total'    => null,
                'group_size'     => (int) $event->group_size,
                'my_group'       => null,
                'my_bid'         => null,
                'my_cumulative'  => 0,
                'round_bids'     => [],
            ]);
        }

        return response()->json($this->formatRoundState($round, $user, $event));
    }

    // GET /events/join/{code}
    public function joinByCode(string $code): JsonResponse
    {
        $event = Event::where('join_code', $code)
            ->select('id', 'name', 'description', 'logo', 'images', 'started_at', 'status')
            ->firstOrFail();

        $alreadyJoined = false;
        if ($user = auth('sanctum')->user()) {
            $alreadyJoined = \App\Models\GroupMember::where('event_id', $event->id)
                ->where('user_id', $user->id)
                ->exists();
        }

        return response()->json([
            'event'          => $event,
            'already_joined' => $alreadyJoined,
        ]);
    }

    // POST /donor/events/{id}/join
    public function join(Request $request, int $id): JsonResponse
    {
        $event = Event::findOrFail($id);
        $user  = $request->user();

        $existing = \App\Models\GroupMember::where('event_id', $id)
            ->where('user_id', $user->id)
            ->first();

        if ($existing) {
            if ($request->filled('pseudonym')) {
                $existing->update(['pseudonym' => $request->pseudonym]);
            }
            return response()->json(['message' => 'Already joined.', 'already_joined' => true]);
        }

        \App\Models\GroupMember::create([
            'event_id'       => $id,
            'group_id'       => null,
            'bid_id'         => null,
            'user_id'        => $user->id,
            'pseudonym'      => $request->filled('pseudonym')
                                    ? $request->pseudonym
                                    : ($user->pseudonym ?? 'Anonymous'),
            'emoji'          => null,
            'payment_status' => 'unpaid',
        ]);

        return response()->json(['success' => true, 'message' => 'Joined successfully.']);
    }

    // ─── Private helpers ─────────────────────────────────────────

    private function findMember(int $eventId, int $userId): ?\App\Models\GroupMember
    {
        return \App\Models\GroupMember::where('event_id', $eventId)
            ->where('user_id', $userId)
            ->first();
    }

    private function countDonors(int $eventId): int
    {
        return \App\Models\GroupMember::where('event_id', $eventId)
            ->distinct('user_id')
            ->count('user_id');
    }

    private function formatListItem(Event $e, int $userId): array
    {
        $images = $e->images;
        if (is_string($images)) $images = json_decode($images, true) ?? [];

        return [
            'id'           => $e->id,
            'name'         => $e->name,
            'charity_name' => $e->charity_name,
            'logo'         => $e->logo,
            'images'       => $images ?? [],
            'status'       => $e->status,
            'started_at'   => $e->started_at,
            'rounds_count' => (int) $e->rounds_count,
            'donors_count' => $this->countDonors((int) $e->id),
            'join_code'    => $e->join_code,
            'is_member'    => (bool) $this->findMember((int) $e->id, $userId),
        ];
    }

    private function formatRoundState(Round $round, $user, Event $event): array
    {
        $myBid   = Bid::where('round_id', $round->id)->where('user_id', $user->id)->first();
        $allBids = Bid::where('round_id', $round->id)->orderBy('amount')->get();

        $secondsLeft = null;
        if ($round->status === 'open' && $round->opened_at) {
            $secondsLeft = (int) $round->opened_at->diffInSeconds(now());
        }

        $roundBids     = [];
        $matchedAmount = null;
        $groupTotal    = null;

        if ($round->status === 'closed' && $allBids->count() > 0) {
            $minAmount     = (int) $allBids->min('amount');
            $matchedAmount = $minAmount;
            $groupTotal    = $allBids->count() * $minAmount;

            foreach ($allBids as $bid) {
                $roundBids[] = [
                    'pseudonym'  => $bid->pseudonym ?? 'Donor',
                    'initial'    => mb_strtoupper(mb_substr($bid->pseudonym ?? 'D', 0, 1)),
                    'amount'     => (int) $bid->amount,
                    'is_you'     => $bid->user_id === $user->id,
                    'is_minimum' => (int) $bid->amount === $minAmount,
                ];
            }
        }

        $cumulative = $this->calcCumulative((int) $event->id, (int) $user->id, $round->round_number);
        $groupSize  = (int) $event->group_size;

        // ── Build my_group ─────────────────────────────────────────────
        //
        // CLOSED round → show THIS round's group (grouping just ran)
        // OPEN round   → show PREVIOUS closed round's group with CURRENT
        //                round bid statuses (shows who you're bidding with)
        // Round 1 open → null (GroupCard shows "Waiting for others")

        $myGroup = null;

        if ($round->status === 'closed') {
            $myGroup = $this->buildMyGroup($event->id, $user->id, $round->id, $round->id);

        } elseif ($round->status === 'open') {
            $prevRound = Round::where('event_id', $event->id)
                ->where('status', 'closed')
                ->orderByDesc('round_number')
                ->first();

            if ($prevRound) {
                // Show prev round's group but mark bid_status against current round
                $myGroup = $this->buildMyGroup(
                    $event->id,
                    $user->id,
                    $prevRound->id,   // group assignment from prev round
                    $round->id        // bid status from current open round
                );
            }
        }

        return [
            'id'             => $round->id,
            'round_number'   => $round->round_number,
            'status'         => $round->status,
            'seconds_left'   => $secondsLeft,
            'matched_amount' => $matchedAmount,
            'match_ratio'    => '1:' . ($groupSize - 1),
            'group_total'    => $groupTotal,
            'group_size'     => $groupSize,
            'my_group'       => $myGroup,
            'my_bid'         => $myBid ? (int) $myBid->amount : null,
            'my_cumulative'  => $cumulative,
            'round_bids'     => $roundBids,
        ];
    }

    /**
     * Builds the my_group payload.
     *
     * @param int $groupRoundId  closed round whose grouping to look up
     * @param int $bidRoundId    round to check bid_status against (may differ during open round)
     */
    private function buildMyGroup(int $eventId, int $userId, int $groupRoundId, int $bidRoundId): ?array
    {
        $member = \App\Models\GroupMember::where('event_id', $eventId)
            ->where('user_id', $userId)
            ->whereHas('group', fn($q) => $q->where('round_id', $groupRoundId))
            ->with(['group.members'])
            ->first();

        if (!$member || !$member->group) {
            return null;
        }

        $group = $member->group;

        // Who in this group has already bid in the current round?
        $bidsThisRound = Bid::where('round_id', $bidRoundId)
            ->whereIn('user_id', $group->members->pluck('user_id'))
            ->pluck('user_id')
            ->toArray();

        $members = $group->members->map(function ($m) use ($userId, $bidsThisRound) {
            return [
                'pseudonym'  => $m->pseudonym ?? 'Donor',
                'initial'    => mb_strtoupper(mb_substr($m->pseudonym ?? 'D', 0, 1)),
                'emoji'      => $m->emoji,
                'is_you'     => $m->user_id === $userId,
                'bid_status' => in_array($m->user_id, $bidsThisRound) ? 'submitted' : 'bidding',
            ];
        })->values()->toArray();

        return [
            'name'    => $group->group_name,
            'members' => $members,
        ];
    }

    private function getMyEventIds(int $userId): array
    {
        return \App\Models\GroupMember::where('user_id', $userId)
            ->pluck('event_id')
            ->unique()
            ->toArray();
    }

    private function calcCumulative(int $eventId, int $userId, int $upToRound): int
    {
        $closedRounds = Round::where('event_id', $eventId)
            ->where('status', 'closed')
            ->where('round_number', '<=', $upToRound)
            ->pluck('id');

        return (int) Bid::whereIn('round_id', $closedRounds)
            ->where('user_id', $userId)
            ->sum('amount');
    }
}