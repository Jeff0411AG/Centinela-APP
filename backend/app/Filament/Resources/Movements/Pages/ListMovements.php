<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Filament\Resources\Movements\Pages;

use App\Enums\MovementDirection;
use App\Filament\Resources\Movements\MovementResource;
use App\Models\Movement;
use App\Models\Worker;
use App\Services\MovementService;
use App\Support\XlsxExport;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use InvalidArgumentException;

class ListMovements extends ListRecords
{
    private const EXPORT_LIMIT = 100000;

    protected static string $resource = MovementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')
                ->label(__('Export to Excel'))
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->visible(fn (): bool => (bool) auth()->user()?->can('viewAny', Movement::class))
                ->action(function () {
                    $query = $this->getFilteredSortedTableQuery();

                    if ($query === null || $query->count() > self::EXPORT_LIMIT) {
                        Notification::make()->danger()->title(__('Too many rows to export. Narrow the filters first.'))->send();

                        return null;
                    }

                    $rows = (function () use ($query) {
                        foreach ($query->with(['worker.department', 'visitor', 'fleetVehicle', 'reason', 'destination', 'driver', 'copilot', 'registeredBy'])->lazy(500) as $mark) {
                            yield [
                                $mark->occurred_at,
                                $mark->worker?->full_name ?? $mark->fleetVehicle?->label ?? trim(($mark->visitor?->first_name ?? '').' '.($mark->visitor?->name ?? '')),
                                $mark->worker_id ? __('Worker') : ($mark->fleet_vehicle_id ? __('Company vehicle') : __('Visitor')),
                                $mark->worker?->document_number,
                                $mark->worker?->department?->name,
                                $mark->direction->label(),
                                MovementResource::statusLabel($mark),
                                $mark->reason?->name,
                                $mark->destination?->name,
                                $mark->route_number,
                                $mark->driver?->full_name,
                                $mark->copilot?->full_name,
                                $mark->guide_number,
                                $mark->odometer_km,
                                $mark->checklistSummary(),
                                $mark->notes,
                                $mark->registeredBy?->email,
                                $mark->correction_reason,
                                $mark->manual_time ? __('Yes') : null,
                                $mark->manual_time_reason,
                            ];
                        }
                    })();

                    $path = XlsxExport::make()->sheet(__('Movements'), [
                        __('Date / time'), __('Person'), __('Type of person'), __('Document number'), __('Abteilung'), __('Direction'), __('Status'),
                        __('Reason'), __('Destination'), __('Route'), __('Driver'), __('Copilot'), __('Guide'), __('Odometer (km)'), __('Vehicle check'), __('Notizen'), __('Registered by'),
                        __('Reason for the correction'), __('Manual time'), __('Reason for the manual time'),
                    ], $rows, [18, 30, 18, 14, 16, 12, 14, 18, 18, 10, 24, 24, 12, 14, 36, 30, 28, 36, 12, 36])->save();

                    return response()->download($path, 'bitacora-garita-'.now()->format('Ymd-His').'.xlsx')->deleteFileAfterSend(true);
                }),
            Action::make('manual')
                ->label(__('Register forgotten mark'))
                ->icon('heroicon-o-clock')
                ->color('gray')
                ->visible(fn (): bool => (bool) auth()->user()?->can('Correct:Movement'))
                ->modalHeading(__('Register forgotten mark'))
                ->modalDescription(__('For an exit or an entry that was never written down. It is flagged as a manual time and needs a written reason.'))
                ->schema([
                    Select::make('worker_id')
                        ->label(__('Worker'))
                        ->required()
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search): array => Worker::query()->active()->search($search)->orderBy('last_name')->limit(20)->get()
                            ->mapWithKeys(fn (Worker $worker): array => [$worker->id => $worker->full_name.' · '.$worker->document_number])->all())
                        ->getOptionLabelUsing(fn ($value): ?string => ($worker = Worker::query()->find($value)) ? $worker->full_name.' · '.$worker->document_number : null),
                    ...MovementResource::markFields(),
                    Textarea::make('manual_time_reason')
                        ->label(__('Reason for the manual time'))
                        ->required()
                        ->minLength(10)
                        ->maxLength(500),
                ])
                ->action(function (array $data): void {
                    $worker = Worker::query()->active()->find($data['worker_id']);

                    if ($worker === null) {
                        Notification::make()->danger()->title(__('No matching workers found.'))->send();

                        return;
                    }

                    try {
                        app(MovementService::class)->registerManual(
                            $worker,
                            MovementDirection::from($data['direction']),
                            Carbon::parse($data['occurred_at']),
                            (string) $data['manual_time_reason'],
                            auth()->user(),
                            $data,
                        );
                    } catch (InvalidArgumentException $exception) {
                        Notification::make()->danger()->title($exception->getMessage())->send();

                        return;
                    }

                    Notification::make()->success()->title(__('Mark registered.'))->send();
                }),
        ];
    }
}
