<?php

namespace App\Services\Snapshot;

use App\Services\CustomersList\ActiveCustomers;

/**
 * Builds, in the install's single validation pass, the same structure
 * DashboardService::filterOptions() computes from Oracle (value lists with
 * counts, region -> division -> agence tree, DATE_AB bounds) — so export
 * filter validation (AllowedValues::fromFilterOptions) needs no Oracle.
 *
 * Same rules as DashboardService: flat values trimmed, blanks not offered;
 * geo levels labelled "Non renseigné" when blank; lists by count desc (ties
 * by value), geo tree by label; the date-picker minimum floored at
 * 1990-01-01.
 *
 * Only in-perimeter rows (ActiveCustomers) are counted: an excluded status,
 * or a region / agence / segment / date held only by excluded customers, is
 * never offered. build() is shared with SnapshotDuckDbEngine::filterOptions()
 * so both produce the very same payload from the same counts.
 */
final class SnapshotFilterOptions
{
    private const EMPTY_LABEL = 'Non renseigné';

    /** Output key => source column, for the flat lists. */
    public const FLAT = [
        'regions'        => 'REGION',
        'statuses'       => 'STATUS',
        'segmentations'  => 'SEGMENTATION',
        'segmentsTresor' => 'SEGMENT_TRESOR',
        'meters'         => 'METER',
        'voltages'       => 'VOLTAGE',
        'niuQualities'   => 'NUI_QC',
    ];

    /** @var array<string, int> key => field index */
    private array $flatIdx = [];

    /** @var array<string, array<string, int>> */
    private array $flat = [];

    /** @var array<string, int> "region\0division\0agence" => count */
    private array $geo = [];

    private ?string $minDate = null;
    private ?string $maxDate = null;

    /**
     * @param array<string, int> $index Column name => field index.
     */
    public function __construct(private readonly array $index)
    {
        foreach (self::FLAT as $key => $column) {
            $this->flatIdx[$key] = $index[$column];
            $this->flat[$key]    = [];
        }
    }

    /**
     * @param list<string> $fields
     */
    public function add(array $fields, string|false $dateYmd): void
    {
        if (! ActiveCustomers::isActive($fields[$this->index[ActiveCustomers::STATUS_COLUMN]])) {
            return;
        }

        foreach ($this->flatIdx as $key => $i) {
            $value = trim($fields[$i]);
            if ($value !== '') {
                $this->flat[$key][$value] = ($this->flat[$key][$value] ?? 0) + 1;
            }
        }

        $geoKey = self::label($fields[$this->index['REGION']]) . "\0"
            . self::label($fields[$this->index['DIVISION']]) . "\0"
            . self::label($fields[$this->index['AGENCE']]);
        $this->geo[$geoKey] = ($this->geo[$geoKey] ?? 0) + 1;

        if ($dateYmd !== false) {
            if ($this->minDate === null || $dateYmd < $this->minDate) {
                $this->minDate = $dateYmd;
            }
            if ($this->maxDate === null || $dateYmd > $this->maxDate) {
                $this->maxDate = $dateYmd;
            }
        }
    }

    /**
     * @return array<string, mixed> Same shape as DashboardService::filterOptions().
     */
    public function result(): array
    {
        return self::build($this->flat, $this->geo, $this->minDate, $this->maxDate);
    }

    /**
     * The payload from aggregated counts.
     *
     * @param array<string, array<string|int, int>> $flat FLAT key => trimmed non-blank value => rows
     * @param array<string, int> $geo "region\0division\0agence" (labelled) => rows
     *
     * @return array<string, mixed>
     */
    public static function build(array $flat, array $geo, ?string $minDate, ?string $maxDate): array
    {
        $out = [];
        foreach (array_keys(self::FLAT) as $key) {
            $out[$key] = self::pairs($flat[$key] ?? []);
        }

        ksort($geo, SORT_STRING);
        $tree      = [];
        $divisions = [];
        $agences   = [];
        foreach ($geo as $key => $n) {
            [$region, $division, $agence] = explode("\0", (string) $key);
            $tree[$region][$division][]   = ['value' => $agence, 'count' => $n];
            $divisions[$division]         = ($divisions[$division] ?? 0) + $n;
            $agences[$agence]             = ($agences[$agence] ?? 0) + $n;
        }

        $min = $minDate;
        if ($min !== null && $min < '1990-01-01') {
            $min = '1990-01-01';
        }

        return [
            'regions'        => $out['regions'],
            'divisions'      => self::pairs($divisions),
            'agences'        => self::pairs($agences),
            'statuses'       => $out['statuses'],
            'segmentations'  => $out['segmentations'],
            'segmentsTresor' => $out['segmentsTresor'],
            'meters'         => $out['meters'],
            'voltages'       => $out['voltages'],
            'niuQualities'   => $out['niuQualities'],
            'geoTree'        => $tree,
            'dateBounds'     => ['min' => $min, 'max' => $maxDate],
        ];
    }

    public static function label(string $value): string
    {
        $value = trim($value);

        return $value !== '' ? $value : self::EMPTY_LABEL;
    }

    /**
     * @param array<string|int, int> $counts
     *
     * @return list<array{value: string, count: int}>
     */
    private static function pairs(array $counts): array
    {
        $out = [];
        foreach ($counts as $value => $count) {
            $out[] = ['value' => (string) $value, 'count' => $count];
        }
        usort($out, static fn ($a, $b) => ($b['count'] <=> $a['count']) ?: strcmp($a['value'], $b['value']));

        return $out;
    }
}
