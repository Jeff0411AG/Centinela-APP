<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Services\Concerns;

use Generator;
use InvalidArgumentException;

/**
 * Reads the CSV files that Excel produces: UTF-8 with or without BOM or Windows-1252, and
 * comma, semicolon or tab separated.
 */
trait ReadsCsv
{
    /**
     * Yields the data rows keyed by their line number in the file, each one keyed by the field
     * names returned by $mapHeader (headers it maps to null are ignored).
     *
     * @param  callable(string): ?string  $mapHeader
     * @return Generator<int, array<string, string>>
     */
    protected function readCsvRows(string $path, callable $mapHeader, string $requiredField, string $missingMessage): Generator
    {
        $contents = (string) file_get_contents($path);
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;

        if (! mb_check_encoding($contents, 'UTF-8')) {
            $contents = mb_convert_encoding($contents, 'UTF-8', 'Windows-1252');
        }

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $contents);
        rewind($handle);

        $firstLine = (string) strtok($contents, "\n");
        $delimiter = collect([';' => substr_count($firstLine, ';'), ',' => substr_count($firstLine, ','), "\t" => substr_count($firstLine, "\t")])
            ->sortDesc()
            ->keys()
            ->first();

        $header = fgetcsv($handle, 0, $delimiter);

        if ($header === false || $header === [null]) {
            fclose($handle);

            return;
        }

        $map = array_map(fn (?string $name): ?string => $mapHeader((string) $name), $header);

        if (! in_array($requiredField, $map, true)) {
            fclose($handle);

            throw new InvalidArgumentException($missingMessage);
        }

        $line = 1;

        while (($cells = fgetcsv($handle, 0, $delimiter)) !== false) {
            $line++;

            if ($cells === [null] || count(array_filter($cells, fn ($cell): bool => trim((string) $cell) !== '')) === 0) {
                continue;
            }

            $row = [];

            foreach ($map as $index => $field) {
                if ($field !== null) {
                    $row[$field] = trim((string) ($cells[$index] ?? ''));
                }
            }

            yield $line => $row;
        }

        fclose($handle);
    }
}
