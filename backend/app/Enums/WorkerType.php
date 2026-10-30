<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Enums;

enum WorkerType: string
{
    case Employee = 'EMP';
    case Laborer = 'OBR';
    case Operator = 'OPER';
    case Management = 'GG';
    case Administrative = 'ADM';

    public function label(): string
    {
        return match ($this) {
            self::Employee => __('Employee'),
            self::Laborer => __('Laborer'),
            self::Operator => __('Operator'),
            self::Management => __('Management'),
            self::Administrative => __('Administrative'),
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

    /**
     * Maps the spellings found in the legacy spreadsheets (" EMP ", "OBRE", "obrero"...) to a type.
     */
    public static function fromLegacy(?string $value): ?self
    {
        $normalized = strtoupper(trim((string) $value));

        return match ($normalized) {
            'EMP', 'EMPLEADO', 'EMPLEADA' => self::Employee,
            'OBR', 'OBRE', 'OBRERO', 'OBRERA' => self::Laborer,
            'OPER', 'OPERARIO', 'OPERARIA' => self::Operator,
            'GG', 'GERENCIA', 'GERENTE' => self::Management,
            'ADM', 'ADMINISTRATIVO', 'ADMINISTRATIVA' => self::Administrative,
            default => null,
        };
    }
}
