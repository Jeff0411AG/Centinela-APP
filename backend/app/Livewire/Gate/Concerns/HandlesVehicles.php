<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Livewire\Gate\Concerns;

use App\Exceptions\MovementConflict;
use App\Models\FleetVehicle;
use App\Models\Movement;
use App\Models\MovementReason;
use App\Models\StaffVehicle;
use App\Models\Worker;
use App\Services\VehicleMovementService;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * The vehicle half of the gate desk: the same search box finds the company vehicles (to register their
 * exit and return, with odometer and damage checklist) and the staff vehicles (to see whether a plate is
 * authorised). Meant for GatePage, which owns the search, the selection of a worker and the flash message.
 */
trait HandlesVehicles
{
    public ?int $vehicleId = null;

    /** 'exit' or 'return' while a form is open. */
    public ?string $vehicleAction = null;

    public string $vehicleDriverSearch = '';

    public ?int $vehicleDriverId = null;

    public string $copilotSearch = '';

    public ?int $copilotId = null;

    public ?int $vehicleReasonId = null;

    public string $odometerKm = '';

    /** @var array<string, string> side => 'ok' or 'damage' */
    public array $checkStatus = ['front' => 'ok', 'right' => 'ok', 'left' => 'ok', 'rear' => 'ok'];

    /** @var array<string, string> side => what is wrong, when its status is 'damage' */
    public array $checkText = ['front' => '', 'right' => '', 'left' => '', 'rear' => ''];

    public string $vehicleNotes = '';

    /**
     * @var array{action: string, conflict: string, since: string|null, absent_until: string|null, expected_km: int|null}|null
     */
    public ?array $vehicleConflict = null;

    public function selectVehicle(int $vehicleId): void
    {
        $this->authorize('create', Movement::class);

        $vehicle = FleetVehicle::query()->active()->find($vehicleId);

        if ($vehicle === null) {
            return;
        }

        $this->workerId = null;
        $this->resetForm();
        $this->resetVehicleForm();
        $this->flash = null;
        $this->vehicleId = $vehicle->id;
        $this->search = '';
    }

    public function startVehicleExit(): void
    {
        $vehicle = $this->currentVehicle();

        if ($vehicle === null) {
            return;
        }

        $this->vehicleAction = 'exit';
        $this->vehicleConflict = null;
        $this->resetErrorBag();
        $this->odometerKm = (string) ($vehicle->currentOdometerKm() ?? '');
        $this->vehicleReasonId ??= MovementReason::query()->active()->forVehicles()->ordered()->value('id');
    }

    public function startVehicleReturn(): void
    {
        if ($this->currentVehicle() === null) {
            return;
        }

        $this->vehicleAction = 'return';
        $this->vehicleConflict = null;
        $this->resetErrorBag();
        $this->odometerKm = '';
    }

    /**
     * From the "Vehicles outside" panel: opens the return form of that vehicle.
     */
    public function openVehicleReturn(int $vehicleId): void
    {
        $this->selectVehicle($vehicleId);
        $this->startVehicleReturn();
    }

    public function updatedVehicleDriverSearch(): void
    {
        $this->vehicleDriverId = null;

        if (mb_strlen(trim($this->vehicleDriverSearch)) >= 2) {
            $this->enforceSearchRateLimit('gate-vehicle-driver');
        }
    }

    public function updatedCopilotSearch(): void
    {
        $this->copilotId = null;

        if (mb_strlen(trim($this->copilotSearch)) >= 2) {
            $this->enforceSearchRateLimit('gate-vehicle-copilot');
        }
    }

    public function selectVehicleDriver(int $workerId): void
    {
        $worker = Worker::query()->active()->find($workerId);

        if ($worker !== null) {
            $this->vehicleDriverId = $worker->id;
            $this->vehicleDriverSearch = $worker->full_name;
        }
    }

    public function selectCopilot(int $workerId): void
    {
        $worker = Worker::query()->active()->find($workerId);

        if ($worker !== null) {
            $this->copilotId = $worker->id;
            $this->copilotSearch = $worker->full_name;
        }
    }

    public function clearCopilot(): void
    {
        $this->copilotId = null;
        $this->copilotSearch = '';
    }

    public function cancelVehicleForm(): void
    {
        $this->resetVehicleForm(keepVehicle: true);
    }

    public function submitVehicleExit(bool $confirmed = false): void
    {
        $this->authorize('create', Movement::class);

        $vehicle = $this->currentVehicle();

        if ($vehicle === null) {
            return;
        }

        $checklist = $this->checklistInput();

        try {
            $movement = app(VehicleMovementService::class)->registerExit(
                $vehicle,
                $this->vehicleDriverId ? Worker::query()->active()->find($this->vehicleDriverId) : null,
                $this->copilotId ? Worker::query()->active()->find($this->copilotId) : null,
                $this->vehicleReasonId ? MovementReason::query()->find($this->vehicleReasonId) : null,
                $this->odometerKm,
                $checklist,
                $this->vehicleNotes,
                auth()->user(),
                $confirmed,
            );
        } catch (MovementConflict $conflict) {
            $this->holdVehicleConflict('exit', $conflict);

            return;
        } catch (InvalidArgumentException $exception) {
            $this->addError('vehicle', $exception->getMessage());

            return;
        }

        $this->flash = __(':name: exit registered at :time (:km km).', [
            'name' => $vehicle->label,
            'time' => $movement->occurred_at->format('H:i'),
            'km' => number_format((int) $movement->odometer_km, 0, '.', ' '),
        ]);

        $this->clearSelection();
    }

