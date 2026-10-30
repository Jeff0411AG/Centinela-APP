<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Livewire\Gate;

use App\Enums\MovementDirection;
use App\Enums\VisitStatusEnum;
use App\Exceptions\MovementConflict;
use App\Livewire\Concerns\RateLimitsSearch;
use App\Livewire\Gate\Concerns\HandlesVehicles;
use App\Models\Absence;
use App\Models\Destination;
use App\Models\Movement;
use App\Models\MovementReason;
use App\Models\User;
use App\Models\Visit;
use App\Models\Visitor;
use App\Models\Worker;
use App\Services\MovementService;
use App\Services\VisitActionService;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * The guard's working screen: find a worker or a visitor, register the exit with one click and the
 * return with another, and see at a glance who is outside and who is absent.
 */
class GatePage extends Component
{
    use AuthorizesRequests;
    use HandlesVehicles;
    use RateLimitsSearch;

    public string $search = '';

    public ?int $workerId = null;

    /** Reason that is waiting for extra data (destination, route...). */
    public ?int $reasonId = null;

    public ?string $destinationId = null;

    public string $routeNumber = '';

    public string $guideNumber = '';

    public string $driverSearch = '';

    public ?int $driverId = null;

    public string $notes = '';

    /** Shows the end-of-shift controls in the "Outside now" panel. */
    public bool $closingShift = false;

    /**
     * @var array{action: string, worker_id: int, reason_id: int|null, conflict: string, since: string|null, absent_until: string|null}|null
     */
    public ?array $pendingConflict = null;

    public ?string $flash = null;

    public function mount(): void
    {
        $this->authorize('create', Movement::class);
    }

    public function updatedSearch(): void
    {
        $this->authorize('create', Movement::class);

        if (mb_strlen(trim($this->search)) >= 2) {
            $this->enforceSearchRateLimit('gate-workers');
        }
    }

    public function updatedDriverSearch(): void
    {
        $this->driverId = null;

        if (mb_strlen(trim($this->driverSearch)) >= 2) {
            $this->enforceSearchRateLimit('gate-drivers');
        }
    }

    public function selectWorker(int $workerId): void
    {
        $this->authorize('create', Movement::class);

        $worker = Worker::query()->active()->find($workerId);

        if ($worker === null) {
            return;
        }

        $this->resetForm();
        $this->resetVehicleForm();
        $this->flash = null;
        $this->workerId = $worker->id;
        $this->search = '';
    }

    /**
     * Enter in the search box picks the first worker match, so a guard can type a DNI and press Enter.
     */
    public function selectFirst(): void
    {
        if (mb_strlen(trim($this->search)) < 2) {
            return;
        }

        $first = Worker::query()->active()->search($this->search)->orderBy('last_name')->orderBy('first_name')->first();

        if ($first !== null) {
            $this->selectWorker($first->id);
        }
    }

    public function clearSelection(): void
    {
        $this->resetForm();
        $this->resetVehicleForm();
        $this->workerId = null;
        $this->search = '';
        $this->dispatch('gate-focus');
    }

    /**
     * Used by the F1..F4 shortcuts: picks the n-th active reason.
     */
    public function chooseReasonAt(int $index): void
    {
        $reason = $this->reasons()->values()->get($index);

        if ($reason !== null) {
            $this->chooseReason($reason->id);
        }
    }

    public function chooseReason(int $reasonId): void
    {
        $this->authorize('create', Movement::class);

        $reason = MovementReason::query()->active()->find($reasonId);

        if ($reason === null || $this->workerId === null) {
            return;
        }

        $this->pendingConflict = null;

        if ($reason->requires_destination || $reason->requires_route) {
            $this->reasonId = $reason->id;
            $this->resetErrorBag();

            return;
        }

        $this->submitExit($reason->id);
    }

    public function cancelReason(): void
    {
        $this->resetForm();
    }

    public function selectDriver(int $workerId): void
    {
        $driver = Worker::query()->active()->find($workerId);

        if ($driver !== null) {
            $this->driverId = $driver->id;
            $this->driverSearch = $driver->full_name;
        }
    }

