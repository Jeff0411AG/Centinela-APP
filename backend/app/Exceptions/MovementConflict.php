<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Exceptions;

use App\Models\Movement;
use Carbon\CarbonInterface;
use RuntimeException;

/**
 * Raised when a mark does not fit the person's current state (for example a second exit while
 * they are already outside, or an exit for someone who is on vacation). The gate screen asks the
 * guard to confirm before forcing it.
 */
class MovementConflict extends RuntimeException
{
    public const ALREADY_OUTSIDE = 'already_outside';

    public const NOT_OUTSIDE = 'not_outside';

    public const ABSENT = 'absent';

    /** A vehicle's starting odometer is not the one of its last return (use that nobody wrote down). */
    public const ODOMETER_MISMATCH = 'odometer_mismatch';

    /** A vehicle's return odometer is lower than its exit odometer. */
    public const ODOMETER_LOWER = 'odometer_lower';

    public function __construct(
        public readonly string $conflict,
        public readonly ?Movement $lastMovement = null,
        public readonly ?CarbonInterface $absentUntil = null,
        public readonly ?int $expectedKm = null,
    ) {
        parent::__construct($conflict);
    }
}
