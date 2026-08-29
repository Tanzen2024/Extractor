<?php

if (! function_exists('bscd_uuid')) {
    /**
     * Generates a random UUID v4.
     */
    function bscd_uuid(): string
    {
        $data = random_bytes(16);

        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}

if (! function_exists('bscd_number')) {
    /**
     * Formats an integer with a space as the thousands separator (French convention).
     */
    function bscd_number(?int $value): string
    {
        return number_format($value ?? 0, 0, ',', ' ');
    }
}

if (! function_exists('bscd_percent')) {
    /**
     * Formats $part as a percentage of $total ("0%" when $total is 0, never a division-by-zero warning).
     */
    function bscd_percent(?int $part, ?int $total, int $decimals = 2): string
    {
        if (! $total) {
            return '0%';
        }

        return number_format(($part ?? 0) / $total * 100, $decimals, ',', '.') . '%';
    }
}

if (! function_exists('bscd_error_reference')) {
    /**
     * Generates a short, user-facing error reference (never expose raw exception
     * messages to end users — see EXT-YYYYMMDD-XXXXX pattern used across the app).
     */
    function bscd_error_reference(string $prefix = 'ERR'): string
    {
        return sprintf('%s-%s-%05d', $prefix, date('Ymd'), random_int(1, 99999));
    }
}
