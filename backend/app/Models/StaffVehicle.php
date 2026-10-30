<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Models;

use App\Enums\VehicleKind;
use App\Support\Plate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A vehicle that belongs to someone of the staff (car, motorcycle, bicycle...). It is a lookup list so the
 * guard can tell whether a vehicle is authorised; its entries and exits are not logged.
 */
class StaffVehicle extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'kind',
        'plate',
        'owner_worker_id',
        'owner_name',
        'department_id',
        'brand',
        'color',
        'notes',
        'is_active',
    ];

    protected $casts = [
        'kind' => VehicleKind::class,
        'is_active' => 'boolean',
    ];

    public function setPlateAttribute(?string $value): void
    {
        $this->attributes['plate'] = Plate::normalize($value);
    }

    public function getPlateDisplayAttribute(): ?string
    {
        return Plate::format($this->plate);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Worker::class, 'owner_worker_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Matches the plate (ignoring dashes, spaces and case), the owner, the brand or the color.
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        $term = trim($term);

        if ($term === '') {
            return $query;
        }

        $escape = fn (string $value): string => str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
        $plate = Plate::normalize($term);

        return $query->where(function (Builder $query) use ($escape, $term, $plate): void {
            if ($plate !== null) {
                $query->where('plate', 'like', '%'.$escape($plate).'%');
            }

            $query->orWhere('owner_name', 'like', '%'.$escape($term).'%')
                ->orWhere('brand', 'like', '%'.$escape($term).'%')
                ->orWhere('color', 'like', '%'.$escape($term).'%');
        });
    }
}
