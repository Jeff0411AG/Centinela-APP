<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Support;

use DateTimeInterface;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Small wrapper around OpenSpout (installed with Filament) to write a workbook with several sheets:
 * a bold header row, a width per column and real dates that Excel can sort and filter.
 */
final class XlsxExport
{
    private Writer $writer;

    private string $path;

    private bool $firstSheet = true;

    /** @var list<string> */
    private array $usedNames = [];

    private Style $headerStyle;

    private Style $dateStyle;

    private Style $dateTimeStyle;

    private function __construct()
    {
        $this->path = tempnam(sys_get_temp_dir(), 'gate-export-').'.xlsx';
        $this->writer = new Writer(new Options);
        $this->writer->openToFile($this->path);

        $this->headerStyle = (new Style)->setFontBold()->setBackgroundColor('E5E7EB');
        $this->dateStyle = (new Style)->setFormat('dd/mm/yyyy');
        $this->dateTimeStyle = (new Style)->setFormat('dd/mm/yyyy hh:mm');
    }

    public static function make(): self
    {
        return new self;
    }

    /**
     * @param  list<string>  $headers
     * @param  iterable<list<mixed>>  $rows
     * @param  list<float>  $widths  column widths in characters; defaults to 18
     */
    public function sheet(string $title, array $headers, iterable $rows, array $widths = []): self
    {
        $sheet = $this->firstSheet ? $this->writer->getCurrentSheet() : $this->writer->addNewSheetAndMakeItCurrent();
        $this->firstSheet = false;

        $sheet->setName($this->uniqueName($title));

        foreach ($headers as $index => $header) {
            $sheet->setColumnWidth((float) ($widths[$index] ?? 18), $index + 1);
        }

        $this->writer->addRow(Row::fromValues($headers, $this->headerStyle));

        foreach ($rows as $row) {
            $styles = [];

            foreach (array_values($row) as $index => $value) {
                if ($value instanceof DateTimeInterface) {
                    $styles[$index] = $value->format('H:i:s') === '00:00:00' ? $this->dateStyle : $this->dateTimeStyle;
                }
            }

            $this->writer->addRow(Row::fromValuesWithStyles(array_values($row), null, $styles));
        }

        return $this;
    }

    /**
     * Closes the workbook and returns the path of the temporary file; the caller deletes it.
     */
    public function save(): string
    {
        $this->writer->close();

        return $this->path;
    }

    private function uniqueName(string $title): string
    {
        $name = trim(preg_replace('/[\[\]:*?\/\\\\]/', ' ', $title) ?? '');
        $name = mb_substr($name === '' ? 'Hoja' : $name, 0, 31);
        $candidate = $name;
        $counter = 2;

        while (in_array(mb_strtolower($candidate), $this->usedNames, true)) {
            $suffix = ' '.$counter++;
            $candidate = mb_substr($name, 0, 31 - mb_strlen($suffix)).$suffix;
        }

        $this->usedNames[] = mb_strtolower($candidate);

        return $candidate;
    }
}
