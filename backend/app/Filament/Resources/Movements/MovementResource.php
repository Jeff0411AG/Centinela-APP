<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Filament\Resources\Movements;

use App\Enums\MovementDirection;
use App\Filament\Resources\Movements\Pages\ListMovements;
use App\Models\Department;
use App\Models\Destination;
use App\Models\Movement;
use App\Models\MovementReason;
use App\Models\User;
use App\Models\Worker;
use App\Services\MovementService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * The gate log. Marks are created from the gate desk and never edited: a supervisor corrects or voids a
 * mark by adding a new one that points at it, with a written reason. The original stays visible, struck through.
 */
class MovementResource extends Resource
{
    protected static ?string $model = Movement::class;

    protected static string|null|BackedEnum $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static ?int $navigationSort = 5;

    public static function getNavigationGroup(): ?string
    {
        return __('Gate');
    }

    public static function getNavigationLabel(): string
    {
        return __('Movements');
    }

    public static function getModelLabel(): string
    {
        return __('Movement');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Movements');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema;
    }

    /**
     * @return array<string, string>
     */
    public static function directionOptions(): array
    {
        return collect(MovementDirection::options())->except(MovementDirection::Void->value)->all();
    }

    /**
     * Fields shared by the "correct" and "forgotten mark" forms.
     *
     * @return list<mixed>
     */
    public static function markFields(bool $forWorker = true): array
    {
        $hiddenForVisitors = fn (?Movement $record): bool => ! $forWorker || ($record !== null && $record->visitor_id !== null);
        $hiddenForVehicles = fn (?Movement $record): bool => ! $forWorker || ($record !== null && ($record->visitor_id !== null || $record->fleet_vehicle_id !== null));
        $vehicleOnly = fn (?Movement $record): bool => $record === null || $record->fleet_vehicle_id === null;

        return [
            Select::make('direction')
                ->label(__('Direction'))
                ->options(self::directionOptions())
                ->required(),
            DateTimePicker::make('occurred_at')
                ->label(__('Date / time'))
                ->native(false)
                ->seconds(false)
                ->displayFormat('d/m/Y H:i')
                ->maxDate(now())
                ->required(),
            Select::make('reason_id')
                ->label(__('Reason'))
                ->options(fn (): array => MovementReason::query()->ordered()->pluck('name', 'id')->all())
                ->hidden($hiddenForVisitors),
            Select::make('destination_id')
                ->label(__('Destination'))
                ->options(fn (): array => Destination::query()->orderBy('name')->pluck('name', 'id')->all())
                ->searchable()
                ->hidden($hiddenForVehicles),
            TextInput::make('route_number')->label(__('Route'))->maxLength(20)->hidden($hiddenForVehicles),
            Select::make('driver_worker_id')
                ->label(__('Driver'))
                ->searchable()
                ->getSearchResultsUsing(fn (string $search): array => Worker::query()->search($search)->orderBy('last_name')->limit(20)->get()
                    ->mapWithKeys(fn (Worker $worker): array => [$worker->id => $worker->full_name])->all())
                ->getOptionLabelUsing(fn ($value): ?string => Worker::query()->find($value)?->full_name)
                ->hidden($hiddenForVisitors),
            TextInput::make('guide_number')->label(__('Guide'))->maxLength(30)->hidden($hiddenForVehicles),
            TextInput::make('odometer_km')->label(__('Odometer (km)'))->numeric()->minValue(0)->hidden($vehicleOnly),
            TextInput::make('notes')->label(__('Notizen'))->maxLength(500),
        ];
    }

    /**
     * Needs the is_superseded flag selected by the log query (withExists).
     */
    public static function statusLabel(Movement $record): string
    {
        return match (true) {
            $record->direction === MovementDirection::Void => __('Voiding'),
            $record->corrects_movement_id !== null => __('Correction'),
            (bool) ($record->is_superseded ?? false) => __('Corrected'),
            default => __('Current'),
        };
    }