    public function submitExit(?int $reasonId = null, bool $confirmed = false): void
    {
        $this->authorize('create', Movement::class);

        $worker = $this->workerId ? Worker::query()->active()->find($this->workerId) : null;
        $reason = MovementReason::query()->active()->find($reasonId ?? $this->reasonId);

        if ($worker === null || $reason === null) {
            return;
        }

        try {
            $movement = app(MovementService::class)->registerExit($worker, $reason, auth()->user(), [
                'destination_id' => $this->destinationId,
                'route_number' => $this->routeNumber,
                'driver_worker_id' => $this->driverId,
                'guide_number' => $this->guideNumber,
                'notes' => $this->notes,
            ], $confirmed);
        } catch (MovementConflict $conflict) {
            $this->holdConflict('exit', $worker, $reason->id, $conflict);

            return;
        }

        $this->flash = __(':name: exit registered at :time (:reason).', [
            'name' => $worker->full_name,
            'time' => $movement->occurred_at->format('H:i'),
            'reason' => $reason->name,
        ]);

        $this->clearSelection();
    }

    public function registerReturn(int $workerId, bool $confirmed = false): void
    {
        $this->authorize('create', Movement::class);

        $worker = Worker::query()->active()->find($workerId);

        if ($worker === null) {
            return;
        }

        try {
            $movement = app(MovementService::class)->registerReturn($worker, auth()->user(), null, $confirmed);
        } catch (MovementConflict $conflict) {
            $this->workerId = $worker->id;
            $this->holdConflict('return', $worker, null, $conflict);

            return;
        }

        $this->flash = __(':name: return registered at :time.', [
            'name' => $worker->full_name,
            'time' => $movement->occurred_at->format('H:i'),
        ]);

        $this->clearSelection();
    }

    public function confirmPending(): void
    {
        $pending = $this->pendingConflict;
        $this->pendingConflict = null;

        if ($pending === null) {
            return;
        }

        if ($pending['action'] === 'return') {
            $this->registerReturn($pending['worker_id'], true);

            return;
        }

        $this->submitExit($pending['reason_id'], true);
    }

    public function cancelPending(): void
    {
        $this->pendingConflict = null;
    }

    public function toggleClosingShift(): void
    {
        $this->closingShift = ! $this->closingShift;
    }

    /**
     * Closes an exit that will not be followed by a return ("did not return").
     */
    public function markNoReturn(int $workerId): void
    {
        $this->authorize('create', Movement::class);

        $worker = Worker::query()->find($workerId);

        if ($worker === null) {
            return;
        }

        try {
            app(MovementService::class)->markNoReturn($worker, auth()->user());
        } catch (MovementConflict) {
            return;
        }

        $this->flash = __(':name: marked as did not return.', ['name' => $worker->full_name]);
    }

    /**
     * The worker arrives for the day.
     */
    public function registerArrival(int $workerId): void
    {
        $this->authorize('create', Movement::class);

        $worker = Worker::query()->active()->find($workerId);

        if ($worker === null) {
            return;
        }

        $movement = app(MovementService::class)->registerArrival($worker, auth()->user());

        $this->flash = __(':name: arrival registered at :time.', [
            'name' => $worker->full_name,
            'time' => $movement->occurred_at->format('H:i'),
        ]);

        $this->clearSelection();
    }

    /**
     * The worker leaves for the day: he is no longer inside until he marks again.
     */
    public function registerEndOfShift(int $workerId): void
    {
        $this->authorize('create', Movement::class);

        $worker = Worker::query()->active()->find($workerId);

        if ($worker === null) {
            return;
        }

        app(MovementService::class)->registerEndOfShift($worker, auth()->user());

        $this->flash = __(':name: end of shift registered.', ['name' => $worker->full_name]);

        $this->clearSelection();
    }

    public function markAllNoReturn(): void
    {
        $this->authorize('create', Movement::class);

        $count = 0;

        foreach (Worker::query()->whereIn('id', Movement::query()->outsideNow()->pluck('worker_id'))->get() as $worker) {
            try {
                app(MovementService::class)->markNoReturn($worker, auth()->user());
                $count++;
            } catch (MovementConflict) {
                continue;
            }
        }

        $this->closingShift = false;
        $this->flash = trans_choice(':count person marked as did not return.|:count people marked as did not return.', $count, ['count' => $count]);
    }

