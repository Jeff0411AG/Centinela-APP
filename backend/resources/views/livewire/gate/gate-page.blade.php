@php
    $statusBadge = fn (string $status): array => match ($status) {
        'inside' => ['badge-success', __('Inside')],
        'outside' => ['badge-warning', __('Outside')],
        'went_home' => ['badge-neutral', __('Went home')],
        default => ['badge-ghost', __('Not arrived yet')],
    };
    $elapsed = function (\App\Models\Movement $movement): string {
        $minutes = (int) $movement->occurred_at->diffInMinutes(now(), true);

        if ($minutes >= 1440) {
            return intdiv($minutes, 1440).' d '.intdiv($minutes % 1440, 60).' h';
        }

        return $minutes >= 60
            ? intdiv($minutes, 60).' h '.str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT).' min'
            : $minutes.' min';
    };
@endphp

<div
    x-data
    x-on:gate-focus.window="$nextTick(() => $refs.search && $refs.search.focus())"
    x-on:keydown.escape.window="$wire.clearSelection()"
    x-on:keydown.f1.window.prevent="$wire.chooseReasonAt(0)"
    x-on:keydown.f2.window.prevent="$wire.chooseReasonAt(1)"
    x-on:keydown.f3.window.prevent="$wire.chooseReasonAt(2)"
    x-on:keydown.f4.window.prevent="$wire.chooseReasonAt(3)"
