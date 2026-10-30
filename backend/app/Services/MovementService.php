<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Services;

use App\Enums\MovementDirection;
use App\Exceptions\MovementConflict;
use App\Models\Movement;
use App\Models\MovementReason;
use App\Models\User;
use App\Models\Visit;
use App\Models\Visitor;
use App\Models\Worker;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Writes gate marks for workers. Every method locks the worker row first so two guards
 * clicking at the same time cannot both see the same "last mark".
 */
class MovementService
{
    /**
     * @param  array{destination_id?: int|string|null, route_number?: string|null, driver_worker_id?: int|string|null, guide_number?: string|null, notes?: string|null}  $data
     *
     * @throws MovementConflict when the worker is absent today or already figures as outside, and $confirmed is false
     * @throws ValidationException when the reason needs data that is missing
     */
    public function registerExit(Worker $worker, MovementReason $reason, User $actor, array $data = [], bool $confirmed = false): Movement
    {
        if (! $reason->is_active) {
            throw new InvalidArgumentException('The movement reason is not active.');
        }

        $this->validateReasonData($reason, $data);

        return DB::transaction(function () use ($worker, $reason, $actor, $data, $confirmed): Movement {
            $last = $this->lockedLastMovement($worker);

            if (! $confirmed && ($absence = $worker->absenceOn()) !== null) {
                throw new MovementConflict(MovementConflict::ABSENT, $last, $absence->ends_on);
            }

            if ($last?->direction === MovementDirection::Exit && ! $confirmed) {
                throw new MovementConflict(MovementConflict::ALREADY_OUTSIDE, $last);
            }

            return Movement::query()->create([
                'worker_id' => $worker->id,
                'direction' => MovementDirection::Exit,
                'reason_id' => $reason->id,
                'destination_id' => $this->filled($data['destination_id'] ?? null),
                'route_number' => $this->text($data['route_number'] ?? null, 20),
                'driver_worker_id' => $this->filled($data['driver_worker_id'] ?? null),
                'guide_number' => $this->text($data['guide_number'] ?? null, 30),
                'notes' => $this->text($data['notes'] ?? null, 500),
                'registered_by_user_id' => $actor->id,
            ]);
        });
    }

    /**
     * Closes the open exit of the worker. When there is none the mark is refused unless confirmed,
     * because it usually means an exit was never written down.
     *
     * @throws MovementConflict
     */
    public function registerReturn(Worker $worker, User $actor, ?string $notes = null, bool $confirmed = false): Movement
    {
        return DB::transaction(function () use ($worker, $actor, $notes, $confirmed): Movement {
            $last = $this->lockedLastMovement($worker);

            $exitId = match ($last?->direction) {
                MovementDirection::Exit => $last->id,
                MovementDirection::NoReturn => $last->exit_movement_id,
                default => null,
            };

            if ($exitId === null && ! $confirmed) {
                throw new MovementConflict(MovementConflict::NOT_OUTSIDE, $last);
            }

            return Movement::query()->create([
                'worker_id' => $worker->id,
                'direction' => MovementDirection::Entry,
                'reason_id' => $last?->reason_id,
                'exit_movement_id' => $exitId,
                'notes' => $this->text($notes, 500) ?? ($exitId === null ? __('Entry without a previous exit on record') : null),
                'registered_by_user_id' => $actor->id,
            ]);
        });
    }

    /**
     * Closes an exit that will not be followed by a return today ("no retornó").
     *
     * @throws MovementConflict when the worker is not outside
     */
    public function markNoReturn(Worker $worker, User $actor, ?string $notes = null): Movement
    {
        return DB::transaction(function () use ($worker, $actor, $notes): Movement {
            $last = $this->lockedLastMovement($worker);

            if ($last?->direction !== MovementDirection::Exit) {
                throw new MovementConflict(MovementConflict::NOT_OUTSIDE, $last);
            }

            return Movement::query()->create([
                'worker_id' => $worker->id,
                'direction' => MovementDirection::NoReturn,
                'reason_id' => $last->reason_id,
                'exit_movement_id' => $last->id,
                'notes' => $this->text($notes, 500),
                'registered_by_user_id' => $actor->id,
            ]);
        });
    }