    public function visitorCheckIn(int $visitId, int $visitorId): void
    {
        $this->runVisitorAction($visitId, $visitorId, 'checkIn');
    }

    public function visitorCheckOut(int $visitId, int $visitorId): void
    {
        $this->runVisitorAction($visitId, $visitorId, 'checkOut');
    }

    public function render(): View
    {
        $this->authorize('create', Movement::class);

        $term = trim($this->search);
        $today = today();

        $results = mb_strlen($term) >= 2
            ? Worker::query()->active()->search($term)
                ->with(['department', 'latestMovement.reason', 'absences' => fn ($query) => $query->activeOn($today)])
                ->orderBy('last_name')->orderBy('first_name')->limit(8)->get()
            : collect();

        $selected = $this->workerId
            ? Worker::query()->with(['department', 'latestMovement.reason', 'latestMovement.destination', 'absences' => fn ($query) => $query->activeOn($today)])->find($this->workerId)
            : null;

        $driverTerm = trim($this->driverSearch);
        $driverResults = $this->reasonId && $this->driverId === null && mb_strlen($driverTerm) >= 2
            ? Worker::query()->active()->search($driverTerm)->orderBy('last_name')->limit(5)->get()
            : collect();

        return view('livewire.gate.gate-page', $this->vehicleViewData($term) + [
            'results' => $results,
            'visitorResults' => mb_strlen($term) >= 2 ? $this->visitorResults($term) : collect(),
            'canReceiveVisitors' => auth()->user()?->can('CheckIn:Visitor') ?? false,
            'presence' => Worker::query()->active()->with('latestMovement')->get()->countBy(fn (Worker $worker): string => $worker->gateStatus()),
            'selected' => $selected,
            'selectedIsOutside' => $selected?->latestMovement?->direction === MovementDirection::Exit,
            'selectedAbsence' => $selected?->absenceOn($today),
            'reasons' => $this->reasons(),
            'activeReason' => $this->reasonId ? MovementReason::query()->find($this->reasonId) : null,
            'destinations' => $this->destinations(),
            'driverResults' => $driverResults,
            'outside' => Movement::query()->outsideNow()->with(['worker.department', 'reason', 'destination', 'driver'])->orderBy('occurred_at')->get(),
            'today' => Movement::query()->effective()->whereDate('occurred_at', $today)->with(['worker', 'visitor', 'fleetVehicle', 'driver', 'reason', 'destination'])->orderByDesc('occurred_at')->orderByDesc('id')->limit(12)->get(),
            'absentToday' => Absence::query()->activeOn($today)->whereHas('worker', fn ($query) => $query->active())->with('worker')->orderBy('ends_on')->limit(60)->get(),
            'returningSoon' => Absence::query()->endingBetween($today, $today->copy()->addDays(6))->whereHas('worker', fn ($query) => $query->active())->with('worker')->orderBy('ends_on')->limit(60)->get(),
        ])->layout('layouts.app', [
            'header' => new HtmlString(
                view('partials.page-header', ['title' => __('Gate desk')])->render()
            ),
        ]);
    }

