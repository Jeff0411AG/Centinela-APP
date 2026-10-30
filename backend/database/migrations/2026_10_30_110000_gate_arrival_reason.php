<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The reason recorded when a worker arrives for the day. Like "end of shift" it is not an exit button
 * (it does not expect a return); the gate desk writes it on a stand-alone entry that the trip reports
 * do not treat as a forgotten exit.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('movement_reasons')->insertOrIgnore([
            'code' => 'arrival',
            'name' => 'Ingreso a jornada',
            'applies_to' => 'worker',
            'requires_destination' => false,
            'requires_route' => false,
            'expects_return' => false,
            'max_minutes' => null,
            'sort_order' => 910,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        DB::table('movement_reasons')->where('code', 'arrival')->whereNotExists(function ($query) {
            $query->select(DB::raw(1))->from('movements')->whereColumn('movements.reason_id', 'movement_reasons.id');
        })->delete();
    }
};
