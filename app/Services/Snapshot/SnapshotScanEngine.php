<?php

namespace App\Services\Snapshot;

use App\Services\CustomersList\FilterCriteria;
use App\Services\CustomersList\QueryBuilder;

/**
 * SnapshotQueryEngine by plain streaming over the version's CSV (fgets +
 * RowMatcher, the export's own matcher): one pass per call, constant memory
 * except page(), which keeps the matching rows to sort them.
 *
 * The SEMANTIC REFERENCE of the dashboard: the production engine
 * (SnapshotDuckDbEngine) must give exactly the same answers on the same
 * file — the parity tests compare both. Too slow for the live dashboard on
 * 3.3 M rows (one full pass ≈ 8–12 s per call, measured 2026-10-02), so it
 * is used by tests and small files only.
 */
final class SnapshotScanEngine implements SnapshotQueryEngine
{
    public function __construct(private readonly ActiveSnapshot $snapshot)
    {
    }

    public function version(): string
    {
        return $this->snapshot->id;
    }

    public function snapshot(): ActiveSnapshot
    {
        return $this->snapshot;
    }

    public function count(FilterCriteria $criteria, string $search = ''): int
    {
        $n = 0;
        $this->scan($criteria, $search, static function () use (&$n): void {
            $n++;
        });

        return $n;
    }

    public function kpis(FilterCriteria $criteria, array $activeStatuses, array $meteredMeters): array
    {
        $active  = array_fill_keys($activeStatuses, true);
        $metered = array_fill_keys($meteredMeters, true);
        $k       = ['total' => [], 'actifs' => [], 'inactifs' => [], 'avecCompteur' => [], 'nuiCorrect' => [], 'contactOk' => 0];

        $this->scan($criteria, '', static function (array $r) use ($active, $metered, &$k): void {
            $contract = $r['CONTRACT'];
            $k['total'][$contract] = true;
            if (isset($active[$r['STATUS']])) {
                $k['actifs'][$contract] = true;
            } else {
                $k['inactifs'][$contract] = true;
            }
            if (isset($metered[$r['METER']])) {
                $k['avecCompteur'][$contract] = true;
            }
            if (stripos($r['NUI_QC'], 'CORRECT') !== false) {
                $k['nuiCorrect'][$contract] = true;
            }
            if (trim($r['PHONE_NUMBERS']) !== '' || trim($r['E_MAIL']) !== '') {
                $k['contactOk']++;
            }
        });

        return [
            'total'        => count($k['total']),
            'actifs'       => count($k['actifs']),
            'inactifs'     => count($k['inactifs']),
            'avecCompteur' => count($k['avecCompteur']),
            'nuiCorrect'   => count($k['nuiCorrect']),
            'contactOk'    => $k['contactOk'],
        ];
    }

    public function summary(FilterCriteria $criteria, array $activeStatuses, array $meteredMeters): array
    {
        return ['kpis' => $this->kpis($criteria, $activeStatuses, $meteredMeters), 'distributions' => $this->distributions($criteria)];
    }

    public function reference(): array
    {
        $all = [];
        $nui = [];
        $this->scan(FilterCriteria::none(), '', static function (array $r) use (&$all, &$nui): void {
            $all[$r['CONTRACT']] = true;
            if (trim($r['NUI_QC']) !== '') {
                $nui[$r['CONTRACT']] = true;
            }
        });

        return ['totalClients' => count($all), 'totalNui' => count($nui)];
    }

    public function distributions(FilterCriteria $criteria): array
    {
        $d = ['region' => [], 'status' => [], 'segmentation' => [], 'meterType' => []];
        $this->scan($criteria, '', static function (array $r) use (&$d): void {
            foreach (['region' => 'REGION', 'status' => 'STATUS', 'segmentation' => 'SEGMENTATION', 'meterType' => 'METER'] as $key => $column) {
                $d[$key][$r[$column]] = ($d[$key][$r[$column]] ?? 0) + 1;
            }
        });

        return $d;
    }

    public function segmentationCounts(FilterCriteria $criteria): array
    {
        return $this->distributions($criteria)['segmentation'];
    }

    public function page(FilterCriteria $criteria, string $search, ?string $sort, string $dir, int $offset, int $limit): array
    {
        $sort = in_array($sort, QueryBuilder::SORTABLE, true) ? $sort : 'CONTRACT';
        $rows = [];
        $this->scan($criteria, $search, static function (array $r) use (&$rows): void {
            $rows[] = $r;
        });

        $desc = strtolower($dir) === 'desc';
        usort($rows, static function (array $a, array $b) use ($sort, $desc): int {
            $c = SnapshotSort::compare($sort, $a[$sort], $b[$sort], $desc);

            return $c !== 0 ? $c : SnapshotSort::compare('CONTRACT', $a['CONTRACT'], $b['CONTRACT'], false);
        });

        return array_map(
            static fn (array $r): array => SnapshotSort::tableRow($r),
            array_slice($rows, max(0, $offset), max(0, $limit)),
        );
    }

    /**
     * Calls $onRow with every matching record as column => value, the 9
     * filter columns trimmed (as RowMatcher compares them) and the date
     * columns as 'YYYY-MM-DD' (null when blank or unreadable).
     */
    private function scan(FilterCriteria $criteria, string $search, callable $onRow): void
    {
        $reader  = $this->snapshot->reader();
        $index   = $reader->index();
        $matcher = new RowMatcher($criteria, $index, $this->snapshot->dateFormat());
        $date    = $this->snapshot->dateFormat() !== null ? new SnapshotDate($this->snapshot->dateFormat()) : null;
        $needle  = SnapshotSort::searchNeedle($search);

        $reader->each(static function (array $fields) use ($matcher, $index, $date, $needle, $onRow): void {
            if (! $matcher->matches($fields)) {
                return;
            }
            $r = SnapshotSort::record($fields, $index, $date);
            if ($needle !== null && ! SnapshotSort::matchesSearch($r, $needle)) {
                return;
            }
            $onRow($r);
        });
    }
}
