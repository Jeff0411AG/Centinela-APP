<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Filament\Resources\FleetVehicles\Pages;

use App\Filament\Concerns\HasCsvImportAction;
use App\Filament\Resources\FleetVehicles\FleetVehicleResource;
use App\Models\FleetVehicle;
use App\Services\FleetVehicleImporter;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFleetVehicles extends ListRecords
{
    use HasCsvImportAction;

    protected static string $resource = FleetVehicleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->csvImportAction(
                FleetVehicleImporter::class,
                FleetVehicle::class,
                __('Columns: placa, marca, modelo, color, km (starting odometer) and activo. Save the Excel sheet as CSV first.'),
            ),
            CreateAction::make(),
        ];
    }
}
