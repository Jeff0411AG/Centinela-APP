<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Enums;

enum MovementDirection: string
{
    case Exit = 'out';
    case Entry = 'in';
    case NoReturn = 'no_return';
    case Void = 'void';

    public function label(): string
    {
        return match ($this) {
            self::Exit => __('Exit'),
            self::Entry => __('Entry'),
            self::NoReturn => __('Did not return'),
            self::Void => __('Voided'),
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