    public static function table(Table $table): Table
    {
        $struck = fn (Movement $record): array => $record->is_superseded ? ['style' => 'text-decoration: line-through; opacity: .6'] : [];

        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with(['worker.department', 'visitor', 'fleetVehicle', 'reason', 'destination', 'registeredBy', 'driver', 'copilot'])
                ->withExists('corrections as is_superseded'))
            ->columns([
                TextColumn::make('occurred_at')
                    ->label(__('Date / time'))
                    ->dateTime('d/m/Y H:i:s')
                    ->sortable()
                    ->extraCellAttributes($struck),
                TextColumn::make('subject')
                    ->label(__('Person'))
                    ->state(fn (Movement $record): string => $record->worker?->full_name
                        ?? $record->fleetVehicle?->label
                        ?? trim(($record->visitor?->first_name ?? '').' '.($record->visitor?->name ?? '')))
                    ->extraCellAttributes($struck)
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->where(fn (Builder $inner) => $inner
                            ->whereHas('worker', fn (Builder $worker) => $worker->search($search))
                            ->orWhereHas('fleetVehicle', fn (Builder $vehicle) => $vehicle->search($search))
                            ->orWhereHas('visitor', fn (Builder $visitor) => $visitor
                                ->where('first_name', 'like', "%{$search}%")
                                ->orWhere('name', 'like', "%{$search}%")))),
                TextColumn::make('direction')
                    ->label(__('Direction'))
                    ->formatStateUsing(fn ($state): string => $state instanceof MovementDirection ? $state->label() : (string) $state)
                    ->badge()
                    ->color(fn ($state): string => match ($state) {
                        MovementDirection::Exit => 'warning',
                        MovementDirection::Entry => 'success',
                        MovementDirection::Void => 'gray',
                        default => 'danger',
                    }),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->state(fn (Movement $record): string => self::statusLabel($record))
                    ->badge()
                    ->color(fn (string $state): string => $state === __('Current') ? 'success' : 'gray'),
                TextColumn::make('reason.name')->label(__('Reason'))->placeholder('—')->extraCellAttributes($struck),
                TextColumn::make('destination.name')->label(__('Destination'))->placeholder('—')->extraCellAttributes($struck),
                TextColumn::make('correction_reason')
                    ->label(__('Reason for the correction'))
                    ->placeholder('—')
                    ->limit(40)
                    ->tooltip(fn (Movement $record): ?string => $record->correction_reason ?? $record->manual_time_reason),
                IconColumn::make('manual_time')
                    ->label(__('Manual time'))
                    ->boolean()
                    ->trueIcon('heroicon-o-clock')
                    ->falseIcon('')
                    ->tooltip(fn (Movement $record): ?string => $record->manual_time_reason),
                TextColumn::make('odometer_km')->label(__('Odometer (km)'))->numeric()->placeholder('—')->toggleable(),
                TextColumn::make('copilot.last_name')->label(__('Copilot'))->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('checklist_summary')
                    ->label(__('Vehicle check'))
                    ->state(fn (Movement $record): ?string => $record->checklistSummary())
                    ->placeholder('—')
                    ->limit(40)
                    ->tooltip(fn (Movement $record): ?string => $record->checklistSummary())
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('route_number')->label(__('Route'))->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('driver.last_name')->label(__('Driver'))->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('guide_number')->label(__('Guide'))->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('notes')->label(__('Notizen'))->limit(40)->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('registeredBy.email')->label(__('Registered by'))->toggleable(),
            ])
            ->defaultSort('occurred_at', 'desc')
            ->filters([
                Filter::make('today')
                    ->label(__('Today'))
                    ->query(fn (Builder $query): Builder => $query->whereDate('occurred_at', today())),
                Filter::make('occurred_between')
                    ->label(__('Date / time'))
                    ->schema([
                        DatePicker::make('from')->label(__('Von')),
                        DatePicker::make('until')->label(__('bis')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('occurred_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('occurred_at', '<=', $date))),
                SelectFilter::make('direction')
                    ->label(__('Direction'))
                    ->options(MovementDirection::options()),
                SelectFilter::make('status')
                    ->label(__('Status'))
                    ->options([
                        'current' => __('Current'),
                        'corrected' => __('Corrected'),
                        'corrections' => __('Corrections and voids'),
                        'manual' => __('Manual time'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'current' => $query->effective(),
                        'corrected' => $query->superseded(),
                        'corrections' => $query->onlyCorrections(),
                        'manual' => $query->where('manual_time', true),
                        default => $query,
                    }),
                SelectFilter::make('subject_type')
                    ->label(__('Type of person'))
                    ->options(['worker' => __('Worker'), 'visitor' => __('Visitor'), 'vehicle' => __('Company vehicle')])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'worker' => $query->whereNotNull('worker_id'),
                        'vehicle' => $query->whereNotNull('fleet_vehicle_id'),
                        'visitor' => $query->where(fn (Builder $inner) => $inner->whereNotNull('visitor_id')->orWhereNotNull('visit_id')),
                        default => $query,
                    }),
                SelectFilter::make('reason_id')
                    ->label(__('Reason'))
                    ->relationship('reason', 'name'),
                SelectFilter::make('destination_id')
                    ->label(__('Destination'))
                    ->relationship('destination', 'name'),
                SelectFilter::make('department')
                    ->label(__('Abteilung'))
                    ->options(fn (): array => Department::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $query, string $id): Builder => $query->whereHas('worker', fn (Builder $worker) => $worker->where('department_id', $id)),
                    )),
                SelectFilter::make('registered_by_user_id')
                    ->label(__('Registered by'))
                    ->options(fn (): array => User::query()->whereIn('id', Movement::query()->select('registered_by_user_id'))->orderBy('email')->pluck('email', 'id')->all()),
            ])
            ->recordActions([
                Action::make('correct')
                    ->label(__('Correct'))
                    ->icon('heroicon-o-pencil-square')
                    ->color('warning')
                    ->visible(fn (Movement $record): bool => (bool) auth()->user()?->can('correct', $record))
                    ->modalHeading(__('Correct mark'))
                    ->modalDescription(__('The original mark stays in the log, struck through. The correction records who changed it and why.'))
                    ->fillForm(fn (Movement $record): array => [
                        'direction' => $record->direction->value,
                        'occurred_at' => $record->occurred_at,
                        'reason_id' => $record->reason_id,
                        'destination_id' => $record->destination_id,
                        'route_number' => $record->route_number,
                        'driver_worker_id' => $record->driver_worker_id,
                        'odometer_km' => $record->odometer_km,
                        'guide_number' => $record->guide_number,
                        'notes' => $record->notes,
                    ])
                    ->schema([
                        ...self::markFields(),
                        Textarea::make('correction_reason')
                            ->label(__('Reason for the correction'))
                            ->required()
                            ->minLength(10)
                            ->maxLength(500),
                    ])
                    ->action(function (Movement $record, array $data): void {
                        $reason = (string) $data['correction_reason'];
                        unset($data['correction_reason']);

                        try {
                            app(MovementService::class)->correct($record, $data, $reason, auth()->user());
                        } catch (InvalidArgumentException $exception) {
                            Notification::make()->danger()->title($exception->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()->title(__('Correction registered.'))->send();
                    }),
                Action::make('void')
                    ->label(__('Void'))
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (Movement $record): bool => (bool) auth()->user()?->can('correct', $record))
                    ->modalHeading(__('Void mark'))
                    ->modalDescription(__('Use it for a mark that should not exist, for example one made for the wrong person. It stays in the log, struck through.'))
                    ->requiresConfirmation()
                    ->schema([
                        Textarea::make('correction_reason')
                            ->label(__('Reason for the correction'))
                            ->required()
                            ->minLength(10)
                            ->maxLength(500),
                    ])
                    ->action(function (Movement $record, array $data): void {
                        try {
                            app(MovementService::class)->void($record, (string) $data['correction_reason'], auth()->user());
                        } catch (InvalidArgumentException $exception) {
                            Notification::make()->danger()->title($exception->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()->title(__('Mark voided.'))->send();
                    }),
            ])
            ->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMovements::route('/'),
        ];
    }
}
