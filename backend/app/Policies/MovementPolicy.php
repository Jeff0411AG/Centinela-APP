<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Policies;

use App\Models\Movement;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Movements are an append-only log: nobody can edit or delete a row, whatever their role.
 * Creating a mark is allowed to the roles that work the gate (Create:Movement).
 */
class MovementPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('ViewAny:Movement');
    }

    public function view(User $user, Movement $movement): bool
    {
        return $user->can('View:Movement');
    }

    public function create(User $user): bool
    {
        return $user->can('Create:Movement');
    }

    /**
     * Correcting or voiding adds a new mark that points at this one; it never edits it. Only marks that
     * still count can be corrected.
     */
    public function correct(User $user, Movement $movement): bool
    {
        return $user->can('Correct:Movement') && $movement->isEffective();
    }

    public function update(User $user, Movement $movement): bool
    {
        return false;
    }

    public function delete(User $user, Movement $movement): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
