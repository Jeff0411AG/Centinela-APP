<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Support;

/**
 * Plates are stored without dashes, spaces or lower case ("bsu 383" and "BSU-383" are the same plate)
 * and shown the way people write them (ABC-123, 0129-CC).
 */
final class Plate
{
    /** Shortest and longest plate accepted once normalised (older and newer formats exist). */
    private const MIN_LENGTH = 5;

    private const MAX_LENGTH = 7;

    public static function normalize(?string $plate): ?string
    {
        $normalized = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $plate) ?? '');

        return $normalized === '' ? null : $normalized;
    }

    public static function isValid(?string $plate): bool
    {
        $normalized = self::normalize($plate);

        return $normalized !== null
            && strlen($normalized) >= self::MIN_LENGTH
            && strlen($normalized) <= self::MAX_LENGTH
            && preg_match('/[A-Z]/', $normalized) === 1
            && preg_match('/\d/', $normalized) === 1;
    }

    public static function format(?string $plate): ?string
    {
        $normalized = self::normalize($plate);

        if ($normalized === null) {
            return null;
        }

        // Motorcycles: four digits then the letters (0129-CC); everything else: three characters, a dash, the rest.
        if (preg_match('/^(\d{4})([A-Z]{1,2})$/', $normalized, $match) === 1) {
            return $match[1].'-'.$match[2];
        }

        return strlen($normalized) > 3 ? substr($normalized, 0, 3).'-'.substr($normalized, 3) : $normalized;
    }
}