    /**
     * The worker arrives for the day. When he figures as outside this is his return; when he already
     * arrived today nothing new is written (a double click must not duplicate the mark); otherwise it is
     * a stand-alone entry with the "arrival" reason, which the trip reports do not treat as a forgotten exit.
     */
    public function registerArrival(Worker $worker, User $actor, ?string $notes = null): Movement
    {
        return DB::transaction(function () use ($worker, $actor, $notes): Movement {
            $last = $this->lockedLastMovement($worker);

            if ($last?->direction === MovementDirection::Exit) {
                return $this->registerReturn($worker, $actor, $notes);
            }

            if ($last?->direction === MovementDirection::Entry && $last->occurred_at->isToday()) {
                return $last;
            }

            return Movement::query()->create([
                'worker_id' => $worker->id,
                'direction' => MovementDirection::Entry,
                'reason_id' => MovementReason::query()->where('code', 'arrival')->value('id'),
                'notes' => $this->text($notes, 500),
                'registered_by_user_id' => $actor->id,
            ]);
        });
    }

    /**
     * The worker leaves for the day. When he figures as outside this closes that exit as "did not return";
     * otherwise it writes a stand-alone "did not return" mark with the "end of shift" reason, which the trip
     * reports ignore because there is no exit to pair it with.
     */
    public function registerEndOfShift(Worker $worker, User $actor, ?string $notes = null): Movement
    {
        return DB::transaction(function () use ($worker, $actor, $notes): Movement {
            $last = $this->lockedLastMovement($worker);

            if ($last?->direction === MovementDirection::Exit) {
                return $this->markNoReturn($worker, $actor, $notes);
            }

            return Movement::query()->create([
                'worker_id' => $worker->id,
                'direction' => MovementDirection::NoReturn,
                'reason_id' => MovementReason::query()->where('code', 'end_of_shift')->value('id'),
                'notes' => $this->text($notes, 500),
                'registered_by_user_id' => $actor->id,
            ]);
        });
    }

    /**
     * Mirrors a visitor's check-in or check-out in the gate log, so the log holds every person who
     * crosses the gate, whichever screen the guard used. Called by the visit check-in service.
     */
    public function recordVisitorMark(Visit $visit, Visitor $visitor, MovementDirection $direction, User $actor): Movement
    {
        return Movement::query()->create([
            'visitor_id' => $visitor->id,
            'visit_id' => $visit->id,
            'site_id' => $visit->site_id,
            'direction' => $direction,
            'registered_by_user_id' => $actor->id,
        ]);
    }

