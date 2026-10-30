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

return new class extends Migration
{
    private const APPEND_ONLY_MESSAGE = 'movements is append-only: rows cannot be updated or deleted';

    public function up(): void
    {
        Schema::create('workers', function (Blueprint $table) {
            $table->id();
            $table->string('document_type', 10)->default('DNI');
            $table->string('document_number', 20)->nullable()->unique();
            $table->string('last_name');
            $table->string('first_name');
            $table->string('worker_type', 10);
            $table->foreignId('department_id')->nullable()->constrained('departments')->restrictOnDelete();
            $table->foreignId('site_id')->constrained('sites')->restrictOnDelete();
            $table->string('card_code', 40)->nullable()->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['last_name', 'first_name']);
        });

        Schema::create('movement_reasons', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name')->unique();
            $table->boolean('requires_destination')->default(false);
            $table->boolean('requires_route')->default(false);
            $table->boolean('expects_return')->default(true);
            $table->unsignedSmallInteger('max_minutes')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('destinations', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('worker_id')->nullable()->constrained('workers')->restrictOnDelete();
            $table->foreignId('visitor_id')->nullable()->constrained('visitors')->restrictOnDelete();
            $table->foreignId('site_id')->constrained('sites')->restrictOnDelete();
            $table->string('direction', 12);
            $table->dateTime('occurred_at');
            $table->foreignId('reason_id')->nullable()->constrained('movement_reasons')->restrictOnDelete();
            $table->foreignId('destination_id')->nullable()->constrained('destinations')->restrictOnDelete();
            $table->string('route_number', 20)->nullable();
            $table->foreignId('driver_worker_id')->nullable()->constrained('workers')->restrictOnDelete();
            $table->string('guide_number', 30)->nullable();
            $table->string('notes', 500)->nullable();
            $table->foreignId('registered_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('exit_movement_id')->nullable()->constrained('movements')->restrictOnDelete();
            $table->foreignId('corrects_movement_id')->nullable()->constrained('movements')->restrictOnDelete();
            $table->string('correction_reason', 500)->nullable();
            $table->boolean('manual_time')->default(false);
            $table->string('manual_time_reason', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['worker_id', 'occurred_at']);
            $table->index(['visitor_id', 'occurred_at']);
            $table->index('occurred_at');
            $table->index(['site_id', 'occurred_at']);
        });

        $this->addConstraintsAndTriggers();
        $this->seedReasons();
    }

    public function down(): void
    {
        if ($this->supportsTriggers()) {
            DB::unprepared('DROP TRIGGER IF EXISTS movements_no_update');
            DB::unprepared('DROP TRIGGER IF EXISTS movements_no_delete');
        }

        Schema::dropIfExists('movements');
        Schema::dropIfExists('destinations');
        Schema::dropIfExists('movement_reasons');
        Schema::dropIfExists('workers');
    }

    private function supportsTriggers(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }

    private function addConstraintsAndTriggers(): void
    {
        if (! $this->supportsTriggers()) {
            return;
        }

        DB::statement('ALTER TABLE movements ADD CONSTRAINT movements_exactly_one_subject CHECK ((worker_id IS NOT NULL) + (visitor_id IS NOT NULL) = 1)');
        DB::statement("ALTER TABLE movements ADD CONSTRAINT movements_direction_valid CHECK (direction IN ('out', 'in', 'no_return'))");

        $message = self::APPEND_ONLY_MESSAGE;

        DB::unprepared("CREATE TRIGGER movements_no_update BEFORE UPDATE ON movements FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}'");
        DB::unprepared("CREATE TRIGGER movements_no_delete BEFORE DELETE ON movements FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}'");
    }

    /**
     * Reference data the gate screens depend on; safe to run on every install.
     */
    private function seedReasons(): void
    {
        $now = now();

        $reasons = [
            ['code' => 'lunch', 'name' => 'Refrigerio', 'requires_destination' => false, 'requires_route' => false, 'sort_order' => 10],
            ['code' => 'commission', 'name' => 'Comisión', 'requires_destination' => true, 'requires_route' => false, 'sort_order' => 20],
            ['code' => 'delivery_support', 'name' => 'Apoyo a reparto', 'requires_destination' => true, 'requires_route' => true, 'sort_order' => 30],
            ['code' => 'other', 'name' => 'Otra salida', 'requires_destination' => false, 'requires_route' => false, 'sort_order' => 40],
        ];

        foreach ($reasons as $reason) {
            DB::table('movement_reasons')->insertOrIgnore($reason + [
                'expects_return' => true,
                'max_minutes' => null,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
};
