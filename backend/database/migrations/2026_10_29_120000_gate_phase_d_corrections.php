<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Corrections never edit a mark: they add a new one that points at the original. A "void" mark annuls
 * a mark that should not exist (for example registered for the wrong person). The database now also
 * insists on the written reason, so no screen or script can skip it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->supported()) {
            return;
        }

        // Every statement tolerates a previous partial run: MariaDB does not roll back DDL.
        DB::statement('ALTER TABLE movements DROP CONSTRAINT IF EXISTS movements_direction_valid');
        DB::statement("ALTER TABLE movements ADD CONSTRAINT movements_direction_valid CHECK (direction IN ('out', 'in', 'no_return', 'void'))");

        DB::unprepared('DROP TRIGGER IF EXISTS movements_one_subject_on_insert');
        DB::unprepared(<<<'SQL'
CREATE TRIGGER movements_one_subject_on_insert BEFORE INSERT ON movements FOR EACH ROW
BEGIN
    IF (NEW.worker_id IS NOT NULL) + (NEW.visitor_id IS NOT NULL) <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'movements needs exactly one subject: a worker or a visitor';
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

    public function down(): void
    {
        if (! $this->supported()) {
            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS movements_one_subject_on_insert');
        DB::unprepared("CREATE TRIGGER movements_one_subject_on_insert BEFORE INSERT ON movements FOR EACH ROW BEGIN IF (NEW.worker_id IS NOT NULL) + (NEW.visitor_id IS NOT NULL) <> 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'movements needs exactly one subject: a worker or a visitor'; END IF; END");

        // Fails on purpose when "void" marks exist: they cannot be deleted from an append-only log.
        DB::statement('ALTER TABLE movements DROP CONSTRAINT IF EXISTS movements_direction_valid');
        DB::statement("ALTER TABLE movements ADD CONSTRAINT movements_direction_valid CHECK (direction IN ('out', 'in', 'no_return'))");
    }

    private function supported(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }
};
