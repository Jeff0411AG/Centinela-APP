<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Filament\Resources\StaffVehicles;

use App\Enums\VehicleKind;
use App\Filament\Resources\FleetVehicles\FleetVehicleResource;
use App\Filament\Resources\StaffVehicles\Pages\CreateStaffVehicle;
use App\Filament\Resources\StaffVehicles\Pages\EditStaffVehicle;
use App\Filament\Resources\StaffVehicles\Pages\ListStaffVehicles;
use App\Models\StaffVehicle;
use App\Models\Worker;
use App\Support\Plate;
use BackedEnum;
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
use Illuminate\Database\Eloquent\Builder;

class StaffVehicleResource extends Resource
{
    protected static ?string $model = StaffVehicle::class;

    protected static string|null|BackedEnum $navigationIcon = 'heroicon-o-key';

    protected static ?int $navigationSort = 50;

    public static function getNavigationGroup(): ?string
    {
        return __('Gate');
    }

    public static function getNavigationLabel(): string
    {
        return __('Staff vehicles');
    }

    public static function getModelLabel(): string
    {
        return __('Staff vehicle');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Staff vehicles');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('kind')
                ->label(__('Type'))
                ->options(VehicleKind::options())
                ->default(VehicleKind::Car->value)
                ->required(),
            TextInput::make('plate')
                ->label(__('Plate'))
                ->helperText(__('Leave empty for bicycles and scooters.'))
                ->maxLength(12)
                ->formatStateUsing(fn (?string $state): ?string => Plate::format($state))
                ->dehydrateStateUsing(fn (?string $state): ?string => Plate::normalize($state))
                ->rules(fn (?StaffVehicle $record): array => FleetVehicleResource::plateRules(StaffVehicle::class, $record?->id)),
            Select::make('owner_worker_id')
                ->label(__('Worker'))
                ->helperText(__('Optional. Links the vehicle to a worker of the master list.'))
                ->searchable()
                ->live()
                ->getSearchResultsUsing(fn (string $search): array => Worker::query()->search($search)->orderBy('last_name')->limit(20)->get()
                    ->mapWithKeys(fn (Worker $worker): array => [$worker->id => $worker->full_name.' · '.$worker->document_number])->all())
                ->getOptionLabelUsing(fn ($value): ?string => ($worker = Worker::query()->find($value)) ? $worker->full_name.' · '.$worker->document_number : null)
                ->afterStateUpdated(function ($state, callable $set): void {
                    if ($state && ($worker = Worker::query()->find($state))) {
                        $set('owner_name', $worker->full_name);
                        $set('department_id', $worker->department_id);
                    }
                }),
            TextInput::make('owner_name')->label(__('Owner'))->required()->maxLength(255),
            Select::make('department_id')
                ->label(__('Abteilung'))
                ->relationship('department', 'name')
                ->searchable()
                ->preload(),
            TextInput::make('brand')->label(__('Brand'))->maxLength(255),
            TextInput::make('color')->label(__('Color'))->maxLength(255),
            TextInput::make('notes')->label(__('Notizen'))->maxLength(500),
            Toggle::make('is_active')->label(__('Aktiv'))->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['department', 'owner']))
            ->columns([
                TextColumn::make('plate_display')
                    ->label(__('Plate'))
                    ->state(fn (StaffVehicle $record): ?string => $record->plate_display)
                    ->placeholder('—')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->search($search)),
                TextColumn::make('kind')
                    ->label(__('Type'))
                    ->formatStateUsing(fn ($state): string => $state instanceof VehicleKind ? $state->label() : (string) $state)
                    ->badge(),
                TextColumn::make('owner_name')->label(__('Owner'))->searchable(),
                TextColumn::make('department.name')->label(__('Abteilung'))->placeholder('—'),
                TextColumn::make('brand')->label(__('Brand'))->placeholder('—'),
                TextColumn::make('color')->label(__('Color'))->placeholder('—'),
                IconColumn::make('is_active')->label(__('Aktiv'))->boolean(),
            ])
            ->defaultSort('owner_name')
            ->filters([
                SelectFilter::make('kind')->label(__('Type'))->options(VehicleKind::options()),
                SelectFilter::make('department_id')->label(__('Abteilung'))->relationship('department', 'name'),
                TernaryFilter::make('is_active')->label(__('Aktiv')),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStaffVehicles::route('/'),
            'create' => CreateStaffVehicle::route('/create'),
            'edit' => EditStaffVehicle::route('/{record}/edit'),
        ];
    }
}
