<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Filament\Resources\Absences;

use App\Enums\AbsenceType;
use App\Filament\Resources\Absences\Pages\CreateAbsence;
use App\Filament\Resources\Absences\Pages\EditAbsence;
use App\Filament\Resources\Absences\Pages\ListAbsences;
use App\Models\Absence;
use App\Models\Worker;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AbsenceResource extends Resource
{
    protected static ?string $model = Absence::class;

    protected static string|null|BackedEnum $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?int $navigationSort = 15;

    public static function getNavigationGroup(): ?string
    {
        return __('Gate');
    }

    public static function getNavigationLabel(): string
    {
        return __('Absences');
    }

    public static function getModelLabel(): string
    {
        return __('Absence');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Absences');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('worker_id')
                ->label(__('Worker'))
                ->required()
                ->searchable()
                ->getSearchResultsUsing(fn (string $search): array => Worker::query()->search($search)->orderBy('last_name')->limit(20)->get()
                    ->mapWithKeys(fn (Worker $worker): array => [$worker->id => $worker->full_name.' · '.$worker->document_number])->all())
                ->getOptionLabelUsing(fn ($value): ?string => ($worker = Worker::query()->find($value)) ? $worker->full_name.' · '.$worker->document_number : null),
            Select::make('type')
                ->label(__('Type'))
                ->options(AbsenceType::options())
                ->default(AbsenceType::Vacation->value)
                ->required(),
            DatePicker::make('starts_on')
                ->label(__('First day'))
                ->native(false)
                ->displayFormat('d/m/Y')
                ->required(),
            DatePicker::make('ends_on')
                ->label(__('Last day'))
                ->native(false)
                ->displayFormat('d/m/Y')
                ->afterOrEqual('starts_on')
                ->required(),
            DatePicker::make('returns_on')
                ->label(__('Return date'))
                ->helperText(__('Optional. When empty, the day after the last day is used.'))
                ->native(false)
                ->displayFormat('d/m/Y'),
            TextInput::make('notes')
                ->label(__('Notizen'))
                ->maxLength(500),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('worker'))
            ->columns([
                TextColumn::make('worker.document_number')->label(__('Document number'))->searchable(),
                TextColumn::make('worker_name')
                    ->label(__('Worker'))
                    ->state(fn (Absence $record): string => (string) $record->worker?->full_name)
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas('worker', fn (Builder $worker) => $worker->search($search))),
                TextColumn::make('type')
                    ->label(__('Type'))
                    ->formatStateUsing(fn ($state): string => $state instanceof AbsenceType ? $state->label() : (string) $state)
                    ->badge(),
                TextColumn::make('starts_on')->label(__('First day'))->date('d/m/Y')->sortable(),
                TextColumn::make('ends_on')->label(__('Last day'))->date('d/m/Y')->sortable(),
                TextColumn::make('return_date')
                    ->label(__('Return date'))
                    ->state(fn (Absence $record): string => $record->return_date->format('d/m/Y')),
            ])
            ->defaultSort('starts_on', 'desc')
            ->filters([
                SelectFilter::make('type')->label(__('Type'))->options(AbsenceType::options()),
                Filter::make('active_today')
                    ->label(__('Absent today'))
                    ->query(fn (Builder $query): Builder => $query->activeOn(today())),
                Filter::make('ending_this_week')
                    ->label(__('Returning this week'))
                    ->query(fn (Builder $query): Builder => $query->endingBetween(today(), today()->addDays(7))),
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
            'index' => ListAbsences::route('/'),
            'create' => CreateAbsence::route('/create'),
            'edit' => EditAbsence::route('/{record}/edit'),
        ];
    }
}
