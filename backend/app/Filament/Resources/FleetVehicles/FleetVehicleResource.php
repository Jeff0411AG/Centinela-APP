<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Filament\Resources\FleetVehicles;

use App\Filament\Resources\FleetVehicles\Pages\CreateFleetVehicle;
use App\Filament\Resources\FleetVehicles\Pages\EditFleetVehicle;
use App\Filament\Resources\FleetVehicles\Pages\ListFleetVehicles;
use App\Models\FleetVehicle;
use App\Support\Plate;
use BackedEnum;
use Closure;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class FleetVehicleResource extends Resource
{
    protected static ?string $model = FleetVehicle::class;

    protected static string|null|BackedEnum $navigationIcon = 'heroicon-o-truck';

    protected static ?int $navigationSort = 40;

    public static function getNavigationGroup(): ?string
    {
        return __('Gate');
    }

    public static function getNavigationLabel(): string
    {
        return __('Fleet vehicles');
    }

    public static function getModelLabel(): string
    {
        return __('Fleet vehicle');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Fleet vehicles');
    }

    /**
     * Rules for a plate field: well formed, and not used yet once dashes, spaces and case are ignored.
     *
     * @return list<Closure>
     */
    public static function plateRules(string $modelClass, ?int $ignoreId): array
    {
        return [
            function (string $attribute, mixed $value, Closure $fail): void {
                if (filled($value) && ! Plate::isValid((string) $value)) {
                    $fail(__('The plate ":value" is not valid.', ['value' => $value]));
                }
            },
            function (string $attribute, mixed $value, Closure $fail) use ($modelClass, $ignoreId): void {
                $plate = Plate::normalize((string) $value);

                if ($plate !== null && $modelClass::query()->where('plate', $plate)->when($ignoreId, fn (Builder $query) => $query->whereKeyNot($ignoreId))->exists()) {
                    $fail(__('This plate is already registered.'));
                }
            },
        ];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('plate')
                ->label(__('Plate'))
                ->required()
                ->maxLength(12)
                ->formatStateUsing(fn (?string $state): ?string => Plate::format($state))
                ->dehydrateStateUsing(fn (?string $state): ?string => Plate::normalize($state))
                ->rules(fn (?FleetVehicle $record): array => self::plateRules(FleetVehicle::class, $record?->id)),
            TextInput::make('brand')->label(__('Brand'))->maxLength(255),
            TextInput::make('model')->label(__('Model'))->maxLength(255),
            TextInput::make('color')->label(__('Color'))->maxLength(255),
            TextInput::make('initial_odometer_km')
                ->label(__('Starting odometer (km)'))
                ->helperText(__('The system proposes the last recorded return; this value is used until there is one.'))
                ->numeric()
                ->minValue(0),
            Toggle::make('is_active')->label(__('Aktiv'))->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('plate_display')
                    ->label(__('Plate'))
                    ->state(fn (FleetVehicle $record): ?string => $record->plate_display)
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->search($search)),
                TextColumn::make('brand')->label(__('Brand'))->searchable(),
                TextColumn::make('model')->label(__('Model'))->searchable(),
                TextColumn::make('color')->label(__('Color')),
                TextColumn::make('current_odometer')
                    ->label(__('Odometer (km)'))
                    ->state(fn (FleetVehicle $record): ?string => ($km = $record->currentOdometerKm()) === null ? null : number_format($km, 0, '.', ' '))
                    ->placeholder('—'),
                IconColumn::make('is_active')->label(__('Aktiv'))->boolean(),
            ])
            ->defaultSort('plate')
            ->filters([
                TernaryFilter::make('is_active')->label(__('Aktiv')),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFleetVehicles::route('/'),
            'create' => CreateFleetVehicle::route('/create'),
            'edit' => EditFleetVehicle::route('/{record}/edit'),
        ];
    }
}
