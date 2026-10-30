<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Filament\Pages;

use App\Models\Department;
use App\Services\GateReports as GateReportsService;
use App\Support\XlsxExport;
use BackedEnum;
use Carbon\Carbon;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class GateReports extends Page
{
    /** Longer ranges are refused to keep the report fast; the log export has no such limit. */
    private const MAX_DAYS = 366;

    protected static string|null|BackedEnum $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?int $navigationSort = 6;

    protected string $view = 'filament.pages.gate-reports';

    public string $from = '';

    public string $until = '';

    public ?string $departmentId = null;

    public static function getNavigationGroup(): ?string
    {
        return __('Gate');
    }

    public static function getNavigationLabel(): string
    {
        return __('Reports');
    }

    public function getTitle(): string|Htmlable
    {
        return __('Gate reports');
    }

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('View:GateReport');
    }

    public function mount(): void
    {
        $this->from = now()->startOfMonth()->toDateString();
        $this->until = today()->toDateString();
    }

    /**
     * @return array<string, string>
     */
    public function departments(): array
    {
        return Department::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * Null when the dates are not valid.
     *
     * @return array<string, array{title: string, headers: list<string>, rows: list<list<mixed>>}>|null
     */
    public function reports(): ?array
    {
        $range = $this->range();

        if ($range === null) {
            return null;
        }

        return app(GateReportsService::class)->build(
            $range[0],
            $range[1],
            $this->departmentId ? (int) $this->departmentId : null,
            (bool) auth()->user()?->can('ViewAny:Absence'),
        );
    }

    public function rangeError(): ?string
    {
        if ($this->range() !== null) {
            return null;
        }

        return __('Enter a valid range of dates, up to :days days.', ['days' => self::MAX_DAYS]);
    }

    public function export(): ?BinaryFileResponse
    {
        abort_unless(static::canAccess(), 403);

        $reports = $this->reports();

        if ($reports === null) {
            return null;
        }

        $workbook = XlsxExport::make();

        foreach ($reports as $report) {
            $workbook->sheet($report['title'], $report['headers'], $report['rows']);
        }

        return response()
            ->download($workbook->save(), 'reportes-garita-'.$this->from.'_'.$this->until.'.xlsx')
            ->deleteFileAfterSend(true);
    }

    /**
     * @return array{0: Carbon, 1: Carbon}|null
     */
    private function range(): ?array
    {
        try {
            $from = Carbon::createFromFormat('Y-m-d', $this->from)->startOfDay();
            $until = Carbon::createFromFormat('Y-m-d', $this->until)->endOfDay();
        } catch (Throwable) {
            return null;
        }

        if ($until->lt($from) || $from->diffInDays($until) > self::MAX_DAYS) {
            return null;
        }

        return [$from, $until];
    }
}