    /**
     * Replaces a mark with a corrected copy. The original is never touched: the copy points at it and
     * carries the written reason, so the log shows what was corrected, by whom and why.
     *
     * @param  array{direction?: MovementDirection|string, occurred_at?: CarbonInterface|string, reason_id?: int|string|null, destination_id?: int|string|null, route_number?: string|null, driver_worker_id?: int|string|null, copilot_worker_id?: int|string|null, guide_number?: string|null, odometer_km?: int|string|null, checklist?: array<string, string|null>|null, notes?: string|null}  $changes
     *
     * @throws InvalidArgumentException when the mark no longer counts, nothing changes or the reason is missing
     */
    public function correct(Movement $original, array $changes, string $reason, User $actor): Movement
    {
        $reason = $this->requiredText($reason, __('Write the reason for the correction.'));

        return DB::transaction(function () use ($original, $changes, $reason, $actor): Movement {
            $current = $this->lockedForCorrection($original);

            $direction = isset($changes['direction']) ? MovementDirection::from($changes['direction'] instanceof MovementDirection ? $changes['direction']->value : $changes['direction']) : $current->direction;

            if ($direction === MovementDirection::Void) {
                throw new InvalidArgumentException(__('Use "Void" to annul a mark.'));
            }

            $occurredAt = isset($changes['occurred_at']) ? Carbon::parse($changes['occurred_at']) : $current->occurred_at;

            // The time pickers work in minutes: the same minute means the guard's original time stays.
            if ($occurredAt->format('Y-m-d H:i') === $current->occurred_at->format('Y-m-d H:i')) {
                $occurredAt = $current->occurred_at;
            }

            if ($occurredAt->greaterThan(now()->addMinute())) {
                throw new InvalidArgumentException(__('The time cannot be in the future.'));
            }

            $attributes = [
                'worker_id' => $current->worker_id,
                'visitor_id' => $current->visitor_id,
                'visit_id' => $current->visit_id,
                'fleet_vehicle_id' => $current->fleet_vehicle_id,
                'site_id' => $current->site_id,
                'direction' => $direction,
                'occurred_at' => $occurredAt,
                'reason_id' => array_key_exists('reason_id', $changes) ? $this->filled($changes['reason_id']) : $current->reason_id,
                'destination_id' => array_key_exists('destination_id', $changes) ? $this->filled($changes['destination_id']) : $current->destination_id,
                'route_number' => array_key_exists('route_number', $changes) ? $this->text($changes['route_number'], 20) : $current->route_number,
                'driver_worker_id' => array_key_exists('driver_worker_id', $changes) ? $this->filled($changes['driver_worker_id']) : $current->driver_worker_id,
                'guide_number' => array_key_exists('guide_number', $changes) ? $this->text($changes['guide_number'], 30) : $current->guide_number,
                'notes' => array_key_exists('notes', $changes) ? $this->text($changes['notes'], 500) : $current->notes,
                'copilot_worker_id' => array_key_exists('copilot_worker_id', $changes) ? $this->filled($changes['copilot_worker_id']) : $current->copilot_worker_id,
                'odometer_km' => array_key_exists('odometer_km', $changes) ? ($this->filled($changes['odometer_km']) === null ? null : (int) $changes['odometer_km']) : $current->odometer_km,
                'checklist' => array_key_exists('checklist', $changes) ? $changes['checklist'] : $current->checklist,
                'exit_movement_id' => $current->exit_movement_id,
            ];

            $comparable = fn (mixed $value): string => match (true) {
                $value instanceof CarbonInterface => $value->format('Y-m-d H:i:s'),
                $value instanceof MovementDirection => $value->value,
                is_array($value) => (string) json_encode(collect($value)->sortKeys()->all()),
                default => (string) $value,
            };

            $unchanged = collect($attributes)->every(fn (mixed $value, string $key): bool => $comparable($value) === $comparable($current->{$key}));

            if ($unchanged) {
                throw new InvalidArgumentException(__('Nothing was changed.'));
            }

            $timeChanged = ! $occurredAt->equalTo($current->occurred_at);

            return Movement::query()->create($attributes + [
                'registered_by_user_id' => $actor->id,
                'corrects_movement_id' => $current->id,
                'correction_reason' => $reason,
                'manual_time' => $timeChanged,
                'manual_time_reason' => $timeChanged ? $reason : null,
            ]);
        });
    }

    /**
     * Annuls a mark that should never have existed (for example one made for the wrong person).
     * The original stays in the log, struck through, next to the void mark that explains it.
     *
     * @throws InvalidArgumentException when the mark no longer counts or the reason is missing
     */
    public function void(Movement $original, string $reason, User $actor): Movement
    {
        $reason = $this->requiredText($reason, __('Write the reason for voiding the mark.'));

        return DB::transaction(function () use ($original, $reason, $actor): Movement {
            $current = $this->lockedForCorrection($original);

            return Movement::query()->create([
                'worker_id' => $current->worker_id,
                'visitor_id' => $current->visitor_id,
                'visit_id' => $current->visit_id,
                'fleet_vehicle_id' => $current->fleet_vehicle_id,
                'site_id' => $current->site_id,
                'direction' => MovementDirection::Void,
                'registered_by_user_id' => $actor->id,
                'corrects_movement_id' => $current->id,
                'correction_reason' => $reason,
            ]);
        });
    }

