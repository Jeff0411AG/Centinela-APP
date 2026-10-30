<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Policies;

use App\Models\FleetVehicle;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class FleetVehiclePolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('ViewAny:FleetVehicle');
    }

    public function view(User $user, FleetVehicle $vehicle): bool
    {
        return $user->can('View:FleetVehicle');
    }

    public function create(User $user): bool
    {
        return $user->can('Create:FleetVehicle');
    }

    public function update(User $user, FleetVehicle $vehicle): bool
    {
        return $user->can('Update:FleetVehicle');
    }

    /**
     * A vehicle with marks in the log is deactivated, never deleted.
     */
    public function delete(User $user, FleetVehicle $vehicle): bool
    {
        return $user->can('Delete:FleetVehicle') && ! $vehicle->movements()->exists();
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('DeleteAny:FleetVehicle');
    }
}
