<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Services;

use App\Models\FleetVehicle;
use App\Services\Concerns\ReadsCsv;
use App\Support\Plate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Loads the company vehicles from a CSV exported from Excel; it can be run again to update them.
 *
 * Accepted columns, in any order and with or without accents:
 *   placa              plate (required); dashes and spaces do not matter
 *   marca, modelo, color
 *   km | kilometraje   odometer the system starts from
 *   activo             "no", "0", "inactivo" or "baja" deactivate the vehicle
 */
class FleetVehicleImporter
{
    use ReadsCsv;

    /**
     * @return array{created: int, updated: int, unchanged: int, skipped: int, errors: list<array{line: int, message: string}>}
     */
    public function import(string $path, bool $dryRun = false): array
    {
        if (! is_readable($path)) {
            throw new InvalidArgumentException(__('The file cannot be read.'));
        }

        $result = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0, 'errors' => []];

        DB::beginTransaction();

        try {
            $rows = $this->readCsvRows($path, fn (string $name): ?string => $this->mapHeader($name), 'plate', __('The file needs a "placa" column in its first row.'));

            foreach ($rows as $line => $row) {
                try {
                    $result[$this->importRow($row)]++;
                } catch (InvalidArgumentException $exception) {
                    $result['skipped']++;
                    $result['errors'][] = ['line' => $line, 'message' => $exception->getMessage()];
                }
            }

            $dryRun ? DB::rollBack() : DB::commit();
        } catch (Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }

        return $result;
    }

    /**
     * @param  array<string, string>  $row
     * @return 'created'|'updated'|'unchanged'
     */
    private function importRow(array $row): string
    {
        $plate = Plate::normalize($row['plate'] ?? '');

        if ($plate === null) {
            throw new InvalidArgumentException(__('The plate is missing.'));
        }

        if (! Plate::isValid($plate)) {
            throw new InvalidArgumentException(__('The plate ":value" is not valid.', ['value' => $row['plate']]));
        }

        $odometer = null;

        if (filled($row['odometer'] ?? null)) {
            $digits = preg_replace('/\D/', '', $row['odometer']) ?? '';

            if ($digits === '') {
                throw new InvalidArgumentException(__('The odometer ":value" is not a number.', ['value' => $row['odometer']]));
            }

            $odometer = (int) $digits;
        }

        $attributes = [
            'brand' => $this->text($row['brand'] ?? null),
            'model' => $this->text($row['model'] ?? null),
            'color' => $this->text($row['color'] ?? null),
            'is_active' => ! in_array(Str::lower(trim($row['active'] ?? '')), ['no', 'n', '0', 'false', 'inactivo', 'inactiva', 'baja'], true),
        ];

        if ($odometer !== null) {
            $attributes['initial_odometer_km'] = $odometer;
        }

        $vehicle = FleetVehicle::query()->where('plate', $plate)->first();

        if ($vehicle === null) {
            FleetVehicle::query()->create($attributes + ['plate' => $plate]);

            return 'created';
        }

        $vehicle->fill($attributes);

        if (! $vehicle->isDirty()) {
            return 'unchanged';
        }

        $vehicle->save();

        return 'updated';
    }

    private function text(?string $value): ?string
    {
        $value = trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');

        return $value === '' ? null : $value;
    }

    private function mapHeader(string $name): ?string
    {
        $name = Str::of(Str::ascii($name))->lower()->replaceMatches('/[^a-z0-9]+/', ' ')->trim()->toString();

        return match ($name) {
            'placa', 'placas', 'plate', 'nro placa', 'numero de placa' => 'plate',
            'marca', 'brand' => 'brand',
            'modelo', 'model' => 'model',
            'color' => 'color',
            'km', 'kilometraje', 'odometro', 'km actual', 'km inicial', 'odometer' => 'odometer',
            'activo', 'estado', 'active' => 'active',
            default => null,
        };
    }
}
