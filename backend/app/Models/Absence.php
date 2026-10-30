<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Models;

use App\Enums\AbsenceType;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A period in which a worker is not expected at work (vacation, medical leave...).
 * The guard only learns that the person is absent, never the type: medical leave is health data.
 */
class Absence extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'worker_id',
        'type',
        'starts_on',
        'ends_on',
        'returns_on',
        'notes',
        'created_by_user_id',
    ];

    protected $casts = [
        'type' => AbsenceType::class,
        'starts_on' => 'date',
        'ends_on' => 'date',
        'returns_on' => 'date',
    ];

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Absences that cover the given day, both ends included.
     */
    public function scopeActiveOn(Builder $query, CarbonInterface $date): Builder
    {
        return $query->whereDate('starts_on', '<=', $date)->whereDate('ends_on', '>=', $date);
    }

    /**
     * Absences that end between today and the given limit, so the people about to come back.
     */
    public function scopeEndingBetween(Builder $query, CarbonInterface $from, CarbonInterface $until): Builder
    {
        return $query->whereDate('ends_on', '>=', $from)->whereDate('ends_on', '<=', $until);
    }

    /**
     * The first working day after the absence: the stated return date, or the day after it ends.
     */
    public function getReturnDateAttribute(): CarbonInterface
    {
        return $this->returns_on ?? $this->ends_on->copy()->addDay();
    }
}
