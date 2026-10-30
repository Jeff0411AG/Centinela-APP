<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Services;

use App\Enums\WorkerType;
use App\Models\Department;
use App\Models\Site;
use App\Models\Worker;
use App\Services\Concerns\ReadsCsv;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Loads the worker master list (a CSV exported from Excel) and keeps it in sync by document number.
 *
 * Accepted columns, in any order and with or without accents:
 *   dni | documento            document number (required, DNI has 8 digits)
 *   apellidos, nombres         or a single "trabajador" column written as "APELLIDOS, NOMBRES"
 *   tipo                       EMP, OBR, OPER, GG or ADM (the legacy spellings are understood)
 *   area                       department name or code; created when missing
 *   fotocheck | codigo         optional card code
 *   activo                     optional; "no", "0", "inactivo" or "cesado" deactivate the worker
 *
 * With $deactivateMissing the file is treated as the complete list of active workers: whoever is active in the
 * database but absent from the file is deactivated (never when any row was rejected, so a typo cannot deactivate anyone).
 */
class WorkerImporter
{
    use ReadsCsv;

    /** @var array<string, int> */
    private array $departmentIds = [];

    /** @var array<string, true> */
    private array $seenDocuments = [];

    /**
     * @return array{created: int, updated: int, unchanged: int, skipped: int, deactivated: int, errors: list<array{line: int, message: string}>}
     */
    public function import(string $path, bool $dryRun = false, bool $deactivateMissing = false): array
    {
        if (! is_readable($path)) {
            throw new InvalidArgumentException(__('The file cannot be read.'));
        }

        $result = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0, 'deactivated' => 0, 'errors' => []];
        $siteId = Site::default()->id;
        $this->seenDocuments = [];

        DB::beginTransaction();

        try {
            foreach ($this->readCsvRows($path, fn (string $name): ?string => $this->mapHeader($name), 'document', __('The file needs a "dni" column in its first row.')) as $line => $row) {
                try {
                    $outcome = $this->importRow($row, $siteId);
                    $result[$outcome]++;
                } catch (InvalidArgumentException $exception) {
                    $result['skipped']++;
                    $result['errors'][] = ['line' => $line, 'message' => $exception->getMessage()];
                }
            }

            if ($deactivateMissing) {
                $this->deactivateMissing($siteId, $result);
            }

            $dryRun ? DB::rollBack() : DB::commit();
        } catch (Throwable $exception) {
            DB::rollBack();

            throw $exception;
        } finally {
            $this->departmentIds = [];
            $this->seenDocuments = [];
        }

        return $result;
    }

    /**
     * @param  array{created: int, updated: int, unchanged: int, skipped: int, deactivated: int, errors: list<array{line: int, message: string}>}  $result
     */
    private function deactivateMissing(int $siteId, array &$result): void
    {
        if ($result['skipped'] > 0 || $this->seenDocuments === []) {
            $result['errors'][] = ['line' => 0, 'message' => __('Nobody was deactivated: the file has rejected rows or no workers. Fix the file and run it again.')];

            return;
        }

        Worker::query()
            ->where('site_id', $siteId)
            ->where('is_active', true)
            ->whereNotIn('document_number', array_keys($this->seenDocuments))
            ->each(function (Worker $worker) use (&$result): void {
                $worker->update(['is_active' => false]);
                $result['deactivated']++;
            });
    }

    /**
     * @param  array<string, string>  $row
     * @return 'created'|'updated'|'unchanged'
     */
    private function importRow(array $row, int $siteId): string
    {
        [$documentType, $documentNumber] = $this->parseDocument($row['document'] ?? '');
        [$lastName, $firstName] = $this->parseName($row);
        $this->seenDocuments[$documentNumber] = true;

        $type = WorkerType::fromLegacy($row['type'] ?? null);

        if ($type === null) {
            throw new InvalidArgumentException(__('Unknown worker type ":value". Use EMP, OBR, OPER, GG or ADM.', ['value' => trim($row['type'] ?? '')]));
        }

        $attributes = [
            'document_type' => $documentType,
            'last_name' => $lastName,
            'first_name' => $firstName,
            'worker_type' => $type,
            'department_id' => $this->resolveDepartmentId($row['department'] ?? '', $siteId),
            'is_active' => $this->parseActive($row['active'] ?? ''),
        ];

        if (filled($row['card'] ?? null)) {
            $attributes['card_code'] = trim($row['card']);
        }

        $worker = Worker::query()->where('document_number', $documentNumber)->first();

        if ($worker === null) {
            Worker::query()->create($attributes + ['document_number' => $documentNumber, 'site_id' => $siteId]);

            return 'created';
        }

        $worker->fill($attributes);

        if (! $worker->isDirty()) {
            return 'unchanged';
        }

        $worker->save();

        return 'updated';
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function parseDocument(string $value): array
    {
        $value = strtoupper(preg_replace('/[\s.\-]/', '', trim($value)) ?? '');

        if ($value === '') {
            throw new InvalidArgumentException(__('The document number is missing.'));
        }

        if (preg_match('/^\d{8}$/', $value) === 1) {
            return ['DNI', $value];
        }

        if (preg_match('/^[A-Z0-9]{9,12}$/', $value) === 1) {
            return ['CE', $value];
        }

        throw new InvalidArgumentException(__('The document number ":value" is not valid. A DNI has 8 digits.', ['value' => $value]));
    }

    /**
     * @param  array<string, string>  $row
     * @return array{0: string, 1: string}
     */
    private function parseName(array $row): array
    {
        $lastName = $this->collapse($row['last_name'] ?? '');
        $firstName = $this->collapse($row['first_name'] ?? '');

        if ($lastName === '' && $firstName === '' && filled($row['full_name'] ?? null)) {
            $parts = explode(',', $row['full_name'], 2);

            if (count($parts) === 2) {
                [$lastName, $firstName] = [$this->collapse($parts[0]), $this->collapse($parts[1])];
            }
        }

        if ($lastName === '' || $firstName === '') {
            throw new InvalidArgumentException(__('The last name and first name are required.'));
        }

        return [$lastName, $firstName];
    }

    private function resolveDepartmentId(string $name, int $siteId): ?int
    {
        $name = $this->collapse($name);

        if ($name === '') {
            return null;
        }

        $key = Str::upper($name);

        return $this->departmentIds[$key] ??= Department::query()
            ->firstOrCreate(['site_id' => $siteId, 'name' => $name], ['is_active' => true])
            ->id;
    }

    private function parseActive(string $value): bool
    {
        return ! in_array(Str::lower(trim($value)), ['no', 'n', '0', 'false', 'inactivo', 'inactiva', 'cesado', 'cesada'], true);
    }

    private function collapse(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    }

    private function mapHeader(string $name): ?string
    {
        $name = Str::of(Str::ascii($name))->lower()->replaceMatches('/[^a-z0-9]+/', ' ')->trim()->toString();

        return match ($name) {
            'dni', 'documento', 'nro documento', 'numero documento', 'document number', 'document' => 'document',
            'apellidos', 'apellido', 'last name' => 'last_name',
            'nombres', 'nombre', 'first name' => 'first_name',
            'trabajador', 'apellidos y nombres', 'apellidos nombres', 'nombre completo' => 'full_name',
            'tipo', 'tipo de trabajador', 'worker type' => 'type',
            'area', 'departamento', 'department' => 'department',
            'fotocheck', 'codigo', 'codigo fotocheck', 'card code' => 'card',
            'activo', 'estado', 'active' => 'active',
            default => null,
        };
    }
}
