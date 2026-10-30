<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Enums;

enum AbsenceType: string
{
    case Vacation = 'vacation';
    case MedicalLeave = 'medical_leave';
    case Leave = 'leave';

    public function label(): string
    {
        return match ($this) {
            self::Vacation => __('Vacation'),
            self::MedicalLeave => __('Medical leave'),
            self::Leave => __('Other leave'),
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
     * Understands the words used in the legacy sheets ("VACACIONES", "DESCANSOS MEDICOS"...).
     */
    public static function fromLegacy(?string $value): ?self
    {
        $normalized = strtolower(trim(preg_replace('/[^a-z]+/', ' ', strtolower(\Illuminate\Support\Str::ascii((string) $value))) ?? ''));

        return match (true) {
            $normalized === '' => null,
            str_starts_with($normalized, 'vac') => self::Vacation,
            str_contains($normalized, 'medic'), str_starts_with($normalized, 'dm') => self::MedicalLeave,
            str_starts_with($normalized, 'lic'), str_starts_with($normalized, 'perm'), str_starts_with($normalized, 'otro') => self::Leave,
            default => null,
        };
    }
}
