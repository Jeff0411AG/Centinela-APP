<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Filament\Resources\Workers\Pages;

use App\Filament\Resources\Workers\WorkerResource;
use App\Models\Worker;
use App\Services\WorkerImporter;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

class ListWorkers extends ListRecords
{
    protected static string $resource = WorkerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('import')
                ->label(__('Import from CSV'))
                ->icon('heroicon-o-arrow-up-tray')
                ->color('gray')
                ->visible(fn (): bool => (bool) auth()->user()?->can('create', Worker::class))
                ->modalDescription(__('Columns: dni, apellidos, nombres, tipo (EMP, OBR, OPER, GG, ADM) and area. Save the Excel sheet as CSV first.'))
                ->schema([
                    FileUpload::make('file')
                        ->label(__('CSV file'))
                        ->required()
                        ->disk('local')
                        ->directory('imports')
                        ->visibility('private')
                        ->acceptedFileTypes(['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel']),
                    Toggle::make('dry_run')
                        ->label(__('Only validate, do not save'))
                        ->default(true),
                ])
                ->action(function (array $data): void {
                    $path = (string) $data['file'];
                    $dryRun = (bool) ($data['dry_run'] ?? true);

                    try {
                        $result = app(WorkerImporter::class)->import(Storage::disk('local')->path($path), $dryRun);
                    } catch (InvalidArgumentException $exception) {
                        Notification::make()->danger()->title($exception->getMessage())->send();

                        return;
                    } finally {
                        Storage::disk('local')->delete($path);
                    }

                    $lines = [__('Created :created, updated :updated, unchanged :unchanged, skipped :skipped.', [
                        'created' => $result['created'],
                        'updated' => $result['updated'],
                        'unchanged' => $result['unchanged'],
                        'skipped' => $result['skipped'],
                    ])];

                    foreach (array_slice($result['errors'], 0, 10) as $error) {
                        $lines[] = __('Line :line: :message', ['line' => $error['line'], 'message' => $error['message']]);
                    }

                    if (count($result['errors']) > 10) {
                        $lines[] = __('And :count more lines with errors.', ['count' => count($result['errors']) - 10]);
                    }

                    $notification = Notification::make()
                        ->title($dryRun ? __('Validation finished. Nothing was saved.') : __('Import finished.'))
                        ->body(implode("\n", $lines))
                        ->persistent();

                    ($result['skipped'] > 0 ? $notification->warning() : $notification->success())->send();
                }),
            CreateAction::make(),
        ];
    }
}
