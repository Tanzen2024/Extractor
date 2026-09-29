<?php

namespace App\Services\Snapshot;

use App\Services\CustomerListExportService;
use App\Services\CustomersList\AllowedValues;
use App\Services\CustomersList\FilterCriteria;
use App\Services\Export\ReportsProgress;
use App\Services\Export\ReportsStreamStats;
use App\Services\Export\RowSource;
use CodeIgniter\Cache\CacheInterface;
use Config\Services;
use Config\Snapshot as SnapshotConfig;

/**
 * Export rows read from the local snapshot — no Oracle involved.
 *
 * Pinned to ONE version for its whole life: the version is resolved when
 * the object is created, so the allowed values, the count and the export of
 * a request all describe the same file, and a snapshot activated meanwhile
 * never changes an export already under way.
 *
 * Output rows carry exactly CustomerListExportService::COLUMNS (the 25
 * export columns, same order as before); the snapshot's other columns are
 * read but not exported. Values are passed through as found in the file.
 */
final class SnapshotRowSource implements RowSource, ReportsStreamStats, ReportsProgress
{
    private ActiveSnapshot $snapshot;
    private SnapshotConfig $config;

    /** @var array{rows_read: int, rows_matched: int, read_s: float, filter_s: float} */
    private array $lastStats = ['rows_read' => 0, 'rows_matched' => 0, 'read_s' => 0.0, 'filter_s' => 0.0];

    /** @var (callable(int, int, int): void)|null See ReportsProgress. */
    private $progress = null;

    /**
     * @param int $maxRows Stop after this many matching rows (0 = no cap).
     *                     Benchmark smoke tests only.
     *
     * @throws SnapshotUnavailableException when no valid snapshot is active.
     */
    public function __construct(
        ?ActiveSnapshot $snapshot = null,
        ?SnapshotStore $store = null,
        private readonly int $maxRows = 0,
        private readonly ?CacheInterface $cache = null,
    ) {
        $store          = $store ?? new SnapshotStore();
        $this->config   = $store->config();
        $this->snapshot = $snapshot ?? $store->active();
    }

    public function snapshot(): ActiveSnapshot
    {
        return $this->snapshot;
    }

    public function label(): string
    {
        return 'snapshot:' . $this->snapshot->id;
    }

    /** Filter values present in this snapshot — the export's trust boundary. */
    public function allowedValues(): AllowedValues
    {
        return AllowedValues::fromFilterOptions($this->snapshot->filterOptions());
    }

    public function setProgressListener(?callable $listener): void
    {
        $this->progress = $listener;
    }

    public function stream(FilterCriteria $criteria, callable $onRow): int
    {
        $reader  = $this->snapshot->reader();
        $index   = $reader->index();
        $matcher = new RowMatcher($criteria, $index, $this->snapshot->dateFormat());
        $all     = $matcher->isUnfiltered();

        $columns   = CustomerListExportService::COLUMNS;
        $positions = [];
        foreach ($columns as $column) {
            $positions[$column] = $index[$column];
        }

        // When the snapshot holds exactly the export columns, in export
        // order (the customers:refresh file), a record already IS the row:
        // one array_combine() instead of 25 keyed assignments per row —
        // same keys, same order, same values (measured 2026-09-29: -10% CPU
        // per row on its own, -37% with CsvRowWriter's matching fast path).
        // Any other layout (e.g. the 27-column SFTP file) keeps the
        // by-name picking. each() guarantees every record has
        // count($index) fields, so array_combine() always gets equal sizes.
        $combine = count($index) === count($columns) && array_values($positions) === array_keys($columns);

        $max      = $this->maxRows;
        $progress = $this->progress;
        $total    = $this->snapshot->rows();
        $every    = self::PROGRESS_EVERY_ROWS;
        $emitted  = 0;
        $read     = 0;
        $filterNs = 0;
        $emitNs   = 0;
        $t0       = hrtime(true);

        try {
            if ($progress !== null) {
                $progress(0, $total, 0);
            }

            $reader->each(static function (array $fields) use ($matcher, $all, $positions, $columns, $combine, $onRow, $max, $progress, $total, $every, &$emitted, &$read, &$filterNs, &$emitNs): bool {
                // Progress follows the scan, not the matches: a selective
                // filter still moves the bar. One modulo per row, the
                // listener itself only every $every rows.
                if ($progress !== null && $read % $every === 0 && $read > 0) {
                    $progress($read, $total, $emitted);
                }

                $read++;

                if (! $all) {
                    $tf      = hrtime(true);
                    $matches = $matcher->matches($fields);
                    $filterNs += hrtime(true) - $tf;
                    if (! $matches) {
                        return true;
                    }
                }

                $te = hrtime(true);
                if ($combine) {
                    $row = array_combine($columns, $fields);
                } else {
                    $row = [];
                    foreach ($positions as $column => $i) {
                        $row[$column] = $fields[$i];
                    }
                }
                $onRow($row);
                $emitNs += hrtime(true) - $te;
                $emitted++;

                return $max === 0 || $emitted < $max;
            });

            if ($progress !== null) {
                $progress($read, $total, $emitted);
            }
        } finally {
            $reader->close();
            $this->lastStats = [
                'rows_read'    => $read,
                'rows_matched' => $emitted,
                'read_s'       => max(0, hrtime(true) - $t0 - $filterNs - $emitNs) / 1e9,
                'filter_s'     => $filterNs / 1e9,
            ];
        }

        return $emitted;
    }

    public function lastStreamStats(): array
    {
        return $this->lastStats;
    }

    /**
     * Rows matching $criteria in this snapshot. Unfiltered = the row count
     * validated at install (instant); otherwise one streaming pass, cached
     * per (version, filters) since a version never changes.
     */
    public function count(FilterCriteria $criteria): int
    {
        if ($criteria->isEmpty()) {
            return $this->snapshot->rows();
        }

        $cache = $this->cache ?? Services::cache();
        $key   = 'snapcount_' . $this->snapshot->id . '_' . $criteria->cacheKey();

        $cached = $cache->get($key);
        if (is_int($cached)) {
            return $cached;
        }

        $reader  = $this->snapshot->reader();
        $matcher = new RowMatcher($criteria, $reader->index(), $this->snapshot->dateFormat());
        $n       = 0;

        try {
            $reader->each(static function (array $fields) use ($matcher, &$n): void {
                if ($matcher->matches($fields)) {
                    $n++;
                }
            });
        } finally {
            $reader->close();
        }

        $cache->save($key, $n, $this->config->countCacheTtl);

        return $n;
    }
}
