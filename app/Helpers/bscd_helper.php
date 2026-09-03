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

if (! function_exists('user_can')) {
    /**
     * True when the logged-in user holds $permission (checked against the
     * permission list built at login from MariaDB — never AD groups).
     */
    function user_can(string $permission): bool
    {
        return \App\Services\AuthorizationService::fromSession()->can($permission);
    }
}

if (! function_exists('user_can_any')) {
    /**
     * @param list<string> $permissions
     */
    function user_can_any(array $permissions): bool
    {
        return \App\Services\AuthorizationService::fromSession()->canAny($permissions);
    }
}

if (! function_exists('user_has_role')) {
    function user_has_role(string $code): bool
    {
        return \App\Services\AuthorizationService::fromSession()->hasRole($code);
    }
}

if (! function_exists('current_user_id')) {
    function current_user_id(): ?int
    {
        $id = session()->get('user_id');

        return $id === null ? null : (int) $id;
    }
}

if (! function_exists('format_date_fr')) {
    /**
     * User-facing date, French convention: "2026-09-03" (or a full datetime)
     * becomes "03/09/2026". Presentation only — never use this on a value that
     * is sent to Oracle / MariaDB / an API / a form (those keep their technical
     * format).
     *
     * NULL, "", "0000-00-00" or an unparseable value return $placeholder
     * ("—" by default) — never 01/01/1970 or "Invalid Date".
     */
    function format_date_fr(?string $value, string $placeholder = '—'): string
    {
        $value = trim((string) $value);

        if ($value === '' || str_starts_with($value, '0000')) {
            return $placeholder;
        }
        if (preg_match('#^\d{2}/\d{2}/\d{4}$#', $value)) {
            return $value; // already dd/mm/yyyy
        }

        $ts = strtotime($value);

        return $ts === false ? $placeholder : date('d/m/Y', $ts);
    }
}

if (! function_exists('format_datetime_fr')) {
    /**
     * User-facing date + time: "2026-09-03 14:30:00" becomes "03/09/2026 14:30"
     * (seconds dropped from the display; the stored value is untouched).
     * Same NULL / invalid handling as format_date_fr().
     */
    function format_datetime_fr(?string $value, string $placeholder = '—'): string
    {
        $value = trim((string) $value);

        if ($value === '' || str_starts_with($value, '0000')) {
            return $placeholder;
        }
        if (preg_match('#^\d{2}/\d{2}/\d{4}( \d{2}:\d{2})?$#', $value)) {
            return $value; // already dd/mm/yyyy[ HH:mm]
        }

        $ts = strtotime($value);

        return $ts === false ? $placeholder : date('d/m/Y H:i', $ts);
    }
}

if (! function_exists('fr_date_to_iso')) {
    /**
     * Canonical "dd/mm/yyyy" -> "yyyy-mm-dd" conversion (the reverse of
     * format_date_fr()). Strict: only a real calendar day is accepted —
     * "31/02/2026", "32/01/2026", "00/01/2026", "01/13/2026" all return null.
     *
     * This is the reference contract for the same rules implemented client-side
     * in public/assets/js/frdatepicker.js. It is NOT wired into the request
     * flow — the date filters still travel as ISO from the browser and
     * FilterCriteria still validates them as "Y-m-d". Kept here so the rules
     * have one tested definition and are available for any server-side need.
     */
    function fr_date_to_iso(?string $value): ?string
    {
        $value = trim((string) $value);

        if (! preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $value, $m)) {
            return null;
        }

        [$day, $month, $year] = [(int) $m[1], (int) $m[2], (int) $m[3]];

        if (! checkdate($month, $day, $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }
}

if (! function_exists('parse_fr_date')) {
    /**
     * "dd/mm/yyyy" -> ['y'=>int,'m'=>int,'d'=>int] or null. Same strict rules
     * as fr_date_to_iso().
     *
     * @return array{y:int, m:int, d:int}|null
     */
    function parse_fr_date(?string $value): ?array
    {
        $iso = fr_date_to_iso($value);

        if ($iso === null) {
            return null;
        }

        [$y, $m, $d] = array_map('intval', explode('-', $iso));

        return ['y' => $y, 'm' => $m, 'd' => $d];
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
