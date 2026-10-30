<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Filament\Resources\StaffVehicles\Pages;

use App\Filament\Concerns\HasCsvImportAction;
use App\Filament\Resources\StaffVehicles\StaffVehicleResource;
use App\Models\StaffVehicle;
use App\Services\StaffVehicleImporter;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListStaffVehicles extends ListRecords
{
    use HasCsvImportAction;

    protected static string $resource = StaffVehicleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->csvImportAction(
                StaffVehicleImporter::class,
                StaffVehicle::class,
                __('Columns: propietario, placa, tipo (auto, moto, bicicleta, scooter, moto electrica), dni (optional), marca, color, area, observaciones and activo. Save the Excel sheet as CSV first.'),
            ),
            CreateAction::make(),
        ];
    }
}
