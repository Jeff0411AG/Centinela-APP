<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Services;

use App\Enums\MovementDirection;
use App\Exceptions\MovementConflict;
use App\Models\FleetVehicle;
use App\Models\Movement;
use App\Models\MovementReason;
use App\Models\User;
use App\Models\Worker;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Writes the gate marks of the company vehicles. Like the people's, they are rows of the append-only log,
 * and every method locks the vehicle first so two guards cannot both see the same "last mark".
 *
 * Nothing here blocks the gate for an odd odometer: the conflicts ask for a confirmation and the mark keeps
 * an automatic note, so the unlogged use of a vehicle shows up in the reports instead of stopping the guard.
 */
class VehicleMovementService
{
    /** The four sides of the damage checklist. */
    public const SIDES = ['front', 'right', 'left', 'rear'];

    /**
     * @param  array<string, string|null>  $checklist  side => damage text, or null for "no findings"
     *
     * @throws ValidationException when the driver, the reason or the odometer is missing or invalid
     * @throws MovementConflict when the driver is absent, the vehicle is already out or the odometer does not
     *                          match its last return, and $confirmed is false
     * @throws InvalidArgumentException when the vehicle or the reason cannot be used
     */
    public function registerExit(FleetVehicle $vehicle, ?Worker $driver, ?Worker $copilot, ?MovementReason $reason, ?string $odometer, array $checklist, ?string $notes, User $actor, bool $confirmed = false): Movement
    {
        $this->assertUsable($vehicle);
        $km = $this->validatedKm($odometer);
        $this->assertExitInput($driver, $reason);

        return DB::transaction(function () use ($vehicle, $driver, $copilot, $reason, $km, $checklist, $notes, $actor, $confirmed): Movement {
            FleetVehicle::query()->whereKey($vehicle->id)->lockForUpdate()->first();

            $last = $this->lastEffective($vehicle);
            $expected = $vehicle->currentOdometerKm();
            $mismatch = $expected !== null && $km !== $expected;

            if (! $confirmed) {
                if (($absence = $driver->absenceOn()) !== null) {
                    throw new MovementConflict(MovementConflict::ABSENT, $last, $absence->ends_on);
                }

                if ($last?->direction === MovementDirection::Exit) {
                    throw new MovementConflict(MovementConflict::ALREADY_OUTSIDE, $last);
                }

                if ($mismatch) {
                    throw new MovementConflict(MovementConflict::ODOMETER_MISMATCH, $last, null, $expected);
                }
            }

            return Movement::query()->create([
                'fleet_vehicle_id' => $vehicle->id,
                'direction' => MovementDirection::Exit,
                'reason_id' => $reason->id,
                'driver_worker_id' => $driver->id,
                'copilot_worker_id' => $copilot?->id,
                'odometer_km' => $km,
                'checklist' => $this->cleanChecklist($checklist),
                'notes' => $this->notes($notes, $mismatch ? __('Odometer differs from the last recorded reading (:km km).', ['km' => $expected]) : null),
                'registered_by_user_id' => $actor->id,
            ]);
        });
    }

    /**
     * @param  array<string, string|null>  $checklist
     *
     * @throws ValidationException when the odometer is missing or invalid
     * @throws MovementConflict when there is no open exit or the odometer is lower than at the exit, and $confirmed is false
     */
    public function registerReturn(FleetVehicle $vehicle, ?string $odometer, array $checklist, ?string $notes, User $actor, bool $confirmed = false): Movement
    {
        $km = $this->validatedKm($odometer);

        return DB::transaction(function () use ($vehicle, $km, $checklist, $notes, $actor, $confirmed): Movement {
            FleetVehicle::query()->whereKey($vehicle->id)->lockForUpdate()->first();

            $last = $this->lastEffective($vehicle);
            $exit = $last?->direction === MovementDirection::Exit ? $last : null;
            $lower = $exit?->odometer_km !== null && $km < $exit->odometer_km;

            if (! $confirmed) {
                if ($exit === null) {
                    throw new MovementConflict(MovementConflict::NOT_OUTSIDE, $last);
                }

                if ($lower) {
                    throw new MovementConflict(MovementConflict::ODOMETER_LOWER, $exit, null, $exit->odometer_km);
                }
            }

            $auto = match (true) {
                $exit === null => __('Return without a previous exit on record.'),
                $lower => __('Return odometer is lower than at the exit (:km km).', ['km' => $exit->odometer_km]),
                default => null,
            };

            return Movement::query()->create([
                'fleet_vehicle_id' => $vehicle->id,
                'direction' => MovementDirection::Entry,
                'reason_id' => $exit?->reason_id,
                'driver_worker_id' => $exit?->driver_worker_id,
                'copilot_worker_id' => $exit?->copilot_worker_id,
                'exit_movement_id' => $exit?->id,
                'odometer_km' => $km,
                'checklist' => $this->cleanChecklist($checklist),
                'notes' => $this->notes($notes, $auto),
                'registered_by_user_id' => $actor->id,
            ]);
        });
    }

    private function assertUsable(FleetVehicle $vehicle): void
    {
        // Read from the database, not from the object received: it may be stale.
        if (! FleetVehicle::query()->whereKey($vehicle->id)->value('is_active')) {
            throw new InvalidArgumentException(__('The vehicle is not active.'));
        }
    }

    /**
     * @throws ValidationException
     */
    private function assertExitInput(?Worker $driver, ?MovementReason $reason): void
    {
        $errors = [];

        if ($driver === null || ! $driver->is_active) {
            $errors['vehicleDriverId'] = __('Select the driver.');
        }

        if ($reason === null || ! $reason->is_active || $reason->applies_to !== 'vehicle') {
            $errors['vehicleReasonId'] = __('Select a reason.');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @throws ValidationException
     */
    private function validatedKm(?string $odometer): int
    {
        $digits = preg_replace('/[\s.,]/', '', trim((string) $odometer)) ?? '';

        if ($digits === '') {
            throw ValidationException::withMessages(['odometerKm' => __('Enter the odometer reading.')]);
        }

        if (preg_match('/^\d{1,7}$/', $digits) !== 1) {
            throw ValidationException::withMessages(['odometerKm' => __('The odometer must be a whole number.')]);
        }

        return (int) $digits;
    }

    private function lastEffective(FleetVehicle $vehicle): ?Movement
    {
        return Movement::query()
            ->effective()
            ->where('fleet_vehicle_id', $vehicle->id)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Every side is always present; a missing or blank side means "no findings".
     *
     * @param  array<string, string|null>  $checklist
     * @return array<string, string|null>
     */
    private function cleanChecklist(array $checklist): array
    {
        $clean = [];

        foreach (self::SIDES as $side) {
            $text = trim((string) ($checklist[$side] ?? ''));
            $clean[$side] = $text === '' ? null : mb_substr($text, 0, 200);
        }

        return $clean;
    }

    private function notes(?string $notes, ?string $automatic): ?string
    {
        $parts = array_filter([trim((string) $notes), $automatic], fn (?string $part): bool => filled($part));

        return $parts === [] ? null : mb_substr(implode(' · ', $parts), 0, 500);
    }
}
