<?php

namespace App\Services\Snapshot;

use App\Services\CustomersList\FilterCriteria;

/**
 * The PHP twin of QueryBuilder::where() for snapshot rows: turns a validated
 * FilterCriteria into a predicate over one record's fields.
 *
 * Same business rules as the SQL, dimension by dimension:
 *   - list filters (REGION, DIVISION, AGENCE, STATUS, SEGMENTATION,
 *     SEGMENT_TRESOR, METER, VOLTAGE, NUI_QC): the row's value must equal one
 *     of the selected values (SQL `col IN (...)`). Compared on the trimmed
 *     field, which is exactly how the selectable values are built
 *     (filter-options trims), so a selectable value always matches its rows;
 *   - DATE_AB: dateFrom <= date < dateTo + 1 day, i.e. both ends inclusive
 *     at day granularity; a row whose date can't be read never matches a
 *     date filter (SQL: NULL never satisfies a comparison);
 *   - all active dimensions are ANDed; no filter = every row.
 * The export has no free-text search (QueryBuilder::withSearch is table-only).
 */
final class RowMatcher
{
    /** Column => FilterCriteria property, in QueryBuilder::where() order. */
    public const LIST_FILTERS = [
        'REGION'         => 'regions',
        'DIVISION'       => 'divisions',
        'AGENCE'         => 'agences',
        'STATUS'         => 'statuses',
        'SEGMENTATION'   => 'segmentations',
        'SEGMENT_TRESOR' => 'segmentsTresor',
        'METER'          => 'meters',
        'VOLTAGE'        => 'voltages',
        'NUI_QC'         => 'niuQualities',
    ];

    /** @var list<array{int, array<string, true>}> field index => accepted values */
    private array $lists = [];

    private ?int $dateIdx = null;
    private ?string $from = null;
    private ?string $to   = null;
    private ?SnapshotDate $date = null;

    /**
     * @param array<string, int> $index Column name => field index.
     */
    public function __construct(FilterCriteria $criteria, array $index, ?string $dateFormat)
    {
        foreach (self::LIST_FILTERS as $column => $property) {
            $values = $criteria->{$property};
            if ($values !== []) {
                $this->lists[] = [$index[$column], array_fill_keys(array_map('strval', $values), true)];
            }
        }

        if ($criteria->dateFrom !== null || $criteria->dateTo !== null) {
            if ($dateFormat === null) {
                throw new SnapshotException('date_format_unknown', 'Format de DATE_AB inconnu pour ce snapshot : filtre de date impossible.');
            }
            $this->dateIdx = $index[FilterCriteria::DATE_COLUMN];
            $this->from    = $criteria->dateFrom?->format('Y-m-d');
            $this->to      = $criteria->dateTo?->format('Y-m-d');
            $this->date    = new SnapshotDate($dateFormat);
        }
    }

    public function isUnfiltered(): bool
    {
        return $this->lists === [] && $this->dateIdx === null;
    }

    /**
     * @param list<string> $fields
     */
    public function matches(array $fields): bool
    {
        foreach ($this->lists as [$i, $accepted]) {
            if (! isset($accepted[trim($fields[$i])])) {
                return false;
            }
        }

        if ($this->dateIdx !== null) {
            $ymd = $this->date->toYmd($fields[$this->dateIdx]);
            if ($ymd === false) {
                return false;
            }
            if ($this->from !== null && $ymd < $this->from) {
                return false;
            }
            if ($this->to !== null && $ymd > $this->to) {
                return false;
            }
        }

        return true;
    }
}