>
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3 lg:items-start">
        <div class="flex flex-col gap-6 lg:col-span-2">
            @if ($flash)
                <div class="alert alert-success rounded-2xl shadow-sm" role="status">
                    <span>{{ $flash }}</span>
                </div>
            @endif

            @error('visit')
                <div class="alert alert-error rounded-2xl shadow-sm" role="alert">
                    <span>{{ $message }}</span>
                </div>
            @enderror

            <article class="rounded-3xl border border-base-300 bg-base-100 p-6 sm:p-7 shadow-sm">
                <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                    <h2 class="text-xl font-bold text-base-content">{{ __('Suche') }}</h2>
                    @if ($canReceiveVisitors)
                        <a href="{{ route('portal.check_in_out') }}" class="btn btn-outline btn-sm rounded-xl" wire:navigate>{{ __('Visitor without appointment') }}</a>
                    @endif
                </div>

                <div class="mb-4 flex flex-wrap gap-2" aria-label="{{ __('People now') }}">
                    @foreach (['inside', 'outside', 'not_arrived', 'went_home'] as $presenceStatus)
                        @php([$presenceClass, $presenceText] = $statusBadge($presenceStatus))
                        <span class="badge {{ $presenceClass }} rounded-full text-xs font-semibold">{{ $presenceText }} · {{ $presence[$presenceStatus] ?? 0 }}</span>
                    @endforeach
                </div>

                <input
                    type="text"
                    x-ref="search"
                    wire:model.live.debounce.250ms="search"
                    wire:keydown.enter.prevent="selectFirst"
                    class="input input-bordered w-full text-base"
                    placeholder="{{ __('Search by DNI or name') }}"
                    autocomplete="off"
                    autofocus
                >

                @if (mb_strlen(trim($search)) >= 2)
                    <div class="mt-4 grid gap-3">
                        @forelse ($results as $result)
                            @php($isOut = $result->latestMovement?->direction === \App\Enums\MovementDirection::Exit)
                            @php([$badgeClass, $badgeText] = $statusBadge($result->gateStatus()))
                            <button
                                type="button"
                                wire:key="gate-result-{{ $result->id }}"
                                wire:click="selectWorker({{ $result->id }})"
                                class="rounded-2xl border border-base-300 bg-base-100 px-4 py-4 text-left"
                            >
                                <span class="flex flex-wrap items-center gap-2">
                                    <span class="text-lg font-semibold tracking-tight text-base-content">{{ $result->full_name }}</span>
                                    <span class="badge {{ $badgeClass }} rounded-full text-xs font-semibold">{{ $badgeText }}</span>
                                    @if ($result->absenceOn())
                                        <span class="badge badge-neutral rounded-full text-xs font-semibold">{{ __('Absent until :date', ['date' => $result->absenceOn()->ends_on->format('d/m')]) }}</span>
                                    @endif
                                </span>
                                <span class="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-sm text-base-content/70">
                                    <span>{{ $result->document_type }} {{ $result->document_number }}</span>
                                    @if ($result->department)
                                        <span>{{ $result->department->name }}</span>
                                    @endif
                                    <span>{{ $result->worker_type->label() }}</span>
                                </span>
                            </button>
                        @empty
                            @if ($visitorResults->isEmpty() && $fleetResults->isEmpty() && $staffVehicleResults->isEmpty())
                                <div class="rounded-2xl border border-dashed border-base-300 bg-base-100/70 px-4 py-8 text-sm text-base-content/65">
                                    {{ __('No matching workers found.') }}
                                </div>
                            @endif
                        @endforelse

                        @foreach ($visitorResults as $visitor)
                            <div wire:key="gate-visitor-{{ $visitor['visit_id'] }}-{{ $visitor['visitor_id'] }}" class="flex flex-col gap-3 rounded-2xl border border-base-300 bg-base-100 px-4 py-4 lg:flex-row lg:items-center lg:justify-between">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="text-lg font-semibold tracking-tight text-base-content">{{ $visitor['name'] }}</span>
                                        <span class="badge badge-ghost rounded-full text-xs font-semibold">{{ __('Visitor') }}</span>
                                        <span class="badge {{ $visitor['inside'] ? 'badge-success' : 'badge-warning' }} rounded-full text-xs font-semibold">{{ $visitor['inside'] ? __('Inside') : __('Expected') }}</span>
                                    </div>
                                    <div class="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-sm text-base-content/70">
                                        @if ($visitor['company'])
                                            <span>{{ $visitor['company'] }}</span>
                                        @endif
                                        @if ($visitor['host'])
                                            <span>{{ __('Host') }}: {{ $visitor['host'] }}</span>
                                        @endif
                                        @if ($visitor['time'])
                                            <span>{{ $visitor['time'] }}</span>
                                        @endif
                                    </div>
                                </div>
                                @if ($visitor['inside'])
                                    <button type="button" class="btn btn-outline btn-sm rounded-xl" wire:click="visitorCheckOut({{ $visitor['visit_id'] }}, {{ $visitor['visitor_id'] }})" wire:loading.attr="disabled">{{ __('Check-out') }}</button>
                                @else
                                    <button type="button" class="btn btn-primary btn-sm rounded-xl" wire:click="visitorCheckIn({{ $visitor['visit_id'] }}, {{ $visitor['visitor_id'] }})" wire:loading.attr="disabled">{{ __('Check-in') }}</button>
                                @endif
                            </div>
                        @endforeach

                        @foreach ($fleetResults as $fleet)
                            @php($fleetOut = $fleet->latestMovement?->direction === \App\Enums\MovementDirection::Exit)
                            <button
                                type="button"
                                wire:key="gate-fleet-{{ $fleet->id }}"
                                wire:click="selectVehicle({{ $fleet->id }})"
                                class="rounded-2xl border border-base-300 bg-base-100 px-4 py-4 text-left"
                            >
                                <span class="flex flex-wrap items-center gap-2">
                                    <span class="text-lg font-semibold tracking-tight text-base-content">{{ $fleet->plate_display }}</span>
                                    <span class="badge badge-ghost rounded-full text-xs font-semibold">{{ __('Company vehicle') }}</span>
                                    <span class="badge {{ $fleetOut ? 'badge-warning' : 'badge-success' }} rounded-full text-xs font-semibold">{{ $fleetOut ? __('Outside') : __('Inside') }}</span>
                                </span>
                                <span class="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-sm text-base-content/70">
                                    <span>{{ trim(($fleet->brand ?? '').' '.($fleet->model ?? '')) }}</span>
                                    @if ($fleet->color)
                                        <span>{{ $fleet->color }}</span>
                                    @endif
                                </span>
                            </button>
                        @endforeach

                        @foreach ($staffVehicleResults as $staffVehicle)
                            <div wire:key="gate-staff-vehicle-{{ $staffVehicle->id }}" class="rounded-2xl border border-base-300 bg-base-100 px-4 py-4">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="text-lg font-semibold tracking-tight text-base-content">{{ $staffVehicle->plate_display ?? '—' }}</span>
                                    <span class="badge badge-ghost rounded-full text-xs font-semibold">{{ $staffVehicle->kind->label() }}</span>
                                    <span class="badge {{ $staffVehicle->is_active ? 'badge-success' : 'badge-neutral' }} rounded-full text-xs font-semibold">{{ $staffVehicle->is_active ? __('Authorized') : __('Inactive') }}</span>
                                </div>
                                <div class="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-sm text-base-content/70">
                                    <span>{{ __('Owner') }}: {{ $staffVehicle->owner_name }}</span>
                                    @if ($staffVehicle->department)
                                        <span>{{ $staffVehicle->department->name }}</span>
                                    @endif
                                    @if ($staffVehicle->brand)
                                        <span>{{ $staffVehicle->brand }}</span>
                                    @endif
                                    @if ($staffVehicle->color)
                                        <span>{{ $staffVehicle->color }}</span>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </article>

            @if ($selected)
                <article class="rounded-3xl border border-base-300 bg-base-100 p-6 sm:p-7 shadow-sm" wire:key="gate-selected-{{ $selected->id }}">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h2 class="text-xl font-bold text-base-content">{{ $selected->full_name }}</h2>
                                @php([$selectedBadgeClass, $selectedBadgeText] = $statusBadge($selected->gateStatus()))
                                <span class="badge {{ $selectedBadgeClass }} rounded-full text-xs font-semibold">{{ $selectedBadgeText }}</span>
                            </div>
                            <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-sm text-base-content/70">
                                <span>{{ $selected->document_type }} {{ $selected->document_number }}</span>
                                @if ($selected->department)
                                    <span>{{ $selected->department->name }}</span>
                                @endif
                                <span>{{ $selected->worker_type->label() }}</span>
                            </div>
                            @if ($selectedAbsence)
                                <div class="alert alert-warning mt-3 rounded-2xl" role="status">
                                    <span>{{ __('Absent until :date', ['date' => $selectedAbsence->ends_on->format('d/m/Y')]) }}</span>
                                </div>
                            @endif
                            @if ($selectedIsOutside)
                                <div class="mt-3 text-sm text-base-content/70">
                                    {{ __('Left at :time', ['time' => $selected->latestMovement->occurred_at->format('H:i')]) }}
                                    @if ($selected->latestMovement->reason)
                                        · {{ $selected->latestMovement->reason->name }}
                                    @endif
                                    @if ($selected->latestMovement->destination)
                                        · {{ $selected->latestMovement->destination->name }}
                                    @endif
                                </div>
                            @endif
                        </div>

                        <button type="button" class="btn btn-ghost btn-sm rounded-xl" wire:click="clearSelection">{{ __('Abbrechen') }} (Esc)</button>
                    </div>

                    @if ($pendingConflict)
                        <div class="alert alert-warning mt-5 rounded-2xl" role="alert">
                            <span>
                                @if ($pendingConflict['conflict'] === \App\Exceptions\MovementConflict::ABSENT)
                                    {{ __('Marked as absent until :date. Register the exit anyway?', ['date' => $pendingConflict['absent_until'] ?? '—']) }}
                                @elseif ($pendingConflict['conflict'] === \App\Exceptions\MovementConflict::ALREADY_OUTSIDE)
                                    {{ __('Already figures as outside since :time. Register another exit anyway?', ['time' => $pendingConflict['since'] ?? '—']) }}
                                @else
                                    {{ __('There is no open exit for this person. Register the return anyway?') }}
                                @endif
                            </span>
                            <div class="flex gap-2">
                                <button type="button" class="btn btn-primary btn-sm rounded-xl" wire:click="confirmPending" x-init="$nextTick(() => $el.focus())">{{ __('Yes, register') }}</button>
                                <button type="button" class="btn btn-outline btn-sm rounded-xl" wire:click="cancelPending">{{ __('Abbrechen') }}</button>
                            </div>
                        </div>
                    @endif

                    @if ($selectedIsOutside && ! $pendingConflict)
                        <div class="mt-5">
                            <button
                                type="button"
                                class="btn btn-primary w-full rounded-xl"
                                wire:click="registerReturn({{ $selected->id }})"
                                wire:loading.attr="disabled"
                                x-init="$nextTick(() => $el.focus())"
                            >{{ __('Register return') }}</button>
                        </div>
                        <details class="mt-4">
                            <summary class="cursor-pointer text-sm text-base-content/70">{{ __('Register another exit') }}</summary>
                            <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                                @foreach ($reasons as $index => $reason)
                                    <button type="button" class="btn btn-outline rounded-xl" wire:key="gate-reason-out-{{ $reason->id }}" wire:click="chooseReason({{ $reason->id }})">{{ $reason->name }}</button>
                                @endforeach
                            </div>
                        </details>
                        <div class="mt-4">
                            <button type="button" class="btn btn-outline w-full rounded-xl" wire:click="registerEndOfShift({{ $selected->id }})" wire:confirm="{{ __('Register that this person leaves for the day?') }}" wire:loading.attr="disabled">{{ __('End of shift') }} · {{ __('leaves for the day') }}</button>
                        </div>
                    @elseif (! $pendingConflict && ! $activeReason && in_array($selected->gateStatus(), ['not_arrived', 'went_home'], true))
                        <div class="mt-5">
                            <button type="button" class="btn btn-primary w-full rounded-xl" wire:click="registerArrival({{ $selected->id }})" wire:loading.attr="disabled" x-init="$nextTick(() => $el.focus())">{{ __('Register arrival') }}</button>
                        </div>
                        <details class="mt-4">
                            <summary class="cursor-pointer text-sm text-base-content/70">{{ __('Register an exit anyway') }}</summary>
                            <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                                @foreach ($reasons as $reason)
                                    <button type="button" class="btn btn-outline rounded-xl" wire:key="gate-reason-na-{{ $reason->id }}" wire:click="chooseReason({{ $reason->id }})">{{ $reason->name }}</button>
                                @endforeach
                            </div>
                        </details>
                    @elseif (! $pendingConflict && ! $activeReason)
                        <p class="mb-3 mt-5 text-sm text-base-content/70">{{ __('Register exit for') }}</p>
                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            @foreach ($reasons as $index => $reason)
                                <button type="button" class="btn btn-primary rounded-xl" wire:key="gate-reason-{{ $reason->id }}" wire:click="chooseReason({{ $reason->id }})" wire:loading.attr="disabled">
                                    @if ($index < 4)<span class="text-xs">F{{ $index + 1 }}</span>@endif
                                    {{ $reason->name }}
                                </button>
                            @endforeach
                        </div>
                        <div class="mt-4 border-t border-base-300 pt-4">
                            <button type="button" class="btn btn-outline w-full rounded-xl" wire:click="registerEndOfShift({{ $selected->id }})" wire:confirm="{{ __('Register that this person leaves for the day?') }}" wire:loading.attr="disabled">{{ __('End of shift') }} · {{ __('leaves for the day') }}</button>
                        </div>
                    @endif

                    @if ($activeReason)
                        <form wire:submit.prevent="submitExit" class="mt-5 grid gap-4">
                            <h3 class="text-lg font-semibold text-base-content">{{ $activeReason->name }}</h3>

                            @if ($activeReason->requires_destination)
                                <div>
                                    <label class="label" for="gate-destination"><span class="label-text">{{ __('Destination') }}</span></label>
                                    <select id="gate-destination" wire:model="destinationId" class="select select-bordered w-full @error('destinationId') select-error @enderror" x-init="$nextTick(() => $el.focus())">
                                        <option value="">{{ __('Bitte auswählen') }}</option>
                                        @foreach ($destinations as $destination)
                                            <option value="{{ $destination->id }}">{{ $destination->name }}</option>
                                        @endforeach
                                    </select>
                                    @error('destinationId')<p class="mt-1 text-sm text-error">{{ $message }}</p>@enderror
                                </div>
                            @endif

                            @if ($activeReason->requires_route)
                                <div>
                                    <label class="label" for="gate-route"><span class="label-text">{{ __('Route') }}</span></label>
                                    <input id="gate-route" type="text" wire:model="routeNumber" class="input input-bordered w-full @error('routeNumber') input-error @enderror" maxlength="20" autocomplete="off">
                                    @error('routeNumber')<p class="mt-1 text-sm text-error">{{ $message }}</p>@enderror
                                </div>
                                <div>
                                    <label class="label" for="gate-driver"><span class="label-text">{{ __('Driver') }}</span></label>
                                    <input id="gate-driver" type="text" wire:model.live.debounce.250ms="driverSearch" class="input input-bordered w-full @error('driverId') input-error @enderror" placeholder="{{ __('Search by DNI or name') }}" autocomplete="off">
                                    @error('driverId')<p class="mt-1 text-sm text-error">{{ $message }}</p>@enderror
                                    @if ($driverResults->isNotEmpty())
                                        <div class="mt-2 grid gap-2">
                                            @foreach ($driverResults as $driver)
                                                <button type="button" wire:key="gate-driver-{{ $driver->id }}" wire:click="selectDriver({{ $driver->id }})" class="rounded-2xl border border-base-300 bg-base-100 px-4 py-2 text-left text-sm">{{ $driver->full_name }}</button>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                                <div>
                                    <label class="label" for="gate-guide"><span class="label-text">{{ __('Guide') }}</span></label>
                                    <input id="gate-guide" type="text" wire:model="guideNumber" class="input input-bordered w-full" maxlength="30" autocomplete="off">
                                </div>
                            @endif

                            <div>
                                <label class="label" for="gate-notes"><span class="label-text">{{ __('Notizen') }}</span></label>
                                <input id="gate-notes" type="text" wire:model="notes" class="input input-bordered w-full" maxlength="500" autocomplete="off" placeholder="{{ __('optional') }}">
                            </div>

                            <div class="flex gap-3">
                                <button type="submit" class="btn btn-primary rounded-xl" wire:loading.attr="disabled">{{ __('Register exit') }}</button>
                                <button type="button" class="btn btn-outline rounded-xl" wire:click="cancelReason">{{ __('Abbrechen') }}</button>
                            </div>
                        </form>
                    @endif
                </article>
            @endif

            @if ($selectedVehicle)
                @php($vehicleOut = $selectedVehicle->latestMovement?->direction === \App\Enums\MovementDirection::Exit)
                <article class="rounded-3xl border border-base-300 bg-base-100 p-6 sm:p-7 shadow-sm" wire:key="gate-vehicle-{{ $selectedVehicle->id }}">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h2 class="text-xl font-bold text-base-content">{{ $selectedVehicle->label }}</h2>
                                <span class="badge {{ $vehicleOut ? 'badge-warning' : 'badge-success' }} rounded-full text-xs font-semibold">{{ $vehicleOut ? __('Outside') : __('Inside') }}</span>
                            </div>
                            <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-sm text-base-content/70">
                                @if ($selectedVehicle->currentOdometerKm() !== null)
                                    <span>{{ __('Last odometer') }}: {{ number_format($selectedVehicle->currentOdometerKm(), 0, '.', ' ') }} km</span>
                                @endif
                                @if ($vehicleOut)
                                    <span>{{ __('Left at :time', ['time' => $selectedVehicle->latestMovement->occurred_at->format('H:i')]) }}</span>
                                    @if ($selectedVehicle->latestMovement->driver)
                                        <span>{{ __('Driver') }}: {{ $selectedVehicle->latestMovement->driver->full_name }}</span>
                                    @endif
                                @endif
                            </div>
                        </div>

                        <button type="button" class="btn btn-ghost btn-sm rounded-xl" wire:click="clearSelection">{{ __('Abbrechen') }} (Esc)</button>
                    </div>

                    @error('vehicle')
                        <div class="alert alert-error mt-5 rounded-2xl" role="alert"><span>{{ $message }}</span></div>
                    @enderror

                    @if ($vehicleConflict)
                        <div class="alert alert-warning mt-5 rounded-2xl" role="alert">
                            <span>
                                @switch ($vehicleConflict['conflict'])
                                    @case (\App\Exceptions\MovementConflict::ABSENT)
                                        {{ __('The driver is marked as absent until :date. Register the exit anyway?', ['date' => $vehicleConflict['absent_until'] ?? '—']) }}
                                        @break
                                    @case (\App\Exceptions\MovementConflict::ALREADY_OUTSIDE)
                                        {{ __('The vehicle already figures as outside since :time. Register another exit anyway?', ['time' => $vehicleConflict['since'] ?? '—']) }}
                                        @break
                                    @case (\App\Exceptions\MovementConflict::ODOMETER_MISMATCH)
                                        {{ __('The odometer does not match the last return (:km km). The vehicle may have been used without being written down. Register anyway?', ['km' => number_format((int) $vehicleConflict['expected_km'], 0, '.', ' ')]) }}
                                        @break
                                    @case (\App\Exceptions\MovementConflict::ODOMETER_LOWER)
                                        {{ __('The return odometer is lower than at the exit (:km km). Register anyway?', ['km' => number_format((int) $vehicleConflict['expected_km'], 0, '.', ' ')]) }}
                                        @break
                                    @default
                                        {{ __('This vehicle has no open exit. Register the return anyway?') }}
                                @endswitch
                            </span>
                            <div class="flex gap-2">
                                <button type="button" class="btn btn-primary btn-sm rounded-xl" wire:click="confirmVehicleConflict" x-init="$nextTick(() => $el.focus())">{{ __('Yes, register') }}</button>
                                <button type="button" class="btn btn-outline btn-sm rounded-xl" wire:click="cancelVehicleConflict">{{ __('Abbrechen') }}</button>
                            </div>
                        </div>
                    @endif

                    @if (! $vehicleAction)
                        <div class="mt-5">
                            @if ($vehicleOut)
                                <button type="button" class="btn btn-primary w-full rounded-xl" wire:click="startVehicleReturn" x-init="$nextTick(() => $el.focus())">{{ __('Register return') }}</button>
                            @else
                                <button type="button" class="btn btn-primary w-full rounded-xl" wire:click="startVehicleExit" x-init="$nextTick(() => $el.focus())">{{ __('Register exit') }}</button>
                            @endif
                        </div>
                    @else
                        <form wire:submit.prevent="{{ $vehicleAction === 'exit' ? 'submitVehicleExit' : 'submitVehicleReturn' }}" class="mt-5 grid gap-4">
                            @if ($vehicleAction === 'exit')
                                <div>
                                    <label class="label" for="vehicle-driver"><span class="label-text">{{ __('Driver') }}</span></label>
                                    <input id="vehicle-driver" type="text" wire:model.live.debounce.250ms="vehicleDriverSearch" class="input input-bordered w-full @error('vehicleDriverId') input-error @enderror" placeholder="{{ __('Search by DNI or name') }}" autocomplete="off" x-init="$nextTick(() => $el.focus())">
                                    @error('vehicleDriverId')<p class="mt-1 text-sm text-error">{{ $message }}</p>@enderror
                                    @if ($vehicleDriverResults->isNotEmpty())
                                        <div class="mt-2 grid gap-2">
                                            @foreach ($vehicleDriverResults as $candidate)
                                                <button type="button" wire:key="vehicle-driver-{{ $candidate->id }}" wire:click="selectVehicleDriver({{ $candidate->id }})" class="rounded-2xl border border-base-300 bg-base-100 px-4 py-2 text-left text-sm">{{ $candidate->full_name }}</button>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                                <div>
                                    <label class="label" for="vehicle-copilot"><span class="label-text">{{ __('Copilot') }} ({{ __('optional') }})</span></label>
                                    <input id="vehicle-copilot" type="text" wire:model.live.debounce.250ms="copilotSearch" class="input input-bordered w-full" placeholder="{{ __('Search by DNI or name') }}" autocomplete="off">
                                    @if ($copilotResults->isNotEmpty())
                                        <div class="mt-2 grid gap-2">
                                            @foreach ($copilotResults as $candidate)
                                                <button type="button" wire:key="vehicle-copilot-{{ $candidate->id }}" wire:click="selectCopilot({{ $candidate->id }})" class="rounded-2xl border border-base-300 bg-base-100 px-4 py-2 text-left text-sm">{{ $candidate->full_name }}</button>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                                <div>
                                    <label class="label" for="vehicle-reason"><span class="label-text">{{ __('Reason') }}</span></label>
                                    <select id="vehicle-reason" wire:model="vehicleReasonId" class="select select-bordered w-full @error('vehicleReasonId') select-error @enderror">
                                        <option value="">{{ __('Bitte auswählen') }}</option>
                                        @foreach ($vehicleReasons as $reason)
                                            <option value="{{ $reason->id }}">{{ $reason->name }}</option>
                                        @endforeach
                                    </select>
                                    @error('vehicleReasonId')<p class="mt-1 text-sm text-error">{{ $message }}</p>@enderror
                                </div>
                            @endif

                            <div>
                                <label class="label" for="vehicle-odometer"><span class="label-text">{{ $vehicleAction === 'exit' ? __('Odometer at exit (km)') : __('Odometer at return (km)') }}</span></label>
                                <input id="vehicle-odometer" type="text" inputmode="numeric" wire:model="odometerKm" class="input input-bordered w-full @error('odometerKm') input-error @enderror" autocomplete="off" @if ($vehicleAction === 'return') x-init="$nextTick(() => $el.focus())" @endif>
                                @error('odometerKm')<p class="mt-1 text-sm text-error">{{ $message }}</p>@enderror
                                @if ($vehicleAction === 'return' && $vehicleOut && $selectedVehicle->latestMovement->odometer_km !== null)
                                    <p class="mt-1 text-sm text-base-content/65">{{ __('It left with :km km.', ['km' => number_format($selectedVehicle->latestMovement->odometer_km, 0, '.', ' ')]) }}</p>
                                @endif
                            </div>

                            <div>
                                <p class="label"><span class="label-text">{{ __('Vehicle check') }}</span></p>
                                <div class="grid gap-2">
                                    @foreach ($vehicleSides as $side => $sideLabel)
                                        <div class="grid grid-cols-1 gap-2 sm:grid-cols-3 sm:items-center" wire:key="vehicle-side-{{ $side }}">
                                            <span class="text-sm text-base-content">{{ $sideLabel }}</span>
                                            <select wire:model.live="checkStatus.{{ $side }}" class="select select-bordered w-full">
                                                <option value="ok">{{ __('No findings') }}</option>
                                                <option value="damage">{{ __('With damage') }}</option>
                                            </select>
                                            @if (($checkStatus[$side] ?? 'ok') === 'damage')
                                                <div>
                                                    <input type="text" wire:model="checkText.{{ $side }}" class="input input-bordered w-full @error('checkText.'.$side) input-error @enderror" maxlength="200" placeholder="{{ __('Describe the damage.') }}" autocomplete="off">
                                                    @error('checkText.'.$side)<p class="mt-1 text-sm text-error">{{ $message }}</p>@enderror
                                                </div>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            </div>

                            <div>
                                <label class="label" for="vehicle-notes"><span class="label-text">{{ __('Notizen') }}</span></label>
                                <input id="vehicle-notes" type="text" wire:model="vehicleNotes" class="input input-bordered w-full" maxlength="500" placeholder="{{ __('optional') }}" autocomplete="off">
                            </div>

                            <div class="flex gap-3">
                                <button type="submit" class="btn btn-primary rounded-xl" wire:loading.attr="disabled">{{ $vehicleAction === 'exit' ? __('Register exit') : __('Register return') }}</button>
                                <button type="button" class="btn btn-outline rounded-xl" wire:click="cancelVehicleForm">{{ __('Abbrechen') }}</button>
                            </div>
                        </form>
                    @endif
                </article>
            @endif

            <article class="rounded-3xl border border-base-300 bg-base-100 p-6 sm:p-7 shadow-sm">
                <h2 class="mb-4 text-xl font-bold text-base-content">{{ __('Latest marks today') }}</h2>

                @forelse ($today as $mark)
                    <div wire:key="gate-today-{{ $mark->id }}" class="flex flex-wrap items-center gap-x-4 gap-y-1 border-t border-base-300 py-2 text-sm first:border-t-0">
                        <span class="font-semibold text-base-content">{{ $mark->occurred_at->format('H:i') }}</span>
                        <span class="badge {{ $mark->direction === \App\Enums\MovementDirection::Entry ? 'badge-success' : 'badge-warning' }} rounded-full text-xs font-semibold">{{ $mark->direction->label() }}</span>
                        <span class="text-base-content">{{ $mark->worker?->full_name ?? $mark->fleetVehicle?->label ?? trim(($mark->visitor?->first_name ?? '').' '.($mark->visitor?->name ?? '')) }}</span>
                        @if ($mark->fleet_vehicle_id)
                            <span class="badge badge-ghost rounded-full text-xs font-semibold">{{ __('Company vehicle') }}</span>
                            @if ($mark->odometer_km !== null)
                                <span class="text-base-content/65">{{ number_format($mark->odometer_km, 0, '.', ' ') }} km</span>
                            @endif
                        @endif
                        @if ($mark->visitor_id)
                            <span class="badge badge-ghost rounded-full text-xs font-semibold">{{ __('Visitor') }}</span>
                        @endif
                        <span class="text-base-content/65">{{ $mark->reason?->name }}@if ($mark->destination) · {{ $mark->destination->name }}@endif</span>
                    </div>
                @empty
                    <p class="text-sm text-base-content/65">{{ __('No marks registered today.') }}</p>
                @endforelse
            </article>
        </div>

        <aside class="rounded-3xl border border-base-300 bg-base-100 p-6 shadow-sm" wire:poll.30s>
            <div class="mb-1 flex items-center justify-between gap-3">
                <h2 class="text-xl font-bold text-base-content">{{ __('Outside now') }}</h2>
                @if ($outside->isNotEmpty())
                    <button type="button" class="btn btn-ghost btn-sm rounded-xl" wire:click="toggleClosingShift">{{ $closingShift ? __('Abbrechen') : __('Close shift') }}</button>
                @endif
            </div>
            <p class="mb-4 text-sm text-base-content/65">{{ trans_choice(':count person|:count people', $outside->count(), ['count' => $outside->count()]) }}</p>

            @if ($closingShift && $outside->isNotEmpty())
                <div class="alert alert-warning mb-4 rounded-2xl" role="status">
                    <span>{{ __('Mark who did not return, or leave them open for tomorrow.') }}</span>
                    <button type="button" class="btn btn-primary btn-sm rounded-xl" wire:click="markAllNoReturn" wire:confirm="{{ __('Mark everybody outside as did not return?') }}">{{ __('Mark all as did not return') }}</button>
                </div>
            @endif

            @forelse ($outside as $mark)
                <div wire:key="gate-outside-{{ $mark->id }}" class="flex items-center justify-between gap-3 border-t border-base-300 py-3 first:border-t-0">
                    <div class="min-w-0">
                        <p class="truncate text-sm font-semibold text-base-content">{{ $mark->worker->full_name }}</p>
                        <p class="text-xs text-base-content/65">
                            {{ $mark->reason?->name }}@if ($mark->destination) · {{ $mark->destination->name }}@endif
                            @if ($mark->route_number) · {{ __('Route') }} {{ $mark->route_number }}@endif
                        </p>
                        <p class="text-xs text-base-content/55">{{ $mark->occurred_at->isToday() ? $mark->occurred_at->format('H:i') : $mark->occurred_at->format('d/m H:i') }} · {{ $elapsed($mark) }}</p>
                    </div>
                    <div class="flex shrink-0 gap-2">
                        @if ($closingShift)
                            <button type="button" class="btn btn-outline btn-sm rounded-xl" wire:click="markNoReturn({{ $mark->worker_id }})" wire:loading.attr="disabled">{{ __('Did not return') }}</button>
                        @endif
                        <button type="button" class="btn btn-outline btn-sm rounded-xl" wire:click="registerReturn({{ $mark->worker_id }})" wire:loading.attr="disabled">{{ __('Return') }}</button>
                    </div>
                </div>
            @empty
                <p class="text-sm text-base-content/65">{{ __('Nobody is outside.') }}</p>
            @endforelse

            <div class="mt-6 border-t border-base-300 pt-4">
                <h3 class="mb-1 text-lg font-bold text-base-content">{{ __('Vehicles outside') }}</h3>
                <p class="mb-3 text-sm text-base-content/65">{{ trans_choice(':count vehicle|:count vehicles', $vehiclesOutside->count(), ['count' => $vehiclesOutside->count()]) }}</p>

                @forelse ($vehiclesOutside as $mark)
                    <div wire:key="gate-vehicle-out-{{ $mark->id }}" class="flex items-center justify-between gap-3 border-t border-base-300 py-3 first:border-t-0">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-semibold text-base-content">{{ $mark->fleetVehicle?->label }}</p>
                            <p class="text-xs text-base-content/65">
                                {{ $mark->driver?->full_name }}@if ($mark->reason) · {{ $mark->reason->name }}@endif
                            </p>
                            <p class="text-xs text-base-content/55">
                                {{ $mark->occurred_at->isToday() ? $mark->occurred_at->format('H:i') : $mark->occurred_at->format('d/m H:i') }} · {{ $elapsed($mark) }}@if ($mark->odometer_km !== null) · {{ number_format($mark->odometer_km, 0, '.', ' ') }} km @endif
                            </p>
                        </div>
                        <button type="button" class="btn btn-outline btn-sm rounded-xl" wire:click="openVehicleReturn({{ $mark->fleet_vehicle_id }})" wire:loading.attr="disabled">{{ __('Return') }}</button>
                    </div>
                @empty
                    <p class="text-sm text-base-content/65">{{ __('No vehicle is outside.') }}</p>
                @endforelse
            </div>

            <details class="mt-6 border-t border-base-300 pt-4">
                <summary class="cursor-pointer text-sm font-semibold text-base-content">{{ __('Absent today') }} · {{ $absentToday->count() }}</summary>
                <div class="mt-3">
                    @forelse ($absentToday as $absence)
                        <p wire:key="gate-absent-{{ $absence->id }}" class="flex items-center justify-between gap-3 py-1 text-sm">
                            <span class="truncate text-base-content">{{ $absence->worker->full_name }}</span>
                            <span class="shrink-0 text-xs text-base-content/65">{{ __('until :date', ['date' => $absence->ends_on->format('d/m')]) }}</span>
                        </p>
                    @empty
                        <p class="text-sm text-base-content/65">{{ __('Nobody is absent today.') }}</p>
                    @endforelse
                </div>
            </details>

            <details class="mt-3 border-t border-base-300 pt-4">
                <summary class="cursor-pointer text-sm font-semibold text-base-content">{{ __('Returning this week') }} · {{ $returningSoon->count() }}</summary>
                <div class="mt-3">
                    @forelse ($returningSoon as $absence)
                        <p wire:key="gate-returning-{{ $absence->id }}" class="flex items-center justify-between gap-3 py-1 text-sm">
                            <span class="truncate text-base-content">{{ $absence->worker->full_name }}</span>
                            <span class="shrink-0 text-xs text-base-content/65">{{ $absence->return_date->format('d/m') }}</span>
                        </p>
                    @empty
                        <p class="text-sm text-base-content/65">{{ __('Nobody returns this week.') }}</p>
                    @endforelse
                </div>
            </details>
        </aside>
    </div>
</div>
