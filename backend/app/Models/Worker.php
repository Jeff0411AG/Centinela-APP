<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Models;

use App\Enums\MovementDirection;
use App\Enums\WorkerType;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Worker extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'document_type',
        'document_number',
        'last_name',
        'first_name',
        'worker_type',
        'department_id',
        'site_id',
        'card_code',
        'is_active',
    ];

    protected $casts = [
        'worker_type' => WorkerType::class,
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $worker): void {
            if (blank($worker->site_id)) {
                $worker->site_id = Site::default()->id;
            }

            if (blank($worker->document_type)) {
                $worker->document_type = 'DNI';
            }

            $worker->document_number = filled($worker->document_number) ? trim((string) $worker->document_number) : null;
            $worker->card_code = filled($worker->card_code) ? trim((string) $worker->card_code) : null;
        });
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function absences(): HasMany
    {
        return $this->hasMany(Absence::class);
    }

    /**
     * The absence covering the given day (today by default), if any.
     */
    public function absenceOn(?CarbonInterface $date = null): ?Absence
    {
        $date ??= today();

        if ($this->relationLoaded('absences')) {
            return $this->absences->first(fn (Absence $absence): bool => $absence->starts_on->lte($date) && $absence->ends_on->gte($date));
        }

        return $this->absences()->activeOn($date)->orderByDesc('ends_on')->first();
    }

    public function staffVehicles(): HasMany
    {
        return $this->hasMany(StaffVehicle::class, 'owner_worker_id');
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

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Matches a free-text search on the document number, first name or last name.
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        $term = trim($term);

        if ($term === '') {
            return $query;
        }

        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';

        return $query->where(function (Builder $query) use ($like, $term): void {
            $query->where('document_number', 'like', $like)
                ->orWhere('card_code', $term)
                ->orWhere('last_name', 'like', $like)
                ->orWhere('first_name', 'like', $like)
                ->orWhereRaw("CONCAT(last_name, ' ', first_name) like ?", [$like])
                ->orWhereRaw("CONCAT(first_name, ' ', last_name) like ?", [$like]);
        });
    }

    public function getFullNameAttribute(): string
    {
        return trim($this->last_name.' '.$this->first_name);
    }

    /**
     * Where the worker is right now, from the gate marks: outside while an exit is open (even from a
     * previous day), inside after an arrival or a return today, went home after the end of shift today,
     * and "not arrived" when there is no mark from today.
     *
     * @return 'not_arrived'|'inside'|'outside'|'went_home'
     */
    public function gateStatus(): string
    {
        $last = $this->latestMovement;

        if ($last === null) {
            return 'not_arrived';
        }

        if ($last->direction === MovementDirection::Exit) {
            return 'outside';
        }

        if (! $last->occurred_at->isToday()) {
            return 'not_arrived';
        }

        return $last->direction === MovementDirection::NoReturn ? 'went_home' : 'inside';
    }

    /**
     * A worker is outside when the most recent mark is an exit.
     */
    public function isOutside(): bool
    {
        return $this->latestMovement?->direction === MovementDirection::Exit;
    }
}
