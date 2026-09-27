<?php

namespace App\Services\Snapshot;

/**
 * Builds, in the install's single validation pass, the same structure
 * DashboardService::filterOptions() computes from Oracle (value lists with
 * counts, region -> division -> agence tree, DATE_AB bounds) — so export
 * filter validation (AllowedValues::fromFilterOptions) needs no Oracle.
 *
 * Same rules as DashboardService: flat values trimmed, blanks not offered;
 * geo levels labelled "Non renseigné" when blank; lists by count desc; the
 * date-picker minimum floored at 1990-01-01.
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
        $out = [];
        foreach ($this->flat as $key => $counts) {
            $out[$key] = self::pairs($counts);
        }

        $tree      = [];
        $divisions = [];
        $agences   = [];
        foreach ($this->geo as $key => $n) {
            [$region, $division, $agence] = explode("\0", (string) $key);
            $tree[$region][$division][]   = ['value' => $agence, 'count' => $n];
            $divisions[$division]         = ($divisions[$division] ?? 0) + $n;
            $agences[$agence]             = ($agences[$agence] ?? 0) + $n;
        }

        $min = $this->minDate;
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
            'dateBounds'     => ['min' => $min, 'max' => $this->maxDate],
        ];
    }

    private static function label(string $value): string
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
        usort($out, static fn ($a, $b) => $b['count'] <=> $a['count']);

        return $out;
    }
}
