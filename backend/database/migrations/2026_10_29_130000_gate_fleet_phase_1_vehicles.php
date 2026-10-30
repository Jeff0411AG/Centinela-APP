<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fleet and plates, phase 1.
 *
 * - fleet_vehicles: the company vehicles that go out through the gate.
 * - staff_vehicles: the registry of the staff's cars, motorcycles and bicycles (lookup only).
 * - movements gain a third kind of subject (a fleet vehicle) plus odometer, copilot and checklist.
 * - movement_reasons say whether they apply to people or to vehicles.
 *
 * MariaDB does not roll DDL back, so every step checks what already exists and the migration can be re-run.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('fleet_vehicles')) {
            Schema::create('fleet_vehicles', function (Blueprint $table) {
                $table->id();
                $table->string('plate', 12)->unique();
                $table->string('brand')->nullable();
                $table->string('model')->nullable();
                $table->string('color')->nullable();
                $table->unsignedInteger('initial_odometer_km')->nullable();
                $table->foreignId('site_id')->constrained('sites')->restrictOnDelete();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('staff_vehicles')) {
            Schema::create('staff_vehicles', function (Blueprint $table) {
                $table->id();
                $table->string('kind', 20);
                $table->string('plate', 12)->nullable()->unique();
                $table->foreignId('owner_worker_id')->nullable()->constrained('workers')->nullOnDelete();
                $table->string('owner_name');
                $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
                $table->string('brand')->nullable();
                $table->string('color')->nullable();
                $table->string('notes', 500)->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->index('owner_name');
            });
        }

        Schema::table('movements', function (Blueprint $table) {
            if (! Schema::hasColumn('movements', 'fleet_vehicle_id')) {
                $table->foreignId('fleet_vehicle_id')->nullable()->after('visit_id')->constrained('fleet_vehicles')->restrictOnDelete();
                $table->index(['fleet_vehicle_id', 'occurred_at']);
            }

            if (! Schema::hasColumn('movements', 'odometer_km')) {
                $table->unsignedInteger('odometer_km')->nullable()->after('guide_number');
            }

            if (! Schema::hasColumn('movements', 'copilot_worker_id')) {
                $table->foreignId('copilot_worker_id')->nullable()->after('driver_worker_id')->constrained('workers')->restrictOnDelete();
            }

            if (! Schema::hasColumn('movements', 'checklist')) {
                $table->json('checklist')->nullable()->after('odometer_km');
            }
        });

        Schema::table('movement_reasons', function (Blueprint $table) {
            if (! Schema::hasColumn('movement_reasons', 'applies_to')) {
                $table->string('applies_to', 10)->default('worker')->after('name');
            }
        });

        $this->seedVehicleReasons();
        $this->recreateSubjectTrigger(withVehicles: true);
    }

    public function down(): void
    {
        $this->recreateSubjectTrigger(withVehicles: false);

        Schema::table('movements', function (Blueprint $table) {
            if (Schema::hasColumn('movements', 'copilot_worker_id')) {
                $table->dropConstrainedForeignId('copilot_worker_id');
            }

            if (Schema::hasColumn('movements', 'fleet_vehicle_id')) {
                $table->dropConstrainedForeignId('fleet_vehicle_id');
            }

            foreach (['checklist', 'odometer_km'] as $column) {
                if (Schema::hasColumn('movements', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('movement_reasons', function (Blueprint $table) {
            if (Schema::hasColumn('movement_reasons', 'applies_to')) {
                $table->dropColumn('applies_to');
            }
        });

        Schema::dropIfExists('staff_vehicles');
        Schema::dropIfExists('fleet_vehicles');
    }

    private function supportsTriggers(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }

    private function seedVehicleReasons(): void
    {
        $now = now();

        $reasons = [
            ['code' => 'fleet_route', 'name' => 'Ruta de reparto', 'sort_order' => 110],
            ['code' => 'fleet_errand', 'name' => 'Trámite', 'sort_order' => 120],
            ['code' => 'fleet_workshop', 'name' => 'Taller', 'sort_order' => 130],
            ['code' => 'fleet_other', 'name' => 'Otro', 'sort_order' => 140],
        ];

        foreach ($reasons as $reason) {
            DB::table('movement_reasons')->insertOrIgnore($reason + [
                'applies_to' => 'vehicle',
                'requires_destination' => false,
                'requires_route' => false,
                'expects_return' => true,
                'max_minutes' => null,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * The BEFORE INSERT guard keeps every rule of the earlier phases (one subject, written reasons for
     * corrections, voids and manual times); the subject rule now allows a fleet vehicle too.
     */
    private function recreateSubjectTrigger(bool $withVehicles): void
    {
        if (! $this->supportsTriggers()) {
            return;
        }

        $subjects = $withVehicles
            ? '(NEW.worker_id IS NOT NULL) + (NEW.visitor_id IS NOT NULL) + (NEW.fleet_vehicle_id IS NOT NULL)'
            : '(NEW.worker_id IS NOT NULL) + (NEW.visitor_id IS NOT NULL)';
        $message = $withVehicles
            ? 'movements needs exactly one subject: a worker, a visitor or a vehicle'
            : 'movements needs exactly one subject: a worker or a visitor';

        DB::unprepared('DROP TRIGGER IF EXISTS movements_one_subject_on_insert');
        DB::unprepared(<<<SQL
CREATE TRIGGER movements_one_subject_on_insert BEFORE INSERT ON movements FOR EACH ROW
BEGIN
    IF {$subjects} <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}';
    END IF;
    IF NEW.corrects_movement_id IS NOT NULL AND (NEW.correction_reason IS NULL OR TRIM(NEW.correction_reason) = '') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a correction needs a written reason';
    END IF;
    IF NEW.direction = 'void' AND NEW.corrects_movement_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a void mark must point at the mark it annuls';
    END IF;
    IF NEW.manual_time = 1 AND (NEW.manual_time_reason IS NULL OR TRIM(NEW.manual_time_reason) = '') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a manual time needs a written reason';
    END IF;
END
SQL);
    }
};
