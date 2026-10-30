<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Support;

use App\Models\Movement;

/**
 * One trip of a worker or of a vehicle: an exit and the mark that closed it.
 */
final class Trip
{
    /** The worker came back. */
    public const CLOSED = 'closed';

    /** The exit was closed as "did not return". */
    public const NO_RETURN = 'no_return';

    /** Another exit followed without a return in between: the first exit was never closed. */
    public const UNCLOSED = 'unclosed';

    /** The worker is still outside and nothing has closed the exit yet. */
    public const OPEN = 'open';

    public function __construct(
        public readonly Movement $exit,
        public readonly ?Movement $return,
        public readonly string $status,
    ) {}

    /**
     * Minutes outside; only known when the worker came back.
     */
    public function minutes(): ?int
    {
        if ($this->status !== self::CLOSED || $this->return === null) {
            return null;
        }

        return max(0, (int) $this->exit->occurred_at->diffInMinutes($this->return->occurred_at, true));
    }

    /**
     * Kilometres travelled; only known when the trip is closed and both readings were written and make sense.
     */
    public function distanceKm(): ?int
    {
        if ($this->status !== self::CLOSED || $this->return === null) {
            return null;
        }

        $out = $this->exit->odometer_km;
        $back = $this->return->odometer_km;

        return $out !== null && $back !== null && $back >= $out ? $back - $out : null;
    }

    public function reasonCode(): ?string
    {
        return $this->exit->reason?->code;
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::CLOSED => __('Returned'),
            self::NO_RETURN => __('Did not return'),
            self::UNCLOSED => __('Never closed'),
            default => __('Still outside'),
        };
    }
}
