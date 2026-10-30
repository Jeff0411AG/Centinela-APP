<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Policies;

use App\Models\StaffVehicle;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class StaffVehiclePolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('ViewAny:StaffVehicle');
    }

    public function view(User $user, StaffVehicle $vehicle): bool
    {
        return $user->can('View:StaffVehicle');
    }

    public function create(User $user): bool
    {
        return $user->can('Create:StaffVehicle');
    }

    public function update(User $user, StaffVehicle $vehicle): bool
    {
        return $user->can('Update:StaffVehicle');
    }

    public function delete(User $user, StaffVehicle $vehicle): bool
    {
        return $user->can('Delete:StaffVehicle');
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('DeleteAny:StaffVehicle');
    }
}
