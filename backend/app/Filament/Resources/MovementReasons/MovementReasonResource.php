<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Filament\Resources\MovementReasons;

use App\Filament\Resources\MovementReasons\Pages\CreateMovementReason;
use App\Filament\Resources\MovementReasons\Pages\EditMovementReason;
use App\Filament\Resources\MovementReasons\Pages\ListMovementReasons;
use App\Models\MovementReason;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class MovementReasonResource extends Resource
{
    protected static ?string $model = MovementReason::class;

    protected static string|null|BackedEnum $navigationIcon = 'heroicon-o-tag';

    protected static ?int $navigationSort = 20;

    public static function getNavigationGroup(): ?string
    {
        return __('Gate');
    }

    public static function getNavigationLabel(): string
    {
        return __('Movement reasons');
    }

    public static function getModelLabel(): string
    {
        return __('Movement reason');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Movement reasons');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label(__('Name'))
                ->required()
                ->maxLength(255)
                ->unique(ignoreRecord: true),
            TextInput::make('code')
                ->label(__('Code'))
                ->helperText(__('Short internal identifier, without spaces.'))
                ->required()
                ->maxLength(30)
                ->alphaDash()
                ->unique(ignoreRecord: true),
            Select::make('applies_to')
                ->label(__('Applies to'))
                ->options(['worker' => __('People'), 'vehicle' => __('Vehicles')])
                ->default('worker')
                ->required(),
            Toggle::make('requires_destination')
                ->label(__('Asks for a destination')),
            Toggle::make('requires_route')
                ->label(__('Asks for route, driver and guide')),
            Toggle::make('expects_return')
                ->label(__('The person is expected to come back'))
                ->default(true),
            TextInput::make('max_minutes')
                ->label(__('Time limit (minutes)'))
                ->helperText(__('Leave empty for no limit.'))
                ->numeric()
                ->minValue(1)
                ->maxValue(1440),
            TextInput::make('sort_order')
                ->label(__('Order'))
                ->numeric()
                ->default(0)
                ->required(),
            Toggle::make('is_active')
                ->label(__('Aktiv'))
                ->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('Name'))->searchable(),
                TextColumn::make('code')->label(__('Code'))->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('applies_to')->label(__('Applies to'))->formatStateUsing(fn (string $state): string => $state === 'vehicle' ? __('Vehicles') : __('People'))->badge(),
                IconColumn::make('requires_destination')->label(__('Destination'))->boolean(),
                IconColumn::make('requires_route')->label(__('Route'))->boolean(),
                TextColumn::make('max_minutes')->label(__('Time limit (minutes)'))->placeholder('—'),
                IconColumn::make('is_active')->label(__('Aktiv'))->boolean(),
            ])
            ->defaultSort('sort_order')
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                CreateAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMovementReasons::route('/'),
            'create' => CreateMovementReason::route('/create'),
            'edit' => EditMovementReason::route('/{record}/edit'),
        ];
    }
}