    /**
     * Visitors with a planned visit around now whose name or company matches. Check-in and check-out
     * go through the same service as the reception screen, so hosts are still notified.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function visitorResults(string $term): Collection
    {
        $actor = auth()->user();

        if (! $actor instanceof User || (! $actor->can('CheckIn:Visitor') && ! $actor->can('CheckOut:Visitor'))) {
            return collect();
        }

        $now = now();
        $windowEnd = $now->copy()->addHours(max(0, (int) config('reception.check_in_window_hours', 48)));
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
        $matchesTerm = fn ($query) => $query
            ->where('first_name', 'like', $like)
            ->orWhere('name', 'like', $like)
            ->orWhere('company', 'like', $like)
            ->orWhereRaw("concat(first_name, ' ', name) like ?", [$like]);

        return Visit::query()
            ->visibleTo($actor)
            ->where('status', VisitStatusEnum::Planned->value)
            ->where('scheduled_from', '<=', $windowEnd)
            ->where(function ($query) use ($now): void {
                $query->where('scheduled_until', '>=', $now)
                    ->orWhereHas('visitors', fn ($visitors) => $visitors->whereNotNull('visit_visitor.checked_in_at')->whereNull('visit_visitor.checked_out_at'));
            })
            ->whereHas('visitors', fn ($visitors) => $visitors->where($matchesTerm))
            ->with(['host:id,first_name,name', 'visitors'])
            ->orderBy('scheduled_from')
            ->limit(15)
            ->get()
            ->flatMap(fn (Visit $visit) => $visit->visitors
                ->filter(fn (Visitor $visitor): bool => Str::contains(
                    Str::lower(implode(' ', array_filter([$visitor->first_name, $visitor->name, $visitor->company]))),
                    Str::lower($term),
                ))
                ->map(fn (Visitor $visitor): array => [
                    'visit_id' => $visit->id,
                    'visitor_id' => $visitor->id,
                    'name' => trim(($visitor->first_name ?? '').' '.($visitor->name ?? '')),
                    'company' => $visitor->company,
                    'host' => trim(($visit->host?->first_name ?? '').' '.($visit->host?->name ?? '')),
                    'time' => $visit->scheduled_from?->format('H:i'),
                    'inside' => filled($visitor->pivot->checked_in_at) && blank($visitor->pivot->checked_out_at),
                ]))
            ->take(8)
            ->values();
    }

    private function runVisitorAction(int $visitId, int $visitorId, string $action): void
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            abort(403);
        }

        $visit = Visit::query()->findOrFail($visitId);
        $visitor = Visitor::query()->findOrFail($visitorId);

        $this->authorize('view', $visit);
        $this->authorize($action, Visitor::class);

        $service = app(VisitActionService::class);

        try {
            $action === 'checkIn'
                ? $service->checkInParticipant($visit, $visitor, $actor)
                : $service->checkOutParticipant($visit, $visitor, $actor);
        } catch (ValidationException $exception) {
            $this->flash = null;
            $this->addError('visit', $exception->validator->errors()->first());

            return;
        }

        $name = trim(($visitor->first_name ?? '').' '.($visitor->name ?? ''));
        $this->flash = $action === 'checkIn'
            ? __(':name: visitor check-in registered at :time.', ['name' => $name, 'time' => now()->format('H:i')])
            : __(':name: visitor check-out registered at :time.', ['name' => $name, 'time' => now()->format('H:i')]);

        $this->clearSelection();
    }

    private function holdConflict(string $action, Worker $worker, ?int $reasonId, MovementConflict $conflict): void
    {
        $this->pendingConflict = [
            'action' => $action,
            'worker_id' => $worker->id,
            'reason_id' => $reasonId,
            'conflict' => $conflict->conflict,
            'since' => $conflict->lastMovement?->occurred_at?->format('H:i'),
            'absent_until' => $conflict->absentUntil?->format('d/m/Y'),
        ];
    }

    /**
     * @return Collection<int, MovementReason>
     */
    private function reasons(): Collection
    {
        return MovementReason::query()->active()->forWorkers()->where('expects_return', true)->ordered()->get();
    }

    /**
     * Destinations used most in the last two months first, then the rest alphabetically.
     *
     * @return Collection<int, Destination>
     */
    private function destinations(): Collection
    {
        $recentIds = Movement::query()
            ->whereNotNull('destination_id')
            ->where('occurred_at', '>=', now()->subDays(60))
            ->groupBy('destination_id')
            ->orderByRaw('count(*) desc')
            ->limit(5)
            ->pluck('destination_id');

        [$recent, $rest] = Destination::query()->active()->orderBy('name')->get()
            ->partition(fn (Destination $destination): bool => $recentIds->contains($destination->id));

        return $recent->sortBy(fn (Destination $destination): int => (int) $recentIds->search($destination->id))->concat($rest)->values();
    }

    private function resetForm(): void
    {
        $this->reasonId = null;
        $this->destinationId = null;
        $this->routeNumber = '';
        $this->guideNumber = '';
        $this->driverSearch = '';
        $this->driverId = null;
        $this->notes = '';
        $this->pendingConflict = null;
        $this->resetErrorBag();
    }
}
