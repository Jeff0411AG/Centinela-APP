<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Filament\Resources\Workers;

use App\Enums\WorkerType;
use App\Filament\Resources\Workers\Pages\CreateWorker;
use App\Filament\Resources\Workers\Pages\EditWorker;
use App\Filament\Resources\Workers\Pages\ListWorkers;
use App\Models\Worker;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class WorkerResource extends Resource
{
    protected static ?string $model = Worker::class;

    protected static string|null|BackedEnum $navigationIcon = 'heroicon-o-identification';

    protected static ?int $navigationSort = 10;

    public static function getNavigationGroup(): ?string
    {
        return __('Gate');
    }

    public static function getNavigationLabel(): string
    {
        return __('Workers');
    }

    public static function getModelLabel(): string
    {
        return __('Worker');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Workers');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('document_type')
                ->label(__('Document type'))
                ->options(['DNI' => 'DNI', 'CE' => 'CE'])
                ->default('DNI')
                ->required(),
            TextInput::make('document_number')
                ->label(__('Document number'))
                ->required()
                ->maxLength(20)
                ->unique(ignoreRecord: true),
            TextInput::make('last_name')
                ->label(__('Apellido'))
                ->required()
                ->maxLength(255),
            TextInput::make('first_name')
                ->label(__('Vorname'))
                ->required()
                ->maxLength(255),
            Select::make('worker_type')
                ->label(__('Worker type'))
                ->options(WorkerType::options())
                ->required(),
            Select::make('department_id')
                ->label(__('Abteilung'))
                ->relationship('department', 'name')
                ->searchable()
                ->preload(),
            TextInput::make('card_code')
                ->label(__('Card code'))
                ->maxLength(40)
                ->unique(ignoreRecord: true),
            Toggle::make('is_active')
                ->label(__('Aktiv'))
                ->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('document_number')
                    ->label(__('Document number'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('last_name')
                    ->label(__('Apellido'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('first_name')
                    ->label(__('Vorname'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('worker_type')
                    ->label(__('Worker type'))
                    ->formatStateUsing(fn ($state): string => $state instanceof WorkerType ? $state->label() : (string) $state)
                    ->badge(),
                TextColumn::make('department.name')
                    ->label(__('Abteilung'))
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label(__('Aktiv'))
                    ->boolean(),
            ])
            ->defaultSort('last_name')
            ->filters([
                SelectFilter::make('worker_type')
                    ->label(__('Worker type'))
                    ->options(WorkerType::options()),
                SelectFilter::make('department_id')
                    ->label(__('Abteilung'))
                    ->relationship('department', 'name'),
                TernaryFilter::make('is_active')
                    ->label(__('Aktiv')),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWorkers::route('/'),
            'create' => CreateWorker::route('/create'),
            'edit' => EditWorker::route('/{record}/edit'),
        ];
    }
}
