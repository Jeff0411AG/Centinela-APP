<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Services;

use App\Enums\MovementDirection;
use App\Models\Absence;
use App\Models\Movement;
use App\Support\Trip;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * The gate reports (people first, then the fleet). Every section is a plain table (title, headers, rows) so the same data feeds the
 * screen and the Excel workbook. Dates are returned as Carbon objects so Excel gets real dates.
 */
class GateReports
{
    private readonly FleetReports $fleet;

    public function __construct(private readonly GateTrips $tripBuilder, ?FleetReports $fleet = null)
    {
        $this->fleet = $fleet ?? new FleetReports($tripBuilder);
    }

    /**
     * @return array<string, array{title: string, headers: list<string>, rows: list<list<mixed>>}>
     */
    public function build(CarbonInterface $from, CarbonInterface $until, ?int $departmentId = null, bool $canSeeAbsenceType = false): array
    {
        ['trips' => $trips, 'orphanEntries' => $orphans] = $this->tripBuilder->between($from, $until, $departmentId);

        $withReason = fn (string $code): Collection => $trips->filter(fn (Trip $trip): bool => $trip->reasonCode() === $code)->values();

        return [
            'by_reason' => $this->section(__('Summary by reason'), $this->summaryHeaders(__('Reason')), $this->summaryRows($trips, fn (Trip $trip): string => $trip->exit->reason?->name ?? '—')),
            'lunch_by_area' => $this->section(__('Lunch by area'), $this->summaryHeaders(__('Abteilung')), $this->summaryRows($withReason('lunch'), fn (Trip $trip): string => $trip->exit->worker?->department?->name ?? '—')),
            'commission_by_destination' => $this->section(__('Commissions by destination'), $this->summaryHeaders(__('Destination')), $this->summaryRows($withReason('commission'), fn (Trip $trip): string => $trip->exit->destination?->name ?? '—')),
            'commission_by_person' => $this->section(__('Commissions by person'), [__('Document number'), __('Worker'), __('Abteilung'), __('Exits'), __('Total time (min)'), __('Average (min)')], $this->personRows($withReason('commission'))),
            'delivery_by_driver' => $this->section(__('Delivery support by driver and route'), [__('Driver'), __('Route'), __('Exits'), __('Returned'), __('Average (min)')], $this->deliveryRows($withReason('delivery_support'))),
            'unclosed' => $this->section(__('Exits that were not closed'), [__('Date'), __('Worker'), __('Document number'), __('Abteilung'), __('Reason'), __('Destination'), __('Status')], $this->unclosedRows($trips)),
            'orphans' => $this->section(__('Entries without a previous exit'), [__('Date / time'), __('Worker'), __('Document number'), __('Abteilung'), __('Notizen')], $this->orphanRows($orphans)),
            'corrections' => $this->section(__('Corrections and voids'), [__('Date of the correction'), __('Person'), __('Type'), __('Original mark'), __('New value'), __('Reason for the correction'), __('Registered by')], $this->correctionRows($from, $until, $departmentId)),
            'manual' => $this->section(__('Marks with a manual time'), [__('Date / time'), __('Person'), __('Direction'), __('Reason for the manual time'), __('Registered by')], $this->manualRows($from, $until, $departmentId)),
            'absent' => $this->section(__('Exits while absent'), [__('Date'), __('Worker'), __('Document number'), __('Abteilung'), __('Reason'), $canSeeAbsenceType ? __('Absence') : __('Status'), __('Until')], $this->absentRows($trips, $from, $until, $canSeeAbsenceType)),
            'detail' => $this->section(__('Detail of the trips'), [
                __('Date'), __('Worker'), __('Document number'), __('Worker type'), __('Abteilung'), __('Reason'), __('Destination'), __('Route'), __('Driver'), __('Guide'),
                __('Exit'), __('Return'), __('Minutes'), __('Status'), __('Registered by'), __('Notizen'),
            ], $this->detailRows($trips)),
        ] + $this->fleet->build($from, $until);
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
     * @return list<string>
     */
    private function summaryHeaders(string $groupLabel): array
    {
        return [$groupLabel, __('Exits'), __('Returned'), __('Did not return'), __('Still outside'), __('Average (min)'), __('Maximum (min)'), __('Total time (min)')];
    }

    /**
     * @param  Collection<int, Trip>  $trips
     * @param  callable(Trip): string  $groupBy
     * @return list<list<mixed>>
     */
    private function summaryRows(Collection $trips, callable $groupBy): array
    {
        return $trips->groupBy($groupBy)->sortKeys()->map(function (Collection $group, string $name): array {
            $minutes = $group->map(fn (Trip $trip): ?int => $trip->minutes())->filter(fn (?int $value): bool => $value !== null);

            return [
                $name,
                $group->count(),
                $group->where('status', Trip::CLOSED)->count(),
                $group->filter(fn (Trip $trip): bool => in_array($trip->status, [Trip::NO_RETURN, Trip::UNCLOSED], true))->count(),
                $group->where('status', Trip::OPEN)->count(),
                $minutes->isEmpty() ? null : round($minutes->avg(), 1),
                $minutes->isEmpty() ? null : $minutes->max(),
                $minutes->sum(),
            ];
        })->values()->all();
    }

    /**
     * @param  Collection<int, Trip>  $trips
     * @return list<list<mixed>>
     */
    private function personRows(Collection $trips): array
    {
        return $trips->groupBy(fn (Trip $trip): int => (int) $trip->exit->worker_id)->map(function (Collection $group): array {
            $worker = $group->first()->exit->worker;
            $minutes = $group->map(fn (Trip $trip): ?int => $trip->minutes())->filter(fn (?int $value): bool => $value !== null);

            return [
                $worker?->document_number,
                $worker?->full_name,
                $worker?->department?->name ?? '—',
                $group->count(),
                $minutes->sum(),
                $minutes->isEmpty() ? null : round($minutes->avg(), 1),
            ];
        })->sortByDesc(fn (array $row): int => $row[3])->values()->all();
    }

    /**
     * @param  Collection<int, Trip>  $trips
     * @return list<list<mixed>>
     */
    private function deliveryRows(Collection $trips): array
    {
        return $trips->groupBy(fn (Trip $trip): string => ($trip->exit->driver?->full_name ?? '—').'|'.($trip->exit->route_number ?? '—'))
            ->sortKeys()
            ->map(function (Collection $group): array {
                $first = $group->first()->exit;
                $minutes = $group->map(fn (Trip $trip): ?int => $trip->minutes())->filter(fn (?int $value): bool => $value !== null);

                return [
                    $first->driver?->full_name ?? '—',
                    $first->route_number ?? '—',
                    $group->count(),
                    $group->where('status', Trip::CLOSED)->count(),
                    $minutes->isEmpty() ? null : round($minutes->avg(), 1),
                ];
            })->values()->all();
    }

    /**
     * Exits that nothing closed, plus exits left open from a previous day.
     *
     * @param  Collection<int, Trip>  $trips
     * @return list<list<mixed>>
     */
    private function unclosedRows(Collection $trips): array
    {
        return $trips
            ->filter(fn (Trip $trip): bool => in_array($trip->status, [Trip::NO_RETURN, Trip::UNCLOSED], true)
                || ($trip->status === Trip::OPEN && ! $trip->exit->occurred_at->isToday()))
            ->map(fn (Trip $trip): array => [
                $trip->exit->occurred_at,
                $trip->exit->worker?->full_name,
                $trip->exit->worker?->document_number,
                $trip->exit->worker?->department?->name ?? '—',
                $trip->exit->reason?->name ?? '—',
                $trip->exit->destination?->name ?? '—',
                $trip->statusLabel(),
            ])->values()->all();
    }

    /**
     * @param  Collection<int, Movement>  $orphans
     * @return list<list<mixed>>
     */
    private function orphanRows(Collection $orphans): array
    {
        return $orphans->map(fn (Movement $mark): array => [
            $mark->occurred_at,
            $mark->worker?->full_name,
            $mark->worker?->document_number,
            $mark->worker?->department?->name ?? '—',
            $mark->notes,
        ])->values()->all();
    }

    /**
     * @return list<list<mixed>>
     */
    private function correctionRows(CarbonInterface $from, CarbonInterface $until, ?int $departmentId): array
    {
        return Movement::query()
            ->onlyCorrections()
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $until->copy()->endOfDay()])
            ->when($departmentId, fn ($query) => $query->whereHas('worker', fn ($worker) => $worker->where('department_id', $departmentId)))
            ->with(['worker', 'visitor', 'registeredBy', 'corrects'])
            ->orderBy('created_at')
            ->get()
            ->map(function (Movement $mark): array {
                $isVoid = $mark->direction === MovementDirection::Void;
                $original = $mark->corrects;

                return [
                    $mark->created_at,
                    $mark->worker?->full_name ?? trim(($mark->visitor?->first_name ?? '').' '.($mark->visitor?->name ?? '')),
                    $isVoid ? __('Voiding') : __('Correction'),
                    $original ? $original->direction->label().' '.$original->occurred_at->format('d/m/Y H:i') : '—',
                    $isVoid ? '—' : $mark->direction->label().' '.$mark->occurred_at->format('d/m/Y H:i'),
                    $mark->correction_reason,
                    $mark->registeredBy?->email,
                ];
            })->all();
    }

    /**
     * @return list<list<mixed>>
     */
    private function manualRows(CarbonInterface $from, CarbonInterface $until, ?int $departmentId): array
    {
        return Movement::query()
            ->effective()
            ->where('manual_time', true)
            ->whereBetween('occurred_at', [$from->copy()->startOfDay(), $until->copy()->endOfDay()])
            ->when($departmentId, fn ($query) => $query->whereHas('worker', fn ($worker) => $worker->where('department_id', $departmentId)))
            ->with(['worker', 'visitor', 'registeredBy'])
            ->orderBy('occurred_at')
            ->get()
            ->map(fn (Movement $mark): array => [
                $mark->occurred_at,
                $mark->worker?->full_name ?? trim(($mark->visitor?->first_name ?? '').' '.($mark->visitor?->name ?? '')),
                $mark->direction->label(),
                $mark->manual_time_reason,
                $mark->registeredBy?->email,
            ])->all();
    }

    /**
     * Exits made on a day the worker was on vacation or leave. The type of absence is only shown to
     * those who may read it, because medical leave is health data.
     *
     * @param  Collection<int, Trip>  $trips
     * @return list<list<mixed>>
     */
    private function absentRows(Collection $trips, CarbonInterface $from, CarbonInterface $until, bool $canSeeType): array
    {
        $absences = Absence::query()
            ->whereIn('worker_id', $trips->pluck('exit.worker_id')->unique()->all())
            ->whereDate('starts_on', '<=', $until->copy()->endOfDay())
            ->whereDate('ends_on', '>=', $from->copy()->startOfDay())
            ->get()
            ->groupBy('worker_id');

        return $trips->map(function (Trip $trip) use ($absences, $canSeeType): ?array {
            $day = $trip->exit->occurred_at->copy()->startOfDay();
            $absence = ($absences->get($trip->exit->worker_id) ?? collect())
                ->first(fn (Absence $item): bool => $item->starts_on->lte($day) && $item->ends_on->gte($day));

            if ($absence === null) {
                return null;
            }

            return [
                $trip->exit->occurred_at,
                $trip->exit->worker?->full_name,
                $trip->exit->worker?->document_number,
                $trip->exit->worker?->department?->name ?? '—',
                $trip->exit->reason?->name ?? '—',
                $canSeeType ? $absence->type->label() : __('Absent'),
                $absence->ends_on,
            ];
        })->filter()->values()->all();
    }

    /**
     * @param  Collection<int, Trip>  $trips
     * @return list<list<mixed>>
     */
    private function detailRows(Collection $trips): array
    {
        return $trips->map(function (Trip $trip): array {
            $exit = $trip->exit;

            return [
                $exit->occurred_at->copy()->startOfDay(),
                $exit->worker?->full_name,
                $exit->worker?->document_number,
                $exit->worker?->worker_type?->label(),
                $exit->worker?->department?->name ?? '—',
                $exit->reason?->name ?? '—',
                $exit->destination?->name ?? '—',
                $exit->route_number ?? '—',
                $exit->driver?->full_name ?? '—',
                $exit->guide_number ?? '—',
                $exit->occurred_at,
                $trip->return?->occurred_at,
                $trip->minutes(),
                $trip->statusLabel(),
                $exit->registeredBy?->email,
                $exit->notes,
            ];
        })->all();
    }
}
