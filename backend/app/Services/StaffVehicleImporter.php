<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Services;

use App\Enums\VehicleKind;
use App\Models\Department;
use App\Models\Site;
use App\Models\StaffVehicle;
use App\Models\Worker;
use App\Services\Concerns\ReadsCsv;
use App\Support\Plate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Loads the registry of the staff's vehicles from a CSV exported from Excel; it can be run again.
 *
 * Accepted columns, in any order and with or without accents:
 *   propietario | dueno    owner's name (required)
 *   placa                  plate; bicycles and scooters do not have one
 *   tipo                   auto, moto, bicicleta, scooter or moto electrica; when missing it is guessed
 *                          from the plate (four digits and letters is a motorcycle, anything else a car)
 *   dni                    optional: links the vehicle to a worker of the master list
 *   marca, color, area, observaciones
 *   activo                 "no", "0", "inactivo" or "baja" deactivate it
 *
 * A plate that already belongs to another owner is not overwritten: the row is reported so someone decides.
 */
class StaffVehicleImporter
{
    use ReadsCsv;

    /** @var array<string, int> */
    private array $departmentIds = [];

    /**
     * @return array{created: int, updated: int, unchanged: int, skipped: int, errors: list<array{line: int, message: string}>}
     */
    public function import(string $path, bool $dryRun = false): array
    {
        if (! is_readable($path)) {
            throw new InvalidArgumentException(__('The file cannot be read.'));
        }

        $result = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0, 'errors' => []];
        $siteId = Site::default()->id;

        DB::beginTransaction();

        try {
            $rows = $this->readCsvRows($path, fn (string $name): ?string => $this->mapHeader($name), 'owner', __('The file needs a "propietario" column in its first row.'));

            foreach ($rows as $line => $row) {
                try {
                    $result[$this->importRow($row, $siteId)]++;
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

        $this->departmentIds = [];

        return $result;
    }

    /**
     * @param  array<string, string>  $row
     * @return 'created'|'updated'|'unchanged'
     */
    private function importRow(array $row, int $siteId): string
    {
        $ownerName = $this->collapse($row['owner'] ?? '');

        if ($ownerName === '') {
            throw new InvalidArgumentException(__('The owner is missing.'));
        }

        $plate = Plate::normalize($row['plate'] ?? '');

        if ($plate !== null && ! Plate::isValid($plate)) {
            throw new InvalidArgumentException(__('The plate ":value" is not valid.', ['value' => $row['plate']]));
        }

        $kind = $this->resolveKind($row['kind'] ?? '', $plate);
        $ownerId = $this->resolveOwnerId($row['document'] ?? '');

        $attributes = [
            'kind' => $kind,
            'owner_worker_id' => $ownerId,
            'owner_name' => $ownerName,
            'department_id' => $this->resolveDepartmentId($row['department'] ?? '', $siteId),
            'brand' => $this->nullable($row['brand'] ?? null),
            'color' => $this->nullable($row['color'] ?? null),
            'notes' => $this->nullable($row['notes'] ?? null),
            'is_active' => ! in_array(Str::lower(trim($row['active'] ?? '')), ['no', 'n', '0', 'false', 'inactivo', 'inactiva', 'baja'], true),
        ];

        $vehicle = $plate !== null
            ? StaffVehicle::query()->where('plate', $plate)->first()
            : StaffVehicle::query()->whereNull('plate')->where('owner_name', $ownerName)->where('kind', $kind->value)->first();

        if ($vehicle !== null && $plate !== null && Str::lower($vehicle->owner_name) !== Str::lower($ownerName)) {
            throw new InvalidArgumentException(__('The plate :plate is already registered to :owner.', ['plate' => Plate::format($plate), 'owner' => $vehicle->owner_name]));
        }

        if ($vehicle === null) {
            StaffVehicle::query()->create($attributes + ['plate' => $plate]);

            return 'created';
        }

        $vehicle->fill($attributes);

        if (! $vehicle->isDirty()) {
            return 'unchanged';
        }

        $vehicle->save();

        return 'updated';
    }

    private function resolveKind(string $value, ?string $plate): VehicleKind
    {
        if (trim($value) !== '') {
            return VehicleKind::fromLegacy($value)
                ?? throw new InvalidArgumentException(__('Unknown vehicle type ":value". Use auto, moto, bicicleta, scooter or moto electrica.', ['value' => trim($value)]));
        }

        if ($plate === null) {
            throw new InvalidArgumentException(__('A vehicle without a plate needs its type (bicicleta, scooter...).'));
        }

        return preg_match('/^\d{4}[A-Z]{1,2}$/', $plate) === 1 ? VehicleKind::Motorcycle : VehicleKind::Car;
    }

    private function resolveOwnerId(string $document): ?int
    {
        $document = strtoupper(preg_replace('/[\s.\-]/', '', trim($document)) ?? '');

        if ($document === '') {
            return null;
        }

        return Worker::query()->where('document_number', $document)->value('id')
            ?? throw new InvalidArgumentException(__('No worker has the document number ":value".', ['value' => $document]));
    }

    private function resolveDepartmentId(string $name, int $siteId): ?int
    {
        $name = $this->collapse($name);

        if ($name === '') {
            return null;
        }

        return $this->departmentIds[Str::upper($name)] ??= Department::query()
            ->firstOrCreate(['site_id' => $siteId, 'name' => $name], ['is_active' => true])
            ->id;
    }

    private function nullable(?string $value): ?string
    {
        $value = $this->collapse((string) $value);

        return $value === '' ? null : $value;
    }

    private function collapse(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    }

    private function mapHeader(string $name): ?string
    {
        $name = Str::of(Str::ascii($name))->lower()->replaceMatches('/[^a-z0-9]+/', ' ')->trim()->toString();

        return match ($name) {
            'propietario', 'dueno', 'owner', 'nombre' => 'owner',
            'placa', 'placas', 'plate' => 'plate',
            'tipo', 'tipo de vehiculo', 'vehiculo', 'kind' => 'kind',
            'dni', 'documento', 'dni propietario', 'document' => 'document',
            'marca', 'brand' => 'brand',
            'color' => 'color',
            'area', 'departamento', 'department' => 'department',
            'observaciones', 'observacion', 'notas', 'notes', 'obs' => 'notes',
            'activo', 'estado', 'active' => 'active',
            default => null,
        };
    }
}
