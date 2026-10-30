<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Filament\Resources\MovementReasons\Pages;

use App\Filament\Resources\MovementReasons\MovementReasonResource;
use Filament\Resources\Pages\CreateRecord;

class CreateMovementReason extends CreateRecord
{
    protected static string $resource = MovementReasonResource::class;
}
