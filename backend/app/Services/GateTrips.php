<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Services;

use App\Enums\MovementDirection;
use App\Models\Movement;
use App\Support\Trip;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Builds the trips (exit plus return) of the workers and of the company vehicles from the marks that count.
 *
 * It pairs each subject's marks in time order instead of following exit_movement_id: a correction
 * replaces a mark with a new row, so a return can still point at an exit that no longer counts,
 * while the time order of the effective marks is always right.
 */
class GateTrips
{
    /** Marks after the range are read too, so an exit on the last day can find its return. */
    private const LOOK_AHEAD_DAYS = 3;

    /**
     * @return array{trips: Collection<int, Trip>, orphanEntries: Collection<int, Movement>}
     */
    public function between(CarbonInterface $from, CarbonInterface $until, ?int $departmentId = null): array
    {
        [$start, $end] = $this->bounds($from, $until);

        $marks = Movement::query()
            ->effective()
            ->whereNotNull('worker_id')
            ->whereBetween('occurred_at', [$start->copy()->subDay(), $end->copy()->addDays(self::LOOK_AHEAD_DAYS)])
            ->when($departmentId, fn ($query) => $query->whereHas('worker', fn ($worker) => $worker->where('department_id', $departmentId)))
            ->with(['worker.department', 'reason', 'destination', 'driver', 'registeredBy'])
            ->orderBy('worker_id')
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get()
            ->groupBy('worker_id');

        return $this->pair($marks, $start, $end);
    }

    /**
     * The same for the company vehicles, grouped by vehicle.
     *
     * @return array{trips: Collection<int, Trip>, orphanEntries: Collection<int, Movement>}
     */
    public function vehiclesBetween(CarbonInterface $from, CarbonInterface $until): array
    {
        [$start, $end] = $this->bounds($from, $until);

        $marks = Movement::query()
            ->effective()
            ->whereNotNull('fleet_vehicle_id')
            ->whereBetween('occurred_at', [$start->copy()->subDay(), $end->copy()->addDays(self::LOOK_AHEAD_DAYS)])
            ->with(['fleetVehicle', 'reason', 'driver', 'copilot', 'registeredBy'])
            ->orderBy('fleet_vehicle_id')
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get()
            ->groupBy('fleet_vehicle_id');

        return $this->pair($marks, $start, $end);
    }

    /**
     * @return array{0: CarbonInterface, 1: CarbonInterface}
     */
    private function bounds(CarbonInterface $from, CarbonInterface $until): array
    {
        return [$from->copy()->startOfDay(), $until->copy()->endOfDay()];
    }

    /**
     * @param  Collection<int|string, Collection<int, Movement>>  $marksBySubject  each group in time order
     * @return array{trips: Collection<int, Trip>, orphanEntries: Collection<int, Movement>}
     */
    private function pair(Collection $marksBySubject, CarbonInterface $start, CarbonInterface $end): array
    {
        $trips = collect();
        $orphans = collect();

        foreach ($marksBySubject as $subjectMarks) {
            $open = null;

            foreach ($subjectMarks as $mark) {
                switch ($mark->direction) {
                    case MovementDirection::Exit:
                        if ($open !== null) {
                            $trips->push(new Trip($open, null, Trip::UNCLOSED));
                        }
                        $open = $mark;
                        break;

                    case MovementDirection::Entry:
                        if ($open !== null) {
                            $trips->push(new Trip($open, $mark, Trip::CLOSED));
                            $open = null;
                        } elseif ($mark->reason?->code !== 'arrival') {
                            $orphans->push($mark);
                        }
                        break;

                    case MovementDirection::NoReturn:
                        if ($open !== null) {
                            $trips->push(new Trip($open, $mark, Trip::NO_RETURN));
                            $open = null;
                        }
                        break;

                    default:
                        break;
                }
            }

            if ($open !== null) {
                $trips->push(new Trip($open, null, Trip::OPEN));
            }
        }

        return [
            'trips' => $trips
                ->filter(fn (Trip $trip): bool => $trip->exit->occurred_at->betweenIncluded($start, $end))
                ->sortBy(fn (Trip $trip): string => $trip->exit->occurred_at->format('Y-m-d H:i:s').str_pad((string) $trip->exit->id, 10, '0', STR_PAD_LEFT))
                ->values(),
            'orphanEntries' => $orphans
                ->filter(fn (Movement $mark): bool => $mark->occurred_at->betweenIncluded($start, $end))
                ->sortBy('occurred_at')
                ->values(),
        ];
    }
}
