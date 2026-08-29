<?php

namespace App\Services;

use App\Models\CustomersListSnapshotModel;
use App\Models\ToolModel;
use RuntimeException;
use Throwable;

/**
 * Orchestrates the CUSTOMERS_LIST dashboard data lifecycle.
 *
 * refresh() is the ONLY place that talks to Oracle: it runs the stored
 * CUSTOMERS_LIST query (app/Models/Extractor.sql, loaded verbatim from
 * tools.query_definition — never rewritten here) exactly once, streams every
 * row through CustomersListAggregator, and persists the resulting compact
 * aggregate ("cube" of dimension-combination counts, plus totals and
 * dimension lists) as one snapshot row. Every other read — the dashboard on
 * a normal page load, and each filter change — reads that local snapshot
 * instead of touching Oracle again, which is what keeps "Actualiser"
 * distinct from "Customers List" (the latter still runs the row-level
 * preview extraction via ExtractionController, untouched by this class).
 */
class CustomersListStatsService
{
    private const TOOL_CODE = 'CUSTOMERS_LIST';

    private OracleExtractionService $oracle;
    private ToolModel $toolModel;
    private CustomersListSnapshotModel $snapshotModel;

    public function __construct(
        ?OracleExtractionService $oracle = null,
        ?ToolModel $toolModel = null,
        ?CustomersListSnapshotModel $snapshotModel = null
    ) {
        $this->oracle        = $oracle ?? new OracleExtractionService();
        $this->toolModel     = $toolModel ?? new ToolModel();
        $this->snapshotModel = $snapshotModel ?? new CustomersListSnapshotModel();
    }

    /**
     * Runs the CUSTOMERS_LIST extraction against Oracle, aggregates it in one
     * streaming pass, and persists the result as a new snapshot. Returns the
     * decoded snapshot row regardless of outcome (status is 'success' or
     * 'error' — never throws for an Oracle-side failure, so the caller can
     * always render a normal page with a flash message).
     *
     * @return array<string, mixed>
     */
    public function refresh(): array
    {
        $sql = $this->resolveQuery();

        $snapshotId = $this->snapshotModel->insert([
            'status'     => 'running',
            'started_at' => date('Y-m-d H:i:s'),
            'source'     => 'CMS_RFC',
        ], true);

        $startedAt = microtime(true);

        // The aggregator only keeps running counters + a set of distinct
        // client ids, but 500k+ rows still warrant headroom beyond the
        // default CLI/web memory_limit.
        $previousLimit = ini_set('memory_limit', '512M');

        try {
            $aggregator = new CustomersListAggregator();

            $this->oracle->stream($sql, static function (array $row) use ($aggregator): void {
                $aggregator->add($row);
            });

            $result = $aggregator->result();

            $this->snapshotModel->update($snapshotId, [
                'status'                => 'success',
                'finished_at'           => date('Y-m-d H:i:s'),
                'duration_seconds'      => (int) round(microtime(true) - $startedAt),
                'row_count'             => $result['row_count'],
                'distinct_client_count' => $result['distinct_client_count'],
                'totals'                => json_encode($result['totals']),
                'dimensions'            => json_encode($result['dimensions']),
                'cube'                  => json_encode($result['cube']),
            ]);
        } catch (Throwable $e) {
            $reference = bscd_error_reference('DASH');

            log_message('error', 'Echec actualisation dashboard CUSTOMERS_LIST [{ref}]: {message}', [
                'ref'     => $reference,
                'message' => $e->getMessage(),
            ]);

            $this->snapshotModel->update($snapshotId, [
                'status'           => 'error',
                'finished_at'      => date('Y-m-d H:i:s'),
                'duration_seconds' => (int) round(microtime(true) - $startedAt),
                'error_reference'  => $reference,
            ]);
        } finally {
            if ($previousLimit !== false) {
                ini_set('memory_limit', $previousLimit);
            }
        }

        return $this->decode($this->snapshotModel->find($snapshotId));
    }

    public function latest(): ?array
    {
        $row = $this->snapshotModel
            ->orderBy('created_at', 'DESC')
            ->first();

        return $row ? $this->decode($row) : null;
    }

    public function find(int $id): ?array
    {
        $row = $this->snapshotModel->find($id);

        return $row ? $this->decode($row) : null;
    }

    public function latestSuccessful(): ?array
    {
        $row = $this->snapshotModel->latestSuccessful();

        return $row ? $this->decode($row) : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function history(int $limit = 15): array
    {
        return array_map(
            fn (array $row) => $this->decode($row, includeCube: false),
            $this->snapshotModel->history($limit)
        );
    }

    /**
     * Re-aggregates a snapshot's stored cube against a subset of filters,
     * entirely in PHP over locally-stored data — no Oracle round trip.
     *
     * @param array<string, mixed>       $snapshot Decoded snapshot (must include 'cube').
     * @param array<string, string|null> $filters  Keys among region/division/agence/meter/status/segmentation/segment_tresor.
     */
    public function filteredStats(array $snapshot, array $filters): array
    {
        $cube = $snapshot['cube'] ?? [];

        $active = array_filter($filters, static fn ($v) => $v !== null && $v !== '');

        $matching = $cube === [] ? [] : array_values(array_filter($cube, static function (array $entry) use ($active) {
            foreach ($active as $dim => $value) {
                if (($entry[$dim] ?? null) !== $value) {
                    return false;
                }
            }

            return true;
        }));

        $dims = ['region', 'division', 'agence', 'meter', 'status', 'segmentation', 'segment_tresor', 'niu_qc'];
        $breakdown = array_fill_keys($dims, []);
        $rowCount  = 0;

        foreach ($matching as $entry) {
            $rowCount += $entry['count'];

            foreach ($dims as $dim) {
                $value = $entry[$dim];
                $breakdown[$dim][$value] = ($breakdown[$dim][$value] ?? 0) + $entry['count'];
            }
        }

        $dimensions = [];
        foreach ($breakdown as $dim => $counts) {
            arsort($counts);
            $dimensions[$dim] = array_map(
                static fn ($value, $count) => ['value' => $value, 'count' => $count],
                array_keys($counts),
                array_values($counts)
            );
        }

        return [
            'row_count'  => $rowCount,
            'dimensions' => $dimensions,
        ];
    }

    private function resolveQuery(): string
    {
        $tool = $this->toolModel
            ->where('code', self::TOOL_CODE)
            ->where('is_active', 1)
            ->first();

        if (! $tool || empty($tool['query_definition'])) {
            throw new RuntimeException('CUSTOMERS_LIST query is not configured.');
        }

        return $tool['query_definition'];
    }

    private function decode(?array $row, bool $includeCube = true): ?array
    {
        if (! $row) {
            return null;
        }

        $row['totals']     = $row['totals'] ? json_decode($row['totals'], true) : null;
        $row['dimensions'] = $row['dimensions'] ? json_decode($row['dimensions'], true) : null;

        if ($includeCube) {
            $row['cube'] = $row['cube'] ? json_decode($row['cube'], true) : [];
        } else {
            unset($row['cube']);
        }

        return $row;
    }
}
