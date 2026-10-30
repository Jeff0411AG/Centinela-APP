<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Policies;

use App\Models\Absence;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class AbsencePolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('ViewAny:Absence');
    }

    public function view(User $user, Absence $absence): bool
    {
        return $user->can('View:Absence');
    }

    public function create(User $user): bool
    {
        return $user->can('Create:Absence');
    }

    public function update(User $user, Absence $absence): bool
    {
        return $user->can('Update:Absence');
    }

    public function delete(User $user, Absence $absence): bool
    {
        return $user->can('Delete:Absence');
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('DeleteAny:Absence');
    }
}
