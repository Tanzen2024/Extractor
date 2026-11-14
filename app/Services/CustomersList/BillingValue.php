<?php

namespace App\Services\CustomersList;

/**
 * Amount billed over 12 months and the VALUE commercial segmentation.
 *
 * Extractor computes neither: both come from the source query
 * (docs/snapshot/source/customers_list.sql, columns 31 and 32):
 *   AMOUNT_BILLED_12M  whole FCFA billed over the 12 closed months before the
 *                      extraction month (CMSADMIN.RECIBOS, cancelled bills
 *                      excluded); 0 without a retained bill, never blank in a
 *                      source carrying the column;
 *   VALUE_SEGMENT      Top 20% | Medium 50% | Bottom 30% for the ranked
 *                      population (active, billed universe, amount > 0),
 *                      empty for everyone else.
 * The ranking is done once, upstream, on the whole eligible population: the
 * same label whatever the engine or the screen filters.
 *
 * Both columns are optional in a snapshot (versions installed before the
 * source carried them): the table and the exports then show them empty, the
 * Value filter is not offered (no option) and, if sent anyway, matches no row.
 */
final class BillingValue
{
    public const AMOUNT_COLUMN  = 'AMOUNT_BILLED_12M';
    public const SEGMENT_COLUMN = 'VALUE_SEGMENT';

    /** Columns, in source / export order. */
    public const COLUMNS = [self::AMOUNT_COLUMN, self::SEGMENT_COLUMN];

    public const TOP    = 'Top 20%';
    public const MEDIUM = 'Medium 50%';
    public const BOTTOM = 'Bottom 30%';

    /** Business order (filter, chips). @var list<string> */
    public const VALUES = [self::TOP, self::MEDIUM, self::BOTTOM];

    /** Request key of the filter (value[]=…). */
    public const REQUEST_KEY = 'value';

    public static function hasAmount(array $columns): bool
    {
        return in_array(self::AMOUNT_COLUMN, $columns, true);
    }

    public static function hasSegment(array $columns): bool
    {
        return in_array(self::SEGMENT_COLUMN, $columns, true);
    }

    /**
     * Whole FCFA amount of a snapshot value, null when blank or unreadable
     * (the source writes integers; a sign is allowed: a net can be negative).
     */
    public static function amount(?string $raw): ?int
    {
        $raw = trim((string) $raw);

        return preg_match('/^-?\d{1,15}$/', $raw) === 1 ? (int) $raw : null;
    }
}
