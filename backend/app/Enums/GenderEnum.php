<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum GenderEnum: string implements HasLabel
{
    case Male = 'male';
    case Female = 'female';
    case Other = 'other';

    case Not_Specified = 'not specified';

    public function getLabel(): string
    {
        return match ($this) {
            self::Male => __('Male'),
            self::Female => __('Female'),
            self::Other => __('Other'),
            self::Not_Specified => __('Not specified'),
        };
    }
}
