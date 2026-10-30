<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MovementReason extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'name',
        'applies_to',
        'requires_destination',
        'requires_route',
        'expects_return',
        'max_minutes',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'requires_destination' => 'boolean',
        'requires_route' => 'boolean',
        'expects_return' => 'boolean',
        'max_minutes' => 'integer',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function movements(): HasMany
    {
        return $this->hasMany(Movement::class, 'reason_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeForWorkers(Builder $query): Builder
    {
        return $query->where('applies_to', 'worker');
    }

    public function scopeForVehicles(Builder $query): Builder
    {
        return $query->where('applies_to', 'vehicle');
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }
}
