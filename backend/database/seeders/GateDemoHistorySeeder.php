<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Database\Seeders;

use App\Enums\MovementDirection;
use App\Models\Destination;
use App\Models\FleetVehicle;
use App\Models\Movement;
use App\Models\MovementReason;
use App\Models\User;
use App\Models\Worker;
use App\Services\MovementService;
use Carbon\CarbonInterface;
use Illuminate\Database\Seeder;

/**
 * Three weeks of fictitious gate history so the reports have something to show. Run after GateDemoSeeder:
 *
 *   php artisan db:seed --class=GateDemoHistorySeeder
 *
 * It includes the cases the reports exist to find: a worker who did not return, an entry without an exit,
 * an exit during a vacation, a corrected time and a forgotten mark written by the supervisor. The fleet gets its own
 * four weeks (each part skips itself when it already has history), with an unlogged use, a mistyped return, a scratch,
 * an exit nobody closed and a vehicle still at the workshop.
 */
class GateDemoHistorySeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            throw new \RuntimeException('Gate demo history is disabled in production.');
        }

        $guard = User::query()->where('email', 'guard@example.org')->first();
        $supervisor = User::query()->where('email', 'gatesupervisor@example.org')->first();

        if ($guard === null || $supervisor === null) {
            $this->command?->warn('Run GateDemoSeeder first.');

            return;
        }

        $days = collect(range(1, 28))->map(fn (int $back) => today()->subDays($back))->filter(fn (CarbonInterface $day) => $day->isWeekday())->reverse()->values();

        $this->fleetHistory($days, $guard);

        if (Movement::query()->whereNull('fleet_vehicle_id')->whereDate('occurred_at', '<', today())->exists()) {
            $this->command?->warn('There is already gate history; nothing was added.');

            return;
        }

        mt_srand(20261029);

        $workers = Worker::query()->whereIn('document_number', ['10000001', '10000002', '10000003', '10000004', '10000005', '10000006'])->get()->keyBy('document_number');
        $driver = Worker::query()->where('document_number', '10000007')->first();
        $lunch = MovementReason::query()->where('code', 'lunch')->firstOrFail();
        $commission = MovementReason::query()->where('code', 'commission')->firstOrFail();
        $delivery = MovementReason::query()->where('code', 'delivery_support')->firstOrFail();
        $destinations = Destination::query()->orderBy('name')->get();

        // Morning trips end before the lunch break starts, and nobody goes out while on vacation or leave.
        foreach ($days as $index => $day) {
            $present = fn (string $document): bool => isset($workers[$document]) && $workers[$document]->absenceOn($day) === null;

            if ($present('10000002') && $index % 2 === 0) {
                $this->trip($workers['10000002'], $guard, $commission, $day->copy()->setTime(8, mt_rand(0, 40)), mt_rand(45, 150), ['destination_id' => $destinations->random()->id]);
            }

            if ($present('10000003') && $index % 3 === 0) {
                $this->trip($workers['10000003'], $guard, $commission, $day->copy()->setTime(8, mt_rand(0, 40)), mt_rand(45, 150), ['destination_id' => $destinations->random()->id]);
            }

            if ($driver !== null && $present('10000004') && $index % 2 === 1) {
                $this->trip($workers['10000004'], $guard, $delivery, $day->copy()->setTime(8, mt_rand(0, 30)), mt_rand(60, 210), [
                    'destination_id' => $destinations->random()->id,
                    'route_number' => (string) mt_rand(1, 2),
                    'driver_worker_id' => $driver->id,
                    'guide_number' => 'G-'.mt_rand(100, 999),
                ]);
            }

            foreach ($workers as $worker) {
                if ($worker->absenceOn($day) !== null) {
                    continue;
                }

                $this->trip($worker, $guard, $lunch, $day->copy()->setTime(12, 30)->addMinutes(mt_rand(0, 60)), mt_rand(35, 75));
            }
        }

        $this->specialCases($workers, $guard, $supervisor, $lunch);
    }

    /** @var array<string, int> */
    private array $km = [];

    /** @var array<string, array<string, string>> what is already wrong with each vehicle, written at every mark */
    private array $damage = [];

    /**
     * @param  \Illuminate\Support\Collection<int, CarbonInterface>  $days
     */
    private function fleetHistory($days, User $guard): void
    {
        if (Movement::query()->whereNotNull('fleet_vehicle_id')->exists()) {
            $this->command?->warn('There is already fleet history; nothing was added.');

            return;
        }

        $vehicles = FleetVehicle::query()->whereIn('plate', ['TST101', 'TST102', 'TST103', 'TST104'])->get()->keyBy('plate');
        $drivers = Worker::query()->whereIn('document_number', ['10000007', '10000008'])->orderBy('document_number')->get()->values();
        $route = MovementReason::query()->where('code', 'fleet_route')->first();
        $errand = MovementReason::query()->where('code', 'fleet_errand')->first();
        $workshop = MovementReason::query()->where('code', 'fleet_workshop')->first();

        if ($vehicles->count() < 4 || $drivers->count() < 2 || $route === null || $errand === null || $workshop === null) {
            $this->command?->warn('Run GateDemoSeeder first (fleet and drivers are missing).');

            return;
        }

        mt_srand(20261030);

        foreach ($vehicles as $plate => $vehicle) {
            $this->km[$plate] = $vehicle->currentOdometerKm() ?? 0;
            $this->damage[$plate] = [];
        }

        $n = $days->count();
        $unlogged = false;
        $mistyped = false;

        foreach ($days as $i => $day) {
            $driver = $drivers[$i % 2];
            $quiet = $i >= $n - 4;

            $newDamage = $i === $n - 10 ? ['right' => 'Rayón en la puerta derecha'] : [];
            $this->vehicleTrip($vehicles['TST101'], $guard, $route, $driver, $day->copy()->setTime(7, mt_rand(0, 30)), mt_rand(150, 300), mt_rand(40, 90), $newDamage);

            if ($i % 2 === 0) {
                if (! $unlogged && $i >= $n - 8) {
                    $this->km['TST102'] += 120;
                    $unlogged = true;
                }

                $this->vehicleTrip($vehicles['TST102'], $guard, $errand, $drivers[($i + 1) % 2], $day->copy()->setTime(9, mt_rand(0, 40)), mt_rand(60, 120), mt_rand(10, 30));
            }

            if ($i % 3 === 0 && ! $quiet) {
                $typo = ! $mistyped && $i >= $n - 12;
                $mistyped = $mistyped || $typo;
                $this->vehicleTrip($vehicles['TST103'], $guard, $route, $driver, $day->copy()->setTime(8, mt_rand(0, 30)), mt_rand(120, 240), mt_rand(30, 70), [], $typo ? -100 : 0);
            }

            if ($i % 5 === 0 && ! $quiet) {
                $this->vehicleTrip($vehicles['TST104'], $guard, $errand, $drivers[($i + 1) % 2], $day->copy()->setTime(10, mt_rand(0, 30)), mt_rand(45, 90), mt_rand(8, 25));
            }
        }

        // TST104 left and nobody closed it; the next day it went out again and came back.
        $this->vehicleMark($vehicles['TST104'], $guard, MovementDirection::Exit, $days[$n - 4]->copy()->setTime(15, 0), $errand, $this->km['TST104'], $drivers[0]);
        $this->vehicleTrip($vehicles['TST104'], $guard, $errand, $drivers[1], $days[$n - 3]->copy()->setTime(8, 0), 90, 18);

        // TST103 went into the workshop two working days ago and is still there.
        $this->vehicleMark($vehicles['TST103'], $guard, MovementDirection::Exit, $days[$n - 2]->copy()->setTime(9, 0), $workshop, $this->km['TST103'], $drivers[0], ['notes' => 'Cambio de frenos en el taller']);
    }

    /**
     * An exit and its return. $typo shifts the written return odometer (a slip of the guard), not the real one.
     *
     * @param  array<string, string>  $newDamage  damage found on the way back, kept on the vehicle from then on
     */
    private function vehicleTrip(FleetVehicle $vehicle, User $actor, MovementReason $reason, Worker $driver, CarbonInterface $exitAt, int $minutes, int $distance, array $newDamage = [], int $typo = 0): void
    {
        $plate = $vehicle->plate;
        $exit = $this->vehicleMark($vehicle, $actor, MovementDirection::Exit, $exitAt, $reason, $this->km[$plate], $driver);

        $this->km[$plate] += $distance;
        $this->damage[$plate] = $newDamage + $this->damage[$plate];

        $this->vehicleMark($vehicle, $actor, MovementDirection::Entry, $exitAt->copy()->addMinutes($minutes), $reason, $this->km[$plate] + $typo, $driver, ['exit_movement_id' => $exit->id]);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function vehicleMark(FleetVehicle $vehicle, User $actor, MovementDirection $direction, CarbonInterface $at, MovementReason $reason, int $km, Worker $driver, array $extra = []): Movement
    {
        return Movement::query()->create($extra + [
            'fleet_vehicle_id' => $vehicle->id,
            'direction' => $direction,
            'occurred_at' => $at,
            'reason_id' => $reason->id,
            'driver_worker_id' => $direction === MovementDirection::Exit ? $driver->id : null,
            'odometer_km' => $km,
            'checklist' => $this->damage[$vehicle->plate] ?: null,
            'registered_by_user_id' => $actor->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function trip(Worker $worker, User $actor, MovementReason $reason, CarbonInterface $exitAt, int $minutes, array $extra = []): void
    {
        $exit = $this->mark($worker, $actor, MovementDirection::Exit, $exitAt, $reason, $extra);
        $this->mark($worker, $actor, MovementDirection::Entry, $exitAt->copy()->addMinutes($minutes), $reason, ['exit_movement_id' => $exit->id]);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function mark(Worker $worker, User $actor, MovementDirection $direction, CarbonInterface $at, ?MovementReason $reason, array $extra = []): Movement
    {
        return Movement::query()->create($extra + [
            'worker_id' => $worker->id,
            'direction' => $direction,
            'occurred_at' => $at,
            'reason_id' => $reason?->id,
            'registered_by_user_id' => $actor->id,
        ]);
    }

    /**
     * @param  \Illuminate\Support\Collection<string, Worker>  $workers
     */
    private function specialCases($workers, User $guard, User $supervisor, MovementReason $lunch): void
    {
        $service = app(MovementService::class);
        $yesterday = today()->subDay()->isWeekend() ? today()->previousWeekday() : today()->subDay();

        // Palma left and never came back: closed as "did not return".
        $palma = $this->mark($workers['10000005'], $guard, MovementDirection::Exit, $yesterday->copy()->setTime(16, 10), $lunch);
        $this->mark($workers['10000005'], $guard, MovementDirection::NoReturn, $yesterday->copy()->setTime(18, 0), $lunch, ['exit_movement_id' => $palma->id]);

        // Rios Torres came in without any exit on record.
        $this->mark($workers['10000002'], $guard, MovementDirection::Entry, $yesterday->copy()->setTime(7, 10), null, ['notes' => 'Ingreso sin salida previa registrada']);

        // Paredes went out twice in a row: the first exit was never closed.
        $this->mark($workers['10000003'], $guard, MovementDirection::Exit, $yesterday->copy()->setTime(16, 0), $lunch);
        $this->mark($workers['10000003'], $guard, MovementDirection::Exit, $yesterday->copy()->setTime(16, 40), $lunch);
        $this->mark($workers['10000003'], $guard, MovementDirection::Entry, $yesterday->copy()->setTime(17, 20), $lunch);

        // Torres Vega went out for lunch while she was on vacation (the only exit during an absence).
        $this->trip($workers['10000006'], $guard, $lunch, today()->subDays(2)->setTime(18, 40), 50);

        // The supervisor corrected a lunch time and wrote down an exit nobody had registered.
        $mistake = Movement::query()->where('worker_id', $workers['10000001']->id)->where('direction', MovementDirection::Exit->value)->orderByDesc('occurred_at')->first();

        if ($mistake !== null) {
            $service->correct($mistake, ['occurred_at' => $mistake->occurred_at->copy()->subMinutes(25)], 'El vigilante anotó una hora equivocada', $supervisor);
        }

        $service->registerManual($workers['10000004'], MovementDirection::Exit, $yesterday->copy()->setTime(6, 45), 'La salida de la madrugada no se anotó', $supervisor, ['reason_id' => $lunch->id]);
    }
}
