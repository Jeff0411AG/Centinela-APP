<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Policies;

use App\Models\Destination;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class DestinationPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('ViewAny:Destination');
    }

    public function view(User $user, Destination $destination): bool
    {
        return $user->can('View:Destination');
    }

    public function create(User $user): bool
    {
        return $user->can('Create:Destination');
    }

    public function update(User $user, Destination $destination): bool
    {
        return $user->can('Update:Destination');
    }

    public function delete(User $user, Destination $destination): bool
    {
        return $user->can('Delete:Destination') && ! $destination->movements()->exists();
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('DeleteAny:Destination');
    }
}
