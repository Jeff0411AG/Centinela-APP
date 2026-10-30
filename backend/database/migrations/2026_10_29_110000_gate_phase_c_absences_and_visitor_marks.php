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
    public function up(): void
    {
        $this->relaxVisitorSubject();

        Schema::table('movements', function (Blueprint $table) {
            $table->foreignId('visit_id')->nullable()->after('visitor_id')->constrained('visits')->nullOnDelete();
        });

        Schema::create('absences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('worker_id')->constrained('workers')->cascadeOnDelete();
            $table->string('type', 20);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->date('returns_on')->nullable();
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['worker_id', 'starts_on', 'ends_on']);
            $table->index(['starts_on', 'ends_on']);
        });

        if ($this->supportsChecks()) {
            DB::statement('ALTER TABLE absences ADD CONSTRAINT absences_dates_valid CHECK (ends_on >= starts_on)');
        }
    }

    public function down(): void
    {
        if ($this->supportsChecks()) {
            DB::unprepared('DROP TRIGGER IF EXISTS movements_one_subject_on_insert');
        }

        Schema::dropIfExists('absences');

        Schema::table('movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('visit_id');
        });
    }

    private function supportsChecks(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }

    /**
     * The retention job deletes expired visitors. Their marks must survive that, anonymised, instead of
     * blocking the delete: the foreign key becomes ON DELETE SET NULL (a cascaded action does not fire
     * the append-only triggers). MariaDB does not allow a CHECK on a column that has a referential action,
     * so "exactly one subject" moves from a CHECK to a BEFORE INSERT trigger: it still guards every new
     * mark, and the later anonymisation (a cascaded update, which fires no trigger) is not affected.
     */
    private function relaxVisitorSubject(): void
    {
        if (! $this->supportsChecks()) {
            return;
        }

        // Every statement tolerates a previous partial run: MariaDB does not roll back DDL.
        DB::statement('ALTER TABLE movements DROP CONSTRAINT IF EXISTS movements_exactly_one_subject');
        DB::statement('ALTER TABLE movements DROP CONSTRAINT IF EXISTS movements_at_most_one_subject');
        DB::unprepared('DROP TRIGGER IF EXISTS movements_one_subject_on_insert');
        DB::unprepared("CREATE TRIGGER movements_one_subject_on_insert BEFORE INSERT ON movements FOR EACH ROW BEGIN IF (NEW.worker_id IS NOT NULL) + (NEW.visitor_id IS NOT NULL) <> 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'movements needs exactly one subject: a worker or a visitor'; END IF; END");

        DB::statement('ALTER TABLE movements DROP FOREIGN KEY IF EXISTS movements_visitor_id_foreign');
        DB::statement('ALTER TABLE movements ADD CONSTRAINT movements_visitor_id_foreign FOREIGN KEY (visitor_id) REFERENCES visitors (id) ON DELETE SET NULL');
    }
};
