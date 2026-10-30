<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Models;

use App\Enums\MovementDirection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * One gate mark (an exit, an entry or a "did not return" closing).
 *
 * Rows are append-only: the database rejects UPDATE and DELETE through triggers and
 * this model refuses them earlier, with a clearer error. Mistakes are fixed by inserting
 * a new row that points at the original through corrects_movement_id.
 */
class Movement extends Model
{
    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'worker_id',
        'visitor_id',
        'visit_id',
        'fleet_vehicle_id',
        'site_id',
        'direction',
        'occurred_at',
        'reason_id',
        'destination_id',
        'route_number',
        'driver_worker_id',
        'copilot_worker_id',
        'guide_number',
        'odometer_km',
        'checklist',
        'notes',
        'registered_by_user_id',
        'exit_movement_id',
        'corrects_movement_id',
        'correction_reason',
        'manual_time',
        'manual_time_reason',
    ];

    protected $casts = [
        'direction' => MovementDirection::class,
        'occurred_at' => 'datetime',
        'manual_time' => 'boolean',
        'odometer_km' => 'integer',
        'checklist' => 'array',
    ];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Movements are append-only and cannot be updated.');
        });

        static::deleting(function (): never {
            throw new LogicException('Movements are append-only and cannot be deleted.');
        });

        static::creating(function (self $movement): void {
            if (collect([$movement->worker_id, $movement->visitor_id, $movement->fleet_vehicle_id])->filter(fn ($id) => filled($id))->count() !== 1) {
                throw new \InvalidArgumentException('A mark needs exactly one subject: a worker, a visitor or a vehicle.');
            }

            if (filled($movement->corrects_movement_id) && blank(trim((string) $movement->correction_reason))) {
                throw new \InvalidArgumentException('A correction needs a written reason.');
            }

            if ($movement->direction === MovementDirection::Void && blank($movement->corrects_movement_id)) {
                throw new \InvalidArgumentException('A void mark must point at the mark it annuls.');
            }

            if ($movement->manual_time && blank(trim((string) $movement->manual_time_reason))) {
                throw new \InvalidArgumentException('A manual time needs a written reason.');
            }

            if (blank($movement->occurred_at)) {
                $movement->occurred_at = now();
            }

            if (blank($movement->site_id)) {
                $movement->site_id = Site::default()->id;
            }
        });
    }

    /**
     * Marks that count: not annulled, and not replaced by a later correction. The log keeps the others
     * (struck through) as the trail of what was corrected and why.
     */
    public function scopeEffective(Builder $query): Builder
    {
        return $query
            ->where('movements.direction', '<>', MovementDirection::Void->value)
            ->whereNotExists(fn ($sub) => $sub->selectRaw('1')->from('movements as c')->whereColumn('c.corrects_movement_id', 'movements.id'));
    }

    /**
     * Marks that a correction or a void replaced.
     */
    public function scopeSuperseded(Builder $query): Builder
    {
        return $query->whereExists(fn ($sub) => $sub->selectRaw('1')->from('movements as c')->whereColumn('c.corrects_movement_id', 'movements.id'));
    }

    /**
     * Marks that are themselves a correction or a void.
     */
    public function scopeOnlyCorrections(Builder $query): Builder
    {
        return $query->whereNotNull('movements.corrects_movement_id');
    }

    /**
     * Keeps only the most recent effective mark of each worker.
     */
    public function scopeLatestPerWorker(Builder $query): Builder
    {
        return $query->whereNotNull('movements.worker_id')->whereRaw(
            "movements.id = (select m2.id from movements m2 where m2.worker_id = movements.worker_id and m2.direction <> 'void' and not exists (select 1 from movements c2 where c2.corrects_movement_id = m2.id) order by m2.occurred_at desc, m2.id desc limit 1)"
        );
    }

    /**
     * Workers whose last effective mark is an exit: the people who are outside right now.
     */
    public function scopeOutsideNow(Builder $query): Builder
    {
        return $query->latestPerWorker()->where('movements.direction', MovementDirection::Exit->value);
    }

    /**
     * Keeps only the most recent effective mark of each fleet vehicle.
     */
    public function scopeLatestPerFleetVehicle(Builder $query): Builder
    {
        return $query->whereNotNull('movements.fleet_vehicle_id')->whereRaw(
            "movements.id = (select m2.id from movements m2 where m2.fleet_vehicle_id = movements.fleet_vehicle_id and m2.direction <> 'void' and not exists (select 1 from movements c2 where c2.corrects_movement_id = m2.id) order by m2.occurred_at desc, m2.id desc limit 1)"
        );
    }

    /**
     * Vehicles whose last effective mark is an exit: the ones that are outside right now.
     */
    public function scopeVehiclesOutsideNow(Builder $query): Builder
    {
        return $query->latestPerFleetVehicle()->where('movements.direction', MovementDirection::Exit->value);
    }

    /**
     * "Frontal: Rayadura; Posterior: Chocado", or null when the checklist has no findings.
     */
    public function checklistSummary(): ?string
    {
        $labels = ['front' => __('Front'), 'right' => __('Right side'), 'left' => __('Left side'), 'rear' => __('Rear')];
        $parts = [];

        foreach ($labels as $side => $label) {
            $text = trim((string) ($this->checklist[$side] ?? ''));

            if ($text !== '') {
                $parts[] = $label.': '.$text;
            }
        }

        return $parts === [] ? null : implode('; ', $parts);
    }

    public function isEffective(): bool
    {
        return $this->direction !== MovementDirection::Void
            && ! static::query()->where('corrects_movement_id', $this->id)->exists();
    }

    public function corrections(): HasMany
    {
        return $this->hasMany(self::class, 'corrects_movement_id');
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }

    public function visitor(): BelongsTo
    {
        return $this->belongsTo(Visitor::class);
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function fleetVehicle(): BelongsTo
    {
        return $this->belongsTo(FleetVehicle::class);
    }

    public function copilot(): BelongsTo
    {
        return $this->belongsTo(Worker::class, 'copilot_worker_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function reason(): BelongsTo
    {
        return $this->belongsTo(MovementReason::class, 'reason_id');
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(Destination::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Worker::class, 'driver_worker_id');
    }

    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by_user_id');
    }

    public function exitMovement(): BelongsTo
    {
        return $this->belongsTo(self::class, 'exit_movement_id');
    }

    public function corrects(): BelongsTo
    {
        return $this->belongsTo(self::class, 'corrects_movement_id');
    }
}
