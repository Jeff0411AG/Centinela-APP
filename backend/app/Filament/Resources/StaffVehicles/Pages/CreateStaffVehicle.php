<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Filament\Resources\StaffVehicles\Pages;

use App\Filament\Resources\StaffVehicles\StaffVehicleResource;
use Filament\Resources\Pages\CreateRecord;

class CreateStaffVehicle extends CreateRecord
{
    protected static string $resource = StaffVehicleResource::class;
}