    /**
     * Writes a mark that was forgotten, with the real time. Only for supervisors: the written reason
     * is mandatory and the mark is flagged as having a manual time.
     *
     * @param  array{reason_id?: int|string|null, destination_id?: int|string|null, route_number?: string|null, driver_worker_id?: int|string|null, guide_number?: string|null, notes?: string|null}  $data
     *
     * @throws InvalidArgumentException
     */
    public function registerManual(Worker $worker, MovementDirection $direction, CarbonInterface $at, string $reason, User $actor, array $data = []): Movement
    {
        $reason = $this->requiredText($reason, __('Write the reason for the manual time.'));

        if ($direction === MovementDirection::Void) {
            throw new InvalidArgumentException(__('Use "Void" to annul a mark.'));
        }

        if ($at->greaterThan(now()->addMinute())) {
            throw new InvalidArgumentException(__('The time cannot be in the future.'));
        }

        return DB::transaction(function () use ($worker, $direction, $at, $reason, $actor, $data): Movement {
            Worker::query()->whereKey($worker->id)->lockForUpdate()->first();

            $exitId = null;

            if ($direction === MovementDirection::Entry || $direction === MovementDirection::NoReturn) {
                $lastBefore = Movement::query()->effective()
                    ->where('worker_id', $worker->id)
                    ->where('occurred_at', '<=', $at)
                    ->orderByDesc('occurred_at')->orderByDesc('id')
                    ->first();

                $exitId = $lastBefore?->direction === MovementDirection::Exit ? $lastBefore->id : null;
            }

            return Movement::query()->create([
                'worker_id' => $worker->id,
                'direction' => $direction,
                'occurred_at' => $at,
                'reason_id' => $this->filled($data['reason_id'] ?? null),
                'destination_id' => $this->filled($data['destination_id'] ?? null),
                'route_number' => $this->text($data['route_number'] ?? null, 20),
                'driver_worker_id' => $this->filled($data['driver_worker_id'] ?? null),
                'guide_number' => $this->text($data['guide_number'] ?? null, 30),
                'notes' => $this->text($data['notes'] ?? null, 500),
                'exit_movement_id' => $exitId,
                'registered_by_user_id' => $actor->id,
                'manual_time' => true,
                'manual_time_reason' => $reason,
            ]);
        });
    }

    private function lockedForCorrection(Movement $original): Movement
    {
        $current = Movement::query()->lockForUpdate()->findOrFail($original->id);

        if (! $current->isEffective()) {
            throw new InvalidArgumentException(__('This mark was already corrected or voided.'));
        }

        return $current;
    }

    private function requiredText(string $value, string $message): string
    {
        $value = trim($value);

        if ($value === '') {
            throw new InvalidArgumentException($message);
        }

        return mb_substr($value, 0, 500);
    }

    private function lockedLastMovement(Worker $worker): ?Movement
    {
        Worker::query()->whereKey($worker->id)->lockForUpdate()->first();

        return Movement::query()
            ->effective()
            ->where('worker_id', $worker->id)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function validateReasonData(MovementReason $reason, array $data): void
    {
        $errors = [];

        if ($reason->requires_destination && blank($data['destination_id'] ?? null)) {
            $errors['destinationId'] = __('Select a destination.');
        }

        if ($reason->requires_route) {
            if (blank($data['route_number'] ?? null)) {
                $errors['routeNumber'] = __('Enter the route number.');
            }

            if (blank($data['driver_worker_id'] ?? null)) {
                $errors['driverId'] = __('Select the driver.');
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function filled(mixed $value): int|string|null
    {
        return blank($value) ? null : $value;
    }

    private function text(?string $value, int $max): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
