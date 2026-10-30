<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Services;

use App\Enums\MovementDirection;
use App\Models\Movement;
use App\Support\Plate;
use App\Support\Trip;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * The fleet half of the gate reports. Like the people's, every section is a plain table (title, headers,
 * rows) that feeds both the screen and the Excel workbook, and trips are paired from the marks that count.
 */
class FleetReports
{
    public function __construct(private readonly GateTrips $tripBuilder) {}

    /**
     * @return array<string, array{title: string, headers: list<string>, rows: list<list<mixed>>}>
     */
    public function build(CarbonInterface $from, CarbonInterface $until): array
    {
        ['trips' => $trips] = $this->tripBuilder->vehiclesBetween($from, $until);
        $start = $from->copy()->startOfDay();
        $end = $until->copy()->endOfDay();

        return [
            'fleet_by_vehicle' => $this->section(
                __('Fleet by vehicle'),
                [__('Plate'), __('Vehicle'), __('Exits'), __('Returned'), __('Never closed'), __('Still outside'), __('Distance (km)'), __('Average per trip (km)'), __('Total time (min)')],
                $this->byVehicleRows($trips),
            ),
            'fleet_by_driver' => $this->section(
                __('Fleet by driver'),
                [__('Driver'), __('Document number'), __('Exits'), __('Distance (km)'), __('Total time (min)'), __('Average (min)')],
                $this->byDriverRows($trips),
            ),
            'fleet_odometer' => $this->section(
                __('Odometer readings that do not match'),
                [__('Date / time'), __('Plate'), __('Driver'), __('Problem'), __('Expected (km)'), __('Recorded (km)'), __('Difference (km)'), __('Notizen')],
                $this->odometerRows($start, $end),
            ),
            'fleet_checklist' => $this->section(
                __('Checklist findings'),
                [__('Date / time'), __('Plate'), __('Moment'), __('Side'), __('Damage'), __('New'), __('Driver'), __('Registered by')],
                $this->checklistRows($start, $end),
            ),
            'fleet_unclosed' => $this->section(
                __('Vehicles not returned'),
                [__('Date'), __('Plate'), __('Driver'), __('Reason'), __('Odometer at exit (km)'), __('Status'), __('Days outside')],
                $this->unclosedRows($trips),
            ),
            'fleet_detail' => $this->section(
                __('Detail of fleet trips'),
                [
                    __('Date'), __('Plate'), __('Vehicle'), __('Driver'), __('Copilot'), __('Reason'), __('Exit'), __('Return'),
                    __('Odometer at exit (km)'), __('Odometer at return (km)'), __('Distance (km)'), __('Minutes'), __('Status'),
                    __('Checklist at exit'), __('Checklist at return'), __('Notizen'), __('Registered by'),
                ],
                $this->detailRows($trips),
            ),
        ];
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<mixed>>  $rows
     * @return array{title: string, headers: list<string>, rows: list<list<mixed>>}
     */
    private function section(string $title, array $headers, array $rows): array
    {
        return ['title' => $title, 'headers' => $headers, 'rows' => $rows];
    }

    /**
     * @param  Collection<int, Trip>  $trips
     * @return list<list<mixed>>
     */
    private function byVehicleRows(Collection $trips): array
    {
        return $trips->groupBy(fn (Trip $trip): int => (int) $trip->exit->fleet_vehicle_id)->map(function (Collection $group): array {
            $vehicle = $group->first()->exit->fleetVehicle;
            $distances = $group->map(fn (Trip $trip): ?int => $trip->distanceKm())->filter(fn (?int $km): bool => $km !== null);
            $minutes = $group->map(fn (Trip $trip): ?int => $trip->minutes())->filter(fn (?int $value): bool => $value !== null);

            return [
                $vehicle?->plate_display,
                trim(($vehicle?->brand ?? '').' '.($vehicle?->model ?? '')) ?: '—',
                $group->count(),
                $group->where('status', Trip::CLOSED)->count(),
                $group->where('status', Trip::UNCLOSED)->count(),
                $group->where('status', Trip::OPEN)->count(),
                $distances->sum(),
                $distances->isEmpty() ? null : round($distances->avg(), 1),
                $minutes->sum(),
            ];
        })->sortBy(fn (array $row): string => (string) $row[0])->values()->all();
    }

    /**
     * @param  Collection<int, Trip>  $trips
     * @return list<list<mixed>>
     */
    private function byDriverRows(Collection $trips): array
    {
        return $trips->groupBy(fn (Trip $trip): int => (int) $trip->exit->driver_worker_id)->map(function (Collection $group): array {
            $driver = $group->first()->exit->driver;
            $distances = $group->map(fn (Trip $trip): ?int => $trip->distanceKm())->filter(fn (?int $km): bool => $km !== null);
            $minutes = $group->map(fn (Trip $trip): ?int => $trip->minutes())->filter(fn (?int $value): bool => $value !== null);

            return [
                $driver?->full_name ?? '—',
                $driver?->document_number,
                $group->count(),
                $distances->sum(),
                $minutes->sum(),
                $minutes->isEmpty() ? null : round($minutes->avg(), 1),
            ];
        })->sortByDesc(fn (array $row): int => $row[2])->values()->all();
    }

    /**
     * The two things the odometer can reveal: a vehicle that left with a reading different from the one it
     * came back with (somebody used it without writing it down), and a return lower than the exit.
     *
     * @return list<list<mixed>>
     */
    private function odometerRows(CarbonInterface $start, CarbonInterface $end): array
    {
        $rows = [];

        foreach ($this->marksByVehicle($start, $end, 'odometer_km') as $marks) {
            $previous = $this->previousMark($marks->first()->fleet_vehicle_id, $start, 'odometer_km');

            foreach ($marks as $mark) {
                $problem = null;

                if ($previous !== null && $mark->direction === MovementDirection::Exit && $mark->odometer_km !== $previous->odometer_km) {
                    $problem = __('Exit odometer differs from the last reading');
                } elseif ($previous !== null && $previous->direction === MovementDirection::Exit && $mark->direction === MovementDirection::Entry && $mark->odometer_km < $previous->odometer_km) {
                    $problem = __('Return odometer lower than at the exit');
                }

                if ($problem !== null) {
                    $rows[] = [
                        $mark->occurred_at,
                        $mark->fleetVehicle?->plate_display,
                        ($mark->driver ?? $previous->driver)?->full_name ?? '—',
                        $problem,
                        $previous->odometer_km,
                        $mark->odometer_km,
                        $mark->odometer_km - $previous->odometer_km,
                        $mark->notes,
                    ];
                }

                $previous = $mark;
            }
        }

        usort($rows, fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return $rows;
    }

    /**
     * One row per damaged side. "New" is yes when the same text was not already on the previous mark of the
     * vehicle, so an old scratch written at every exit does not look like fresh damage.
     *
     * @return list<list<mixed>>
     */
    private function checklistRows(CarbonInterface $start, CarbonInterface $end): array
    {
        $labels = ['front' => __('Front'), 'right' => __('Right side'), 'left' => __('Left side'), 'rear' => __('Rear')];
        $rows = [];

        foreach ($this->marksByVehicle($start, $end, 'checklist') as $marks) {
            $previous = $this->previousMark($marks->first()->fleet_vehicle_id, $start, 'checklist');

            foreach ($marks as $mark) {
                foreach ($labels as $side => $label) {
                    $text = trim((string) ($mark->checklist[$side] ?? ''));

                    if ($text === '') {
                        continue;
                    }

                    $rows[] = [
                        $mark->occurred_at,
                        $mark->fleetVehicle?->plate_display,
                        $mark->direction === MovementDirection::Exit ? __('Exit') : __('Return'),
                        $label,
                        $text,
                        mb_strtolower(trim((string) ($previous?->checklist[$side] ?? ''))) === mb_strtolower($text) ? '—' : __('Yes'),
                        $mark->driver?->full_name ?? '—',
                        $mark->registeredBy?->email,
                    ];
                }

                $previous = $mark;
            }
        }

        usort($rows, fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return $rows;
    }

    /**
     * Exits nothing closed, plus vehicles still out from a previous day (a workshop visit shows here too).
     *
     * @param  Collection<int, Trip>  $trips
     * @return list<list<mixed>>
     */
    private function unclosedRows(Collection $trips): array
    {
        return $trips
            ->filter(fn (Trip $trip): bool => $trip->status === Trip::UNCLOSED || ($trip->status === Trip::OPEN && ! $trip->exit->occurred_at->isToday()))
            ->map(fn (Trip $trip): array => [
                $trip->exit->occurred_at,
                $trip->exit->fleetVehicle?->plate_display,
                $trip->exit->driver?->full_name ?? '—',
                $trip->exit->reason?->name ?? '—',
                $trip->exit->odometer_km,
                $trip->statusLabel(),
                (int) $trip->exit->occurred_at->diffInDays(now(), true),
            ])->values()->all();
    }

    /**
     * @param  Collection<int, Trip>  $trips
     * @return list<list<mixed>>
     */
    private function detailRows(Collection $trips): array
    {
        return $trips->map(function (Trip $trip): array {
            $exit = $trip->exit;
            $vehicle = $exit->fleetVehicle;

            return [
                $exit->occurred_at->copy()->startOfDay(),
                $vehicle?->plate_display,
                trim(($vehicle?->brand ?? '').' '.($vehicle?->model ?? '')) ?: '—',
                $exit->driver?->full_name ?? '—',
                $exit->copilot?->full_name ?? '—',
                $exit->reason?->name ?? '—',
                $exit->occurred_at,
                $trip->return?->occurred_at,
                $exit->odometer_km,
                $trip->return?->odometer_km,
                $trip->distanceKm(),
                $trip->minutes(),
                $trip->statusLabel(),
                $exit->checklistSummary() ?? '—',
                $trip->return?->checklistSummary() ?? '—',
                $exit->notes,
                $exit->registeredBy?->email,
            ];
        })->all();
    }

    /**
     * The effective marks of the vehicles in the range that have the given column filled, grouped by vehicle.
     *
     * @return Collection<int, Collection<int, Movement>>
     */
    private function marksByVehicle(CarbonInterface $start, CarbonInterface $end, string $column): Collection
    {
        return Movement::query()
            ->effective()
            ->whereNotNull('fleet_vehicle_id')
            ->whereNotNull($column)
            ->whereBetween('occurred_at', [$start, $end])
            ->with(['fleetVehicle', 'driver', 'registeredBy'])
            ->orderBy('fleet_vehicle_id')
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get()
            ->groupBy('fleet_vehicle_id');
    }

    /**
     * The last mark of the vehicle before the range, to compare the first one inside it.
     */
    private function previousMark(int $vehicleId, CarbonInterface $start, string $column): ?Movement
    {
        return Movement::query()
            ->effective()
            ->where('fleet_vehicle_id', $vehicleId)
            ->whereNotNull($column)
            ->where('occurred_at', '<', $start)
            ->with('driver')
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->first();
    }
}
