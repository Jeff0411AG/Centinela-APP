<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Enums;

use Illuminate\Support\Str;

enum VehicleKind: string
{
    case Car = 'car';
    case Motorcycle = 'motorcycle';
    case Bicycle = 'bicycle';
    case Scooter = 'scooter';
    case ElectricMotorcycle = 'electric_motorcycle';

    public function label(): string
    {
        return match ($this) {
            self::Car => __('Car'),
            self::Motorcycle => __('Motorcycle'),
            self::Bicycle => __('Bicycle'),
            self::Scooter => __('Scooter'),
            self::ElectricMotorcycle => __('Electric motorcycle'),
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
     * Understands the words of the legacy sheets ("BICICLETA", "MOTO ELEC.", "SCOOTER"...).
     */
    public static function fromLegacy(?string $value): ?self
    {
        $normalized = Str::of(Str::ascii((string) $value))->lower()->replaceMatches('/[^a-z]+/', ' ')->trim()->toString();

        return match (true) {
            $normalized === '' => null,
            str_contains($normalized, 'elec') => self::ElectricMotorcycle,
            str_starts_with($normalized, 'bici') => self::Bicycle,
            str_starts_with($normalized, 'scoot'), str_starts_with($normalized, 'patin') => self::Scooter,
            str_starts_with($normalized, 'moto') => self::Motorcycle,
            str_starts_with($normalized, 'auto'), str_starts_with($normalized, 'carro'), str_starts_with($normalized, 'camion'), $normalized === 'car' => self::Car,
            default => null,
        };
    }
}
