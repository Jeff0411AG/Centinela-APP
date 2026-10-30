<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\Worker;
use Illuminate\Auth\Access\HandlesAuthorization;

class WorkerPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('ViewAny:Worker');
    }

    public function view(User $user, Worker $worker): bool
    {
        return $user->can('View:Worker');
    }

    public function create(User $user): bool
    {
        return $user->can('Create:Worker');
    }

    public function update(User $user, Worker $worker): bool
    {
        return $user->can('Update:Worker');
    }

    public function delete(User $user, Worker $worker): bool
    {
        return $user->can('Delete:Worker');
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('DeleteAny:Worker');
    }
}
