<?php

namespace App\Services;

/**
 * PhoneNumberService
 * 
 * Normalizes Indonesian phone numbers to canonical format: 628xxxxxxxxxx
 * Used by Event System Analytics for consistent customer grouping.
 * 
 * Phase 1: Event System Analytics
 */
class PhoneNumberService
{
    /**
     * Normalize an Indonesian phone number to canonical format 628xxxxxxxxxx.
     *
     * Handles:
     *   08123456789   → 628123456789
     *   628123456789  → 628123456789
     *   +628123456789 → 628123456789
     *   8123456789    → 628123456789
     *
     * Returns null for invalid/empty/null input without throwing exceptions.
     *
     * @param string|null $phone Raw phone number
     * @return string|null Normalized phone or null if invalid
     */
    public static function normalize(?string $phone): ?string
    {
        if ($phone === null || trim($phone) === '') {
            return null;
        }

        // Strip all non-digit characters (removes +, -, spaces, etc.)
        $digits = preg_replace('/[^0-9]/', '', trim($phone));

        if ($digits === '' || strlen($digits) < 9) {
            return null;
        }

        // Handle various prefixes
        if (str_starts_with($digits, '62')) {
            // Already in 62xxx format — validate length
            $normalized = $digits;
        } elseif (str_starts_with($digits, '0')) {
            // 08xxx → 628xxx
            $normalized = '62' . substr($digits, 1);
        } elseif (str_starts_with($digits, '8')) {
            // 8xxx → 628xxx
            $normalized = '62' . $digits;
        } else {
            // Unknown format
            return null;
        }

        // Indonesian mobile numbers: 62 + 8-13 digits = 11-15 total digits
        if (strlen($normalized) < 11 || strlen($normalized) > 15) {
            return null;
        }

        // Must start with 628 (Indonesian mobile)
        if (!str_starts_with($normalized, '628')) {
            return null;
        }

        return $normalized;
    }

    /**
     * Mask a normalized phone number for admin display.
     * 
     * Example: 628123456789 → 6281****6789
     *
     * @param string $phone Normalized phone number
     * @return string Masked phone number
     */
    public static function mask(string $phone): string
    {
        $len = strlen($phone);

        if ($len <= 8) {
            // Too short to mask meaningfully
            return str_repeat('*', $len);
        }

        // Show first 4 chars + mask middle + show last 4 chars
        $prefix = substr($phone, 0, 4);
        $suffix = substr($phone, -4);
        $maskLen = $len - 8;

        return $prefix . str_repeat('*', $maskLen) . $suffix;
    }
}
