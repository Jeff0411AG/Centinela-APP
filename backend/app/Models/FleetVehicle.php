<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Models;

use App\Enums\MovementDirection;
use App\Support\Plate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A company vehicle. Its exits and returns are marks in the gate log (movements), like the people's.
 */
class FleetVehicle extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'plate',
        'brand',
        'model',
        'color',
        'initial_odometer_km',
        'site_id',
        'is_active',
    ];

    protected $casts = [
        'initial_odometer_km' => 'integer',
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $vehicle): void {
            if (blank($vehicle->site_id)) {
                $vehicle->site_id = Site::default()->id;
            }
        });
    }

    public function setPlateAttribute(?string $value): void
    {
        $this->attributes['plate'] = Plate::normalize($value);
    }

    public function getPlateDisplayAttribute(): ?string
    {
        return Plate::format($this->plate);
    }

    /**
     * "ABC-123 · Hyundai H1", the way the guard recognises the vehicle.
     */
    public function getLabelAttribute(): string
    {
        return trim(($this->plate_display ?? '').' · '.trim(($this->brand ?? '').' '.($this->model ?? '')), ' ·');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(Movement::class);
    }

    public function latestMovement(): HasOne
    {
        return $this->hasOne(Movement::class)->ofMany(
            ['occurred_at' => 'max', 'id' => 'max'],
            fn ($query) => $query->effective(),
        );
    }

    public function isOutside(): bool
    {
        return $this->latestMovement?->direction === MovementDirection::Exit;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Matches the plate (ignoring dashes, spaces and case), the brand or the model.
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        $term = trim($term);

        if ($term === '') {
            return $query;
        }

        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
        $plate = Plate::normalize($term);

        return $query->where(function (Builder $query) use ($like, $plate): void {
            if ($plate !== null) {
                $query->where('plate', 'like', '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $plate).'%');
            }

            $query->orWhere('brand', 'like', $like)->orWhere('model', 'like', $like);
        });
    }

    /**
     * The odometer the next exit should start from: the last reading on a mark that still counts, or the
     * initial value loaded with the vehicle.
     */
    public function currentOdometerKm(): ?int
    {
        $last = Movement::query()
            ->effective()
            ->where('fleet_vehicle_id', $this->id)
            ->whereNotNull('odometer_km')
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->value('odometer_km');

        return $last !== null ? (int) $last : $this->initial_odometer_km;
    }
}
