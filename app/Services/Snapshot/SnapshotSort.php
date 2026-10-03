<?php

namespace App\Services\Snapshot;

use App\Services\CustomersList\QueryBuilder;

/**
 * Row-level rules shared by every SnapshotQueryEngine — written once here and
 * mirrored in SQL by SnapshotDuckDbEngine (the parity tests check both):
 *
 *   record     filter columns trimmed (as RowMatcher compares them), the 4
 *              date columns as 'YYYY-MM-DD' (null when blank / unreadable)
 *   search     the table's free text: case-insensitive substring of
 *              CUST_NAME or METER_NO, or substring of CONTRACT / COD_CLI —
 *              literal, no wildcard (same as QueryBuilder::withSearch())
 *   sort       CONTRACT / COD_CLI numerically (non-numeric after), dates
 *              chronologically, text by byte order; ascending puts blanks /
 *              nulls last, descending first (Oracle's default)
 *   tableRow   every QueryBuilder::ALL_COLUMNS key, '' for a column the
 *              snapshot does not carry (XCOORD / YCOORD)
 */
final class SnapshotSort
{
    public const DATE_COLUMNS    = ['DATE_AB', 'DATE_RESILIATION', 'LAST_VC_DATE', 'POSTPAID_PROFILE_DATE'];
    public const NUMERIC_COLUMNS = ['CONTRACT', 'COD_CLI'];
    public const SEARCH_UPPER    = ['CUST_NAME', 'METER_NO'];
    public const SEARCH_PLAIN    = ['CONTRACT', 'COD_CLI'];

    /**
     * @param list<string>       $fields
     * @param array<string, int> $index
     *
     * @return array<string, string|null>
     */
    public static function record(array $fields, array $index, ?SnapshotDate $date): array
    {
        $r = [];
        foreach ($index as $column => $i) {
            $r[$column] = $fields[$i];
        }
        foreach (array_keys(RowMatcher::LIST_FILTERS) as $column) {
            if (isset($r[$column])) {
                $r[$column] = trim($r[$column]);
            }
        }
        foreach (self::DATE_COLUMNS as $column) {
            if (! isset($r[$column])) {
                continue;
            }
            $ymd        = $date !== null && trim($r[$column]) !== '' ? $date->toYmd($r[$column]) : false;
            $r[$column] = $ymd === false ? null : $ymd;
        }

        return $r;
    }

    /** Upper-cased trimmed search text, null when there is none. */
    public static function searchNeedle(string $search): ?string
    {
        $search = trim($search);

        return $search === '' ? null : mb_strtoupper($search);
    }

    /** @param array<string, string|null> $r */
    public static function matchesSearch(array $r, string $needle): bool
    {
        foreach (self::SEARCH_UPPER as $column) {
            if (str_contains(mb_strtoupper((string) ($r[$column] ?? '')), $needle)) {
                return true;
            }
        }
        foreach (self::SEARCH_PLAIN as $column) {
            if (str_contains((string) ($r[$column] ?? ''), $needle)) {
                return true;
            }
        }

        return false;
    }

    /** -1 / 0 / 1 for two values of $column in the requested direction. */
    public static function compare(string $column, ?string $a, ?string $b, bool $desc): int
    {
        if (in_array($column, self::NUMERIC_COLUMNS, true)) {
            $na = $a !== null && ctype_digit($a) ? ltrim($a, '0') : null;
            $nb = $b !== null && ctype_digit($b) ? ltrim($b, '0') : null;
            $c  = self::nullsLast($na, $nb) ?? (strlen($na) <=> strlen($nb) ?: strcmp($na, $nb) <=> 0);
            if ($c === 0 && $na === null) {
                $c = strcmp((string) $a, (string) $b) <=> 0; // both non-numeric: text order
            }
        } elseif (in_array($column, self::DATE_COLUMNS, true)) {
            $c = self::nullsLast($a, $b) ?? (strcmp($a, $b) <=> 0);
        } else {
            $c = strcmp((string) $a, (string) $b) <=> 0;
        }

        return $desc ? -$c : $c;
    }

    /**
     * @param array<string, string|null> $r
     *
     * @return array<string, string|null>
     */
    public static function tableRow(array $r): array
    {
        $row = [];
        foreach (QueryBuilder::ALL_COLUMNS as $column) {
            $row[$column] = array_key_exists($column, $r) ? $r[$column] : '';
        }

        return $row;
    }

    /** Null-ordering part of compare(): null when both are non-null. */
    private static function nullsLast(?string $a, ?string $b): ?int
    {
        if ($a === null || $b === null) {
            return ($a === null) <=> ($b === null);
        }

        return null;
    }
}
