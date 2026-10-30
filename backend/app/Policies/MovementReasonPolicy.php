<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Policies;

use App\Models\MovementReason;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class MovementReasonPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('ViewAny:MovementReason');
    }

    public function view(User $user, MovementReason $reason): bool
    {
        return $user->can('View:MovementReason');
    }

    public function create(User $user): bool
    {
        return $user->can('Create:MovementReason');
    }

    public function update(User $user, MovementReason $reason): bool
    {
        return $user->can('Update:MovementReason');
    }

    public function delete(User $user, MovementReason $reason): bool
    {
        return $user->can('Delete:MovementReason') && ! $reason->movements()->exists();
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('DeleteAny:MovementReason');
    }
}