    public function submitVehicleReturn(bool $confirmed = false): void
    {
        $this->authorize('create', Movement::class);

        $vehicle = $this->currentVehicle();

        if ($vehicle === null) {
            return;
        }

        $checklist = $this->checklistInput();

        try {
            $movement = app(VehicleMovementService::class)->registerReturn(
                $vehicle,
                $this->odometerKm,
                $checklist,
                $this->vehicleNotes,
                auth()->user(),
                $confirmed,
            );
        } catch (MovementConflict $conflict) {
            $this->holdVehicleConflict('return', $conflict);

            return;
        } catch (InvalidArgumentException $exception) {
            $this->addError('vehicle', $exception->getMessage());

            return;
        }

        $distance = $movement->exitMovement?->odometer_km !== null ? $movement->odometer_km - $movement->exitMovement->odometer_km : null;

        $this->flash = __(':name: return registered at :time (:km km).', [
            'name' => $vehicle->label,
            'time' => $movement->occurred_at->format('H:i'),
            'km' => number_format((int) $movement->odometer_km, 0, '.', ' '),
        ]).($distance !== null && $distance >= 0 ? ' '.__('Distance: :km km.', ['km' => $distance]) : '');

        $this->clearSelection();
    }

    public function confirmVehicleConflict(): void
    {
        $pending = $this->vehicleConflict;
        $this->vehicleConflict = null;

        if ($pending === null) {
            return;
        }

        $pending['action'] === 'return' ? $this->submitVehicleReturn(true) : $this->submitVehicleExit(true);
    }

    public function cancelVehicleConflict(): void
    {
        $this->vehicleConflict = null;
    }

    /**
     * Data the view needs for the vehicles.
     *
     * @return array<string, mixed>
     */
    protected function vehicleViewData(string $term): array
    {
        $searching = mb_strlen($term) >= 2;
        $driverTerm = trim($this->vehicleDriverSearch);
        $copilotTerm = trim($this->copilotSearch);

        return [
            'fleetResults' => $searching
                ? FleetVehicle::query()->active()->search($term)->with('latestMovement.driver')->orderBy('plate')->limit(5)->get()
                : collect(),
            'staffVehicleResults' => $searching
                ? StaffVehicle::query()->search($term)->with('department')->orderBy('owner_name')->limit(5)->get()
                : collect(),
            'selectedVehicle' => $this->vehicleId
                ? FleetVehicle::query()->with(['latestMovement.driver', 'latestMovement.reason'])->find($this->vehicleId)
                : null,
            'vehicleReasons' => MovementReason::query()->active()->forVehicles()->ordered()->get(),
            'vehicleDriverResults' => $this->vehicleAction === 'exit' && $this->vehicleDriverId === null && mb_strlen($driverTerm) >= 2
                ? Worker::query()->active()->search($driverTerm)->orderBy('last_name')->limit(5)->get()
                : collect(),
            'copilotResults' => $this->vehicleAction === 'exit' && $this->copilotId === null && mb_strlen($copilotTerm) >= 2
                ? Worker::query()->active()->search($copilotTerm)->orderBy('last_name')->limit(5)->get()
                : collect(),
            'vehiclesOutside' => Movement::query()->vehiclesOutsideNow()->with(['fleetVehicle', 'driver', 'reason'])->orderBy('occurred_at')->get(),
            'vehicleSides' => ['front' => __('Front'), 'right' => __('Right side'), 'left' => __('Left side'), 'rear' => __('Rear')],
        ];
    }

    protected function resetVehicleForm(bool $keepVehicle = false): void
    {
        if (! $keepVehicle) {
            $this->vehicleId = null;
        }

        $this->vehicleAction = null;
        $this->vehicleDriverSearch = '';
        $this->vehicleDriverId = null;
        $this->copilotSearch = '';
        $this->copilotId = null;
        $this->vehicleReasonId = null;
        $this->odometerKm = '';
        $this->checkStatus = ['front' => 'ok', 'right' => 'ok', 'left' => 'ok', 'rear' => 'ok'];
        $this->checkText = ['front' => '', 'right' => '', 'left' => '', 'rear' => ''];
        $this->vehicleNotes = '';
        $this->vehicleConflict = null;
        $this->resetErrorBag();
    }

    private function currentVehicle(): ?FleetVehicle
    {
        return $this->vehicleId ? FleetVehicle::query()->active()->find($this->vehicleId) : null;
    }

    /**
     * A side marked as damaged needs its text; the others are "no findings".
     *
     * @return array<string, string|null>
     *
     * @throws ValidationException
     */
    private function checklistInput(): array
    {
        $checklist = [];
        $errors = [];

        foreach (VehicleMovementService::SIDES as $side) {
            if (($this->checkStatus[$side] ?? 'ok') !== 'damage') {
                $checklist[$side] = null;

                continue;
            }

            $text = trim($this->checkText[$side] ?? '');

            if ($text === '') {
                $errors["checkText.{$side}"] = __('Describe the damage.');
            }

            $checklist[$side] = $text;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $checklist;
    }

    private function holdVehicleConflict(string $action, MovementConflict $conflict): void
    {
        $this->vehicleConflict = [
            'action' => $action,
            'conflict' => $conflict->conflict,
            'since' => $conflict->lastMovement?->occurred_at?->format('H:i'),
            'absent_until' => $conflict->absentUntil?->format('d/m/Y'),
            'expected_km' => $conflict->expectedKm,
        ];
    }
}
