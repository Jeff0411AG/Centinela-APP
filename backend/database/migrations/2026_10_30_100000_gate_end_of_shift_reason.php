<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The reason recorded when a worker leaves for the day. It never shows as an exit button (it does not
 * expect a return); the gate desk writes it on a stand-alone "did not return" mark.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('movement_reasons')->insertOrIgnore([
            'code' => 'end_of_shift',
            'name' => 'Fin de jornada',
            'applies_to' => 'worker',
            'requires_destination' => false,
            'requires_route' => false,
            'expects_return' => false,
            'max_minutes' => null,
            'sort_order' => 900,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        DB::table('movement_reasons')->where('code', 'end_of_shift')->whereNotExists(function ($query) {
            $query->select(DB::raw(1))->from('movements')->whereColumn('movements.reason_id', 'movement_reasons.id');
        })->delete();
    }
};
