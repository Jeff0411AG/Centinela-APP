<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Support;

use Illuminate\Support\Str;

/**
 * The text shown for a role. The role name (guard, hr...) is an internal key that the code, the permission
 * sync and the login redirects depend on, so it is never renamed; only this label changes. Edit the
 * "role.<name>" entries in lang/es.json to rename what people see.
 */
class RoleLabel
{
    public static function for(string $name): string
    {
        $key = 'role.'.$name;
        $label = __($key);

        return $label === $key ? Str::headline($name) : $label;
    }
}
