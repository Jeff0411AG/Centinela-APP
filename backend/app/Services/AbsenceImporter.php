<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Services;

use App\Enums\AbsenceType;
use App\Models\Absence;
use App\Models\User;
use App\Models\Worker;
use App\Services\Concerns\ReadsCsv;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Loads vacations and medical leaves from a CSV exported from Excel. Workers are matched by document
 * number, so the master list must be loaded first. Running it twice does not duplicate anything.
 *
 * Accepted columns, in any order and with or without accents:
 *   dni | documento       document number (required)
 *   desde | inicio        first day of the absence (required)
 *   hasta | fin           last day of the absence (required)
 *   retorno               optional first working day after it
 *   tipo                  vacaciones, descanso medico or licencia; vacaciones when the column is missing
 *   notas | observaciones optional
 */
class AbsenceImporter
{
    use ReadsCsv;

    /** Longer periods are almost always a typo in a date. */
    private const MAX_DAYS = 366;

    /**
     * @return array{created: int, updated: int, unchanged: int, skipped: int, errors: list<array{line: int, message: string}>}
     */
    public function import(string $path, bool $dryRun = false, ?User $actor = null): array
    {
        if (! is_readable($path)) {
            throw new InvalidArgumentException(__('The file cannot be read.'));
        }

        $result = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0, 'errors' => []];

        DB::beginTransaction();

        try {
            $rows = $this->readCsvRows($path, fn (string $name): ?string => $this->mapHeader($name), 'document', __('The file needs a "dni" column in its first row.'));

            foreach ($rows as $line => $row) {
                try {
                    $result[$this->importRow($row, $actor)]++;
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
    private function importRow(array $row, ?User $actor): string
    {
        $document = strtoupper(preg_replace('/[\s.\-]/', '', $row['document'] ?? '') ?? '');

        if ($document === '') {
            throw new InvalidArgumentException(__('The document number is missing.'));
        }

        $worker = Worker::query()->where('document_number', $document)->first();

        if ($worker === null) {
            throw new InvalidArgumentException(__('No worker has the document number ":value".', ['value' => $document]));
        }

        $type = array_key_exists('type', $row) ? AbsenceType::fromLegacy($row['type']) : AbsenceType::Vacation;

        if ($type === null) {
            throw new InvalidArgumentException(__('Unknown absence type ":value". Use vacaciones, descanso medico or licencia.', ['value' => $row['type'] ?? '']));
        }

        $startsOn = $this->parseDate($row['starts'] ?? '', __('start date'));
        $endsOn = $this->parseDate($row['ends'] ?? '', __('end date'));

        if ($endsOn->lt($startsOn)) {
            throw new InvalidArgumentException(__('The end date is before the start date.'));
        }

        $days = $startsOn->diffInDays($endsOn) + 1;

        if ($days > self::MAX_DAYS) {
            throw new InvalidArgumentException(__('The absence lasts :days days. Check the dates.', ['days' => $days]));
        }

        $returnsOn = filled($row['returns'] ?? null) ? $this->parseDate($row['returns'], __('return date')) : null;
        $notes = filled($row['notes'] ?? null) ? mb_substr(trim($row['notes']), 0, 500) : null;

        $absence = Absence::query()->firstOrNew([
            'worker_id' => $worker->id,
            'type' => $type->value,
            'starts_on' => $startsOn->toDateString(),
            'ends_on' => $endsOn->toDateString(),
        ]);

        $created = ! $absence->exists;
        $absence->returns_on = $returnsOn?->toDateString();
        $absence->notes = $notes;

        if ($created) {
            $absence->created_by_user_id = $actor?->id;
        } elseif (! $absence->isDirty()) {
            return 'unchanged';
        }

        $absence->save();

        return $created ? 'created' : 'updated';
    }

    private function parseDate(string $value, string $label): CarbonImmutable
    {
        $value = trim($value);

        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $value, $m) === 1) {
            [$year, $month, $day] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('#^(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{2,4})#', $value, $m) === 1) {
            [$day, $month, $year] = [(int) $m[1], (int) $m[2], (int) $m[3]];
            $year = $year < 100 ? 2000 + $year : $year;
        } else {
            throw new InvalidArgumentException(__('The :field ":value" is not a valid date. Use dd/mm/yyyy.', ['field' => $label, 'value' => $value]));
        }

        if (! checkdate($month, $day, $year)) {
            throw new InvalidArgumentException(__('The :field ":value" is not a valid date. Use dd/mm/yyyy.', ['field' => $label, 'value' => $value]));
        }

        return CarbonImmutable::create($year, $month, $day)->startOfDay();
    }

    private function mapHeader(string $name): ?string
    {
        $name = Str::of(Str::ascii($name))->lower()->replaceMatches('/[^a-z0-9]+/', ' ')->trim()->toString();

        return match ($name) {
            'dni', 'documento', 'nro documento', 'numero documento', 'document number', 'document' => 'document',
            'desde', 'inicio', 'fecha inicio', 'fecha desde', 'start', 'starts on' => 'starts',
            'hasta', 'fin', 'fecha fin', 'fecha hasta', 'end', 'ends on' => 'ends',
            'retorno', 'fecha retorno', 'reincorporacion', 'return', 'returns on' => 'returns',
            'tipo', 'tipo de ausencia', 'ausencia', 'type' => 'type',
            'notas', 'observaciones', 'notes' => 'notes',
            default => null,
        };
    }
}
