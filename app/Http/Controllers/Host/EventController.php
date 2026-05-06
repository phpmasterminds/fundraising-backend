<?php

namespace App\Http\Controllers\Host;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Round;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Storage;

class EventController extends Controller
{
    // GET /api/host/events
    public function index(Request $request)
    {
        $events = Event::where('host_id', $request->user()->id)
            ->withCount('rounds')
            ->latest()
            ->get()
            ->map(fn($e) => array_merge($e->toArray(), [
                'total_raised' => $e->total_raised,
                'donors_count' => $e->rounds()
                    ->with('bids')
                    ->get()
                    ->pluck('bids')
                    ->flatten()
                    ->pluck('user_id')
                    ->unique()
                    ->count(),
            ]));

        return response()->json($events);
    }

    // POST /api/host/events
    public function store(Request $request)
    {
        $request->validate([
            'name'          => 'required|string|max:255',
            'charity_name'  => 'required|string|max:255',
            'description'   => 'nullable|string',
            'target_amount' => 'required|numeric|min:1',
            'rounds_count'  => 'required|integer|min:1|max:10',
            'group_size'    => 'required|integer|min:2|max:10',
            'started_at'    => 'nullable|date',
            'charity_link'  => 'nullable|string',
            'logo'          => 'nullable|image|max:2048',
            'images.*'      => 'nullable|image|max:2048',
        ]);

        $logoPath = null;
        if ($request->hasFile('logo')) {
            $logoPath = $request->file('logo')->store('events/logos', 'public');
        }

        $imagePaths = [];
        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $img) {
                $imagePaths[] = $img->store('events/images', 'public');
            }
        }

        $joinCode = $this->generateJoinCode();
        $joinUrl  = config('app.frontend_url') . '/join?code=' . $joinCode;

        $qrFilename = 'events/qrcodes/' . $joinCode . '.svg';
        Storage::disk('public')->makeDirectory('events/qrcodes');

        $renderer = new ImageRenderer(new RendererStyle(300), new SvgImageBackEnd());
        $writer   = new Writer($renderer);
        Storage::disk('public')->put($qrFilename, $writer->writeString($joinUrl));

        $event = Event::create([
            'host_id'       => $request->user()->id,
            'name'          => $request->name,
            'charity_name'  => $request->charity_name,
            'description'   => $request->description,
            'target_amount' => $request->target_amount,
            'rounds_count'  => $request->rounds_count,
            'group_size'    => $request->group_size,
            'started_at'    => $request->started_at,
            'charity_link'  => $request->charity_link,
            'logo'          => $logoPath,
            'images'        => !empty($imagePaths) ? $imagePaths : null,
            'join_code'     => $joinCode,
            'qr_code'       => $qrFilename,
            'status'        => 'draft',
        ]);

        return response()->json($event, 201);
    }

    // GET /api/host/events/{id}
    public function show(Request $request, $id)
    {
        $event = Event::where('host_id', $request->user()->id)
            ->with(['rounds.bids', 'rounds.groups.members.bid'])
            ->findOrFail($id);

        $currentRound    = $event->rounds
            ->whereIn('status', ['waiting', 'open'])
            ->sortBy('round_number')
            ->first();

        $completedRounds = $event->rounds->where('status', 'closed')->count();

        // ── Rounds overview ──────────────────────────────────────────
        $roundsOverview = $event->rounds->map(function ($round) {
            $groupRows = $round->groups->map(function ($group) {
                return [
                    'name'         => $group->group_name,
                    'status'       => 'done',
                    'alert'        => false,
                    'detail'       => '£' . number_format($group->min_amount, 0) . ' min · ' . $group->match_ratio,
                    'detail_color' => '#2BA7A0',
                ];
            })->values()->toArray();

            return [
                'id'           => $round->id,
                'round_number' => $round->round_number,
                'status'       => $round->status,
                'raised'       => $round->status === 'closed'
                    ? '£' . number_format($round->bids->sum('amount'), 0)
                    : null,
                'alerts'       => null,
                'groups_done'  => $round->groups->count() . '/' . $round->groups->count(),
                'group_rows'   => $groupRows,
                'opened_at'    => $round->opened_at,
                'closed_at'    => $round->closed_at,
            ];
        })->values();

        // ── Current groups ────────────────────────────────────────────
        // Show formed groups if round closed, or flat bid list if open
        $currentGroups = [];

        if ($currentRound) {
            if ($currentRound->status === 'open') {
                // Round open — show flat bid list (groups not formed yet)
                $donors = $currentRound->bids->map(function ($bid) {
                    return [
                        'pseudonym'       => $bid->pseudonym ?? '—',
                        'initial'         => strtoupper(substr($bid->pseudonym ?? '?', 0, 1)),
                        'bid_amount'      => $bid->amount ? '£' . number_format($bid->amount, 0) : null,
                        'is_quit'         => false,
                        'total_committed' => null,
                    ];
                })->values();

                if ($donors->count() > 0) {
                    $currentGroups = [[
                        'name'       => 'Round ' . $currentRound->round_number . ' Bids',
                        'bids'       => $donors->count(),
                        'total_bids' => $donors->count(),
                        'min'        => null,
                        'alert'      => false,
                        'status'     => 'pending',
                        'donors'     => $donors,
                    ]];
                }
            }
        } else {
            // Show groups from the latest closed round
            $lastClosedRound = $event->rounds
                ->where('status', 'closed')
                ->sortByDesc('round_number')
                ->first();

            if ($lastClosedRound) {
                foreach ($lastClosedRound->groups as $group) {
                    $members = $group->members->map(function ($member) {
                        return [
                            'pseudonym'       => $member->pseudonym ?? '—',
                            'initial'         => strtoupper(substr($member->pseudonym ?? '?', 0, 1)),
                            'bid_amount'      => $member->bid ? '£' . number_format($member->bid->amount, 0) : null,
                            'is_quit'         => $member->is_quit ?? false,
                            'total_committed' => null,
                        ];
                    })->values();

                    $currentGroups[] = [
                        'name'       => $group->group_name,
                        'bids'       => $group->members->count(),
                        'total_bids' => $group->members->count(),
                        'min'        => '£' . number_format($group->min_amount, 0),
                        'alert'      => false,
                        'status'     => 'done',
                        'donors'     => $members,
                    ];
                }
            }
        }

        // ── All donors across event ────────────────────────────────────
        $allDonors = $event->rounds
            ->flatMap(fn($r) => $r->bids)
            ->unique('user_id')
            ->values()
            ->map(fn($bid) => [
                'pseudonym'  => $bid->pseudonym ?? '—',
                'initial'    => strtoupper(substr($bid->pseudonym ?? '?', 0, 1)),
                'bid_amount' => $bid->amount ? '£' . number_format($bid->amount, 0) : null,
                'is_quit'    => false,
            ]);

        return response()->json([
            'id'            => $event->id,
            'name'          => $event->name,
            'charity_name'  => $event->charity_name,
            'description'   => $event->description,
            'logo'          => $event->logo,
            'target_amount' => $event->target_amount,
            'join_code'     => $event->join_code,
            'status'        => $event->status,
            'rounds_count'  => $event->rounds_count,
            'group_size'    => $event->group_size,
            'charity_link'  => $event->charity_link,
            'started_at'    => $event->started_at,
            'ended_at'      => $event->ended_at ?? null,
            'total_raised'  => $event->total_raised,
            'donors_count'  => $allDonors->count(),
            'current_round_number' => $currentRound?->round_number ?? null,
            'completed_rounds'     => $completedRounds,
            'current_round_timer'  => $currentRound?->opened_at ? [
                'human'   => $currentRound->opened_at->diffForHumans(),
                'seconds' => (int) $currentRound->opened_at->diffInSeconds(now()),
            ] : null,
            'round_progress'  => $currentRound
                ? '0/' . $currentRound->bids->count() . ' Complete'
                : null,
            'active_alert'    => null,
            'current_groups'  => $currentGroups,
            'rounds_overview' => $roundsOverview,
            'all_donors'      => $allDonors,
        ]);
    }

    // PUT /api/host/events/{id}
    public function update(Request $request, $id)
    {
        $event = Event::where('host_id', $request->user()->id)->findOrFail($id);
        $event->update($request->only([
            'name', 'charity_name', 'description',
            'target_amount', 'rounds_count', 'group_size',
            'started_at', 'charity_link',
        ]));
        return response()->json($event);
    }

    // DELETE /api/host/events/{id}
    public function destroy(Request $request, $id)
    {
        $event = Event::where('host_id', $request->user()->id)->findOrFail($id);
        $event->delete();
        return response()->json(['message' => 'Event deleted']);
    }

    // POST /api/host/events/{id}/start
    public function start(Request $request, $id)
    {
        $event = Event::where('host_id', $request->user()->id)->findOrFail($id);
        $event->update([
            'status'     => 'live',
            'started_at' => $event->started_at ?? now(),
        ]);

        // If started_at already passed and no rounds exist, open Round 1 immediately
        if ($event->started_at <= now() && $event->rounds()->count() === 0) {
            Round::create([
                'event_id'     => $event->id,
                'round_number' => 1,
                'status'       => 'open',
                'opened_at'    => now(),
            ]);
        }

        return response()->json($event->fresh()->load('rounds'));
    }

    // POST /api/host/events/{id}/end
    public function end(Request $request, $id)
    {
        $event = Event::where('host_id', $request->user()->id)->findOrFail($id);
        $event->update(['status' => 'finished', 'ended_at' => now()]);
        return response()->json($event);
    }

    // GET /api/host/events/{id}/donors
    public function donors(Request $request, $id)
    {
        $event = Event::where('host_id', $request->user()->id)->findOrFail($id);
        $donors = $event->rounds()
            ->with('bids.user')
            ->get()
            ->pluck('bids')
            ->flatten()
            ->unique('user_id')
            ->values();
        return response()->json($donors);
    }

    private function generateJoinCode(): string
    {
        do {
            $code = strtoupper(Str::random(8));
        } while (Event::where('join_code', $code)->exists());
        return $code;
    }
}