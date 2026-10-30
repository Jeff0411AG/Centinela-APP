<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Database\Seeders;

use App\Enums\AbsenceType;
use App\Enums\GenderEnum;
use App\Enums\WorkerType;
use App\Models\Absence;
use App\Models\Department;
use App\Enums\VehicleKind;
use App\Models\Destination;
use App\Models\FleetVehicle;
use App\Models\StaffVehicle;
use App\Models\Site;
use App\Models\User;
use App\Models\Worker;
use App\Support\VisitorPortalPermissions;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

/**
 * Demo data for the gate module: one user per gate profile, a few fictitious workers and the
 * destinations seen in the legacy spreadsheets. Not part of DatabaseSeeder; run it on purpose:
 *
 *   php artisan db:seed --class=GateDemoSeeder
 *
 * The demo users reuse the password hash of admin@example.org, so the demo keeps a single password.
 */
class GateDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            throw new \RuntimeException('Gate demo data is disabled in production.');
        }

        VisitorPortalPermissions::sync($this->command);

        $this->seedUsers();
        $this->seedDestinations();
        $this->seedWorkers();
        $this->seedAbsences();
        $this->seedVehicles();
    }

    private function seedUsers(): void
    {
        $passwordHash = User::query()->where('email', 'admin@example.org')->value('password');

        if ($passwordHash === null) {
            $this->command?->warn('admin@example.org not found; run the demo seeders first.');

            return;
        }

        $site = Site::default();

        $users = [
            ['Gabriela', 'Guard', GenderEnum::Female, 'guard@example.org', 'guard'],
            ['Sergio', 'Supervisor', GenderEnum::Male, 'gatesupervisor@example.org', 'gate-supervisor'],
            ['Hilda', 'Humano', GenderEnum::Female, 'hr@example.org', 'hr'],
            ['Aurelio', 'Auditor', GenderEnum::Male, 'auditor@example.org', 'auditor'],
        ];

        foreach ($users as [$firstName, $name, $gender, $email, $role]) {
            $user = User::query()->updateOrCreate(['email' => $email], [
                'site_id' => $site->id,
                'name' => $name,
                'first_name' => $firstName,
                'gender' => $gender,
                'email_verified_at' => now(),
                'password' => $passwordHash,
            ]);

            $user->syncRoles([Role::findByName($role, 'web')]);
        }
    }

    private function seedDestinations(): void
    {
        foreach (['Rebagliati', 'Cobranza', 'Hosp. Loayza', 'Hosp. Angamos', 'Hosp. Policial', 'Alcántara', 'PNP'] as $name) {
            Destination::query()->firstOrCreate(['name' => $name], ['is_active' => true]);
        }
    }

    private function seedWorkers(): void
    {
        $site = Site::default();

        $workers = [
            ['10000001', 'QUISPE MAMANI', 'ROSA', WorkerType::Employee, 'APT'],
            ['10000002', 'RIOS TORRES', 'LUIS', WorkerType::Laborer, 'LIM'],
            ['10000003', 'PAREDES LOPEZ', 'ANA MARIA', WorkerType::Administrative, 'VENTAS'],
            ['10000004', 'CRUZ HUAMAN', 'MARIO', WorkerType::Employee, 'APT'],
            ['10000005', 'PALMA SOTO', 'JORGE', WorkerType::Employee, 'APT'],
            ['10000006', 'TORRES VEGA', 'ANA', WorkerType::Employee, 'SAC'],
            ['10000007', 'MARCOS DIAZ', 'MARCOS', WorkerType::Operator, 'DIST'],
            ['10000008', 'FRANK HERNANDEZ', 'FRANK', WorkerType::Operator, 'DIST'],
        ];

        foreach ($workers as [$document, $lastName, $firstName, $type, $area]) {
            $department = Department::query()->firstOrCreate(['site_id' => $site->id, 'name' => $area], ['is_active' => true]);

            Worker::query()->updateOrCreate(['document_number' => $document], [
                'document_type' => 'DNI',
                'last_name' => $lastName,
                'first_name' => $firstName,
                'worker_type' => $type,
                'department_id' => $department->id,
                'site_id' => $site->id,
                'is_active' => true,
            ]);
        }
    }

    private function seedAbsences(): void
    {
        $today = today();

        $absences = [
            ['10000006', AbsenceType::Vacation, $today->copy()->subDays(3), $today->copy()->addDays(5)],
            ['10000005', AbsenceType::MedicalLeave, $today->copy()->subDays(10), $today->copy()->addDays(2)],
        ];

        foreach ($absences as [$document, $type, $startsOn, $endsOn]) {
            $worker = Worker::query()->where('document_number', $document)->first();

            if ($worker !== null) {
                Absence::query()->updateOrCreate(
                    ['worker_id' => $worker->id, 'type' => $type->value, 'starts_on' => $startsOn->toDateString()],
                    ['ends_on' => $endsOn->toDateString()],
                );
            }
        }
    }

    /**
     * Fictitious plates and owners; none of them comes from the company's sheets.
     */
    private function seedVehicles(): void
    {
        $site = Site::default();

        foreach ([['TST101', 'Hyundai', 'H1', 'Blanco', 41250], ['TST102', 'Hyundai', 'H1', 'Blanco', 38900], ['TST103', 'Hyundai', 'H100', 'Plomo', 66310], ['TST104', 'Peugeot', 'Partner', 'Blanco', 120450]] as [$plate, $brand, $model, $color, $km]) {
            FleetVehicle::query()->updateOrCreate(['plate' => $plate], [
                'brand' => $brand, 'model' => $model, 'color' => $color, 'initial_odometer_km' => $km, 'site_id' => $site->id, 'is_active' => true,
            ]);
        }

        $owners = Worker::query()->whereIn('document_number', ['10000001', '10000002', '10000003', '10000004'])->get()->keyBy('document_number');

        $staff = [
            ['10000001', 'DEM201', VehicleKind::Car, 'Toyota Yaris', 'Plomo'],
            ['10000002', '4581DM', VehicleKind::Motorcycle, 'Honda', 'Negro'],
            ['10000003', null, VehicleKind::Bicycle, null, 'Rojo'],
            ['10000004', 'DEM202', VehicleKind::Car, 'Kia Rio', 'Blanco'],
        ];

        foreach ($staff as [$document, $plate, $kind, $brand, $color]) {
            $owner = $owners[$document] ?? null;

            if ($owner === null) {
                continue;
            }

            StaffVehicle::query()->updateOrCreate(
                $plate !== null ? ['plate' => $plate] : ['owner_worker_id' => $owner->id, 'kind' => $kind->value],
                ['kind' => $kind, 'plate' => $plate, 'owner_worker_id' => $owner->id, 'owner_name' => $owner->full_name, 'department_id' => $owner->department_id, 'brand' => $brand, 'color' => $color, 'is_active' => true],
            );
        }
    }
}
