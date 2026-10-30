@php
    $reports = $this->reports();
    $limit = 25;
    $format = function ($value): string {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('H:i:s') === '00:00:00' ? $value->format('d/m/Y') : $value->format('d/m/Y H:i');
        }

        if ($value === null || $value === '') {
            return '—';
        }

        return (string) $value;
    };
@endphp

<x-filament-panels::page>
    <style>
        .gate-report-table { width: 100%; border-collapse: collapse; font-size: 0.875rem; }
        .gate-report-table th, .gate-report-table td { padding: 0.4rem 0.75rem; text-align: left; border-bottom: 1px solid rgba(128, 128, 128, 0.25); white-space: nowrap; }
        .gate-report-table th { font-weight: 600; }
        .gate-report-table td.is-number { text-align: right; }
        .gate-report-filters { display: flex; flex-wrap: wrap; gap: 1rem; align-items: flex-end; }
        .gate-report-filters label { display: block; font-size: 0.8rem; margin-bottom: 0.25rem; }
        .gate-report-note { font-size: 0.8rem; opacity: 0.7; margin-top: 0.5rem; }
    </style>

    <x-filament::section>
        <div class="gate-report-filters">
            <div>
                <label for="gate-from">{{ __('Von') }}</label>
                <x-filament::input.wrapper>
                    <x-filament::input id="gate-from" type="date" wire:model.live="from" />
                </x-filament::input.wrapper>
            </div>
            <div>
                <label for="gate-until">{{ __('bis') }}</label>
                <x-filament::input.wrapper>
                    <x-filament::input id="gate-until" type="date" wire:model.live="until" />
                </x-filament::input.wrapper>
            </div>
            <div>
                <label for="gate-department">{{ __('Abteilung') }}</label>
                <x-filament::input.wrapper>
                    <x-filament::input.select id="gate-department" wire:model.live="departmentId">
                        <option value="">{{ __('Alle') }}</option>
                        @foreach ($this->departments() as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </div>
            <div>
                <x-filament::button wire:click="export" icon="heroicon-o-arrow-down-tray" color="gray">{{ __('Export to Excel') }}</x-filament::button>
            </div>
        </div>

        @if ($this->departmentId)
            <p class="gate-report-note">{{ __('The area filter does not apply to the fleet reports.') }}</p>
        @endif

        @if ($error = $this->rangeError())
            <p class="gate-report-note" style="color: rgb(220, 38, 38); opacity: 1;">{{ $error }}</p>
        @endif
    </x-filament::section>

    @if ($reports)
        @foreach ($reports as $key => $report)
            <x-filament::section :heading="$report['title'].' · '.count($report['rows'])" :collapsed="! in_array($key, ['by_reason', 'lunch_by_area'], true)" collapsible>
                @if (count($report['rows']) === 0)
                    <p class="gate-report-note">{{ __('No data for this range.') }}</p>
                @else
                    <div style="overflow-x: auto;">
                        <table class="gate-report-table">
                            <thead>
                                <tr>
                                    @foreach ($report['headers'] as $header)
                                        <th>{{ $header }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @foreach (array_slice($report['rows'], 0, $limit) as $row)
                                    <tr>
                                        @foreach ($row as $cell)
                                            <td class="{{ is_int($cell) || is_float($cell) ? 'is-number' : '' }}">{{ $format($cell) }}</td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @if (count($report['rows']) > $limit)
                        <p class="gate-report-note">{{ __('Showing :shown of :total rows. The Excel file has all of them.', ['shown' => $limit, 'total' => count($report['rows'])]) }}</p>
                    @endif
                @endif
            </x-filament::section>
        @endforeach
    @endif
</x-filament-panels::page>
