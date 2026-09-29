<?php

namespace App\Controllers;

use App\Models\ExportJobModel;
use App\Services\CustomerListExportService;
use App\Services\CustomersList\DashboardService;
use App\Services\CustomersList\FilterCriteria;
use App\Services\CustomersList\InvalidFilterException;
use App\Services\CustomersList\QueryBuilder;
use App\Services\Snapshot\SnapshotRowSource;
use App\Services\Snapshot\SnapshotUnavailableException;
use Config\Oracle as OracleConfig;
use Config\Snapshot as SnapshotConfig;
use Throwable;

/**
 * The CUSTOMERS_LIST analytics dashboard.
 *
 * Sources (Config\Snapshot::$exportSource = 'snapshot', the default):
 *   - the ACTIVE SNAPSHOT — the very file the exports read — for the filter
 *     options (and the validation of every filter), the row count shown as
 *     "Total" / "Résultats" (GET /dashboard/count, the export's own
 *     SnapshotRowSource::count(), same RowMatcher, same cache) and the
 *     exports themselves: what the user counts is what they export;
 *   - live CMS_RFC.TB_CUSTOMERS_LIST for the analytics that need SQL
 *     aggregation or paging (KPI ratios, charts, table page) — the page
 *     flags when that live base holds a different number of rows.
 * With exportSource = 'oracle' (rollback) everything is live Oracle again.
 *
 *   GET  /dashboard                  the page shell (+ filter options island)
 *   GET  /dashboard/stats            KPIs + chart datasets for a filter set
 *   GET  /dashboard/count            rows matching the filters in the snapshot (+ its metadata)
 *   GET  /dashboard/rows             one server-side page of the data table
 *   GET  /dashboard/filter-options   region→division→agence tree + value lists
 *   POST /dashboard/export           run (small) or queue (large) a filtered export
 *   GET  /dashboard/export/download  one-shot signed download of a sync export
 *
 * Every AJAX endpoint validates the incoming filters against the real
 * column values of the source the filter options came from (the snapshot's,
 * see criteria()) before touching any data; an unknown value is a 422, a
 * data failure a sanitised 503.
 */
class DashboardController extends BaseController
{
    private DashboardService $dashboard;
    private OracleConfig $oracleConfig;

    /** Memo of snapshotSource(): false = not resolved yet. */
    private SnapshotRowSource|false|null $snapshotSource = false;

    public function __construct()
    {
        $this->dashboard    = new DashboardService();
        $this->oracleConfig = new OracleConfig();
    }

    public function index()
    {
        // The page shell never touches Oracle — it renders instantly and the
        // browser then fetches filter-options / stats / rows in parallel
        // (each behind a skeleton). Keeps first paint fast even when a cold
        // aggregate scan takes a few seconds.
        session()->close();

        return view('dashboard/index', [
            'title'     => 'Customer Data Analytics',
            'bootstrap' => [
                'endpoints' => [
                    'stats'         => site_url('dashboard/stats'),
                    'count'         => site_url('dashboard/count'),
                    'rows'          => site_url('dashboard/rows'),
                    'filterOptions' => site_url('dashboard/filter-options'),
                    'export'        => site_url('dashboard/export'),
                    'jobStatus'     => site_url('exports'), // + /{id}
                ],
                'sortable'          => QueryBuilder::SORTABLE,
                'tableColumns'      => QueryBuilder::ALL_COLUMNS,
                'defaultVisibleColumns' => QueryBuilder::DEFAULT_VISIBLE_COLUMNS,
                'perPageOptions'    => [20, 50, 100, 200],
                'exportSyncMaxRows' => $this->oracleConfig->exportSyncMaxRows,
            ],
        ]);
    }

    public function stats()
    {
        return $this->guarded(function () {
            $criteria = $this->criteria();
            $fresh    = $this->request->getGet('fresh') === '1';

            return $this->response->setJSON($this->dashboard->stats($criteria, $fresh));
        });
    }

    /**
     * Rows matching the filters in the active snapshot — the export's own
     * count (SnapshotRowSource::count(): same RowMatcher, same per-version
     * cache as POST /dashboard/export, so launching the export afterwards
     * reuses it). No filter = the row count validated at install (instant);
     * filters = one streaming pass, cached per (version, filters).
     *
     * Releases the session lock first: a new filter set can take seconds
     * and must not hold up /stats and /rows, requested alongside it.
     */
    public function count()
    {
        session()->close();

        return $this->guarded(function () {
            if (! (new SnapshotConfig())->usesSnapshot()) {
                return $this->response->setJSON([
                    'count'    => $this->dashboard->count($this->criteria()),
                    'source'   => 'oracle',
                    'snapshot' => null,
                ]);
            }

            try {
                $source = $this->snapshotSource();
            } catch (SnapshotUnavailableException $e) {
                return $this->snapshotUnavailable($e);
            }

            $criteria = FilterCriteria::fromRequest($this->request->getGet(), $source->allowedValues());

            return $this->response->setJSON([
                'count'    => $source->count($criteria),
                'source'   => 'snapshot',
                'snapshot' => $this->snapshotInfo($source),
            ]);
        });
    }

    public function rows()
    {
        return $this->guarded(function () {
            $criteria = $this->criteria();
            $get      = $this->request->getGet();

            $result = $this->dashboard->rows(
                $criteria,
                (int) ($get['page'] ?? 1),
                (int) ($get['per_page'] ?? 50),
                $get['sort'] ?? null,
                (string) ($get['dir'] ?? 'asc'),
                trim((string) ($get['search'] ?? '')),
                ($get['fresh'] ?? null) === '1',
            );

            return $this->response->setJSON($result);
        });
    }

    public function filterOptions()
    {
        return $this->guarded(function () {
            // Snapshot mode: the values (with counts) computed when the active
            // snapshot was installed — same shape as the Oracle query, instant,
            // and exactly the values an export accepts.
            $source = $this->snapshotSourceOrNull();
            if ($source !== null) {
                return $this->response->setJSON($source->snapshot()->filterOptions());
            }

            $fresh = $this->request->getGet('fresh') === '1';

            return $this->response->setJSON($this->dashboard->filterOptions($fresh));
        });
    }

    /**
     * Decide sync vs async and either generate the file now or queue a job.
     *
     * The row count that drives that decision is ALWAYS recomputed here by
     * DashboardService::count() from the posted filters — the browser's
     * displayed figure is never trusted. Small exports are streamed straight
     * back via a short-lived signed URL and create NO export_jobs row; only a
     * genuinely large export becomes a queued job.
     */
    public function export()
    {
        return $this->guarded(function () {
            $format = strtolower((string) $this->request->getPost('format'));
            if (! in_array($format, ['csv', 'xlsx'], true)) {
                return $this->response->setStatusCode(422)->setJSON(['error' => 'format', 'message' => 'Format invalide.']);
            }

            // Export source (Config\Snapshot::$exportSource). With the
            // snapshot, validation, count and rows all come from ONE pinned
            // local version — this endpoint then never touches Oracle, and
            // never falls back to it when no snapshot is available.
            $source = null;
            if ((new SnapshotConfig())->usesSnapshot()) {
                try {
                    $source = new SnapshotRowSource();
                } catch (SnapshotUnavailableException $e) {
                    return $this->snapshotUnavailable($e);
                }
            }

            $criteria = FilterCriteria::fromRequest(
                (array) $this->request->getPost(),
                $source?->allowedValues() ?? $this->dashboard->allowedValues(),
            );

            // Source of truth: the backend's own count over the export
            // filters — the browser's displayed figure is never trusted.
            $count    = $source?->count($criteria) ?? $this->dashboard->count($criteria);
            $decision = $this->dashboard->exportDecision($count);

            if ($decision === 'empty') {
                return $this->response->setJSON(['mode' => 'empty', 'count' => 0]);
            }

            if ($decision === 'sync') {
                $service = new CustomerListExportService(rowSource: $source);

                try {
                    $meta = $format === 'xlsx' ? $service->exportXlsx($criteria) : $service->exportCsv($criteria);
                } catch (Throwable $e) {
                    $ref = bscd_error_reference('EXP');
                    log_message('error', 'Echec export synchrone [{ref}] format={format}: {message}', ['ref' => $ref, 'format' => $format, 'message' => $e->getMessage()]);

                    return $this->response->setStatusCode(500)->setJSON(['error' => 'export', 'reference' => $ref]);
                }

                return $this->response->setJSON([
                    'mode'        => 'sync',
                    'count'       => $count,
                    'rows'        => (int) $meta['rows'],
                    'downloadUrl' => site_url('dashboard/export/download') . '?' . http_build_query([
                        'f'   => $meta['filename'],
                        'sig' => $this->signDownload($meta['filename']),
                    ]),
                ]);
            }

            // Large export -> queued job.
            $jobs  = new ExportJobModel();
            $jobId = $jobs->insert([
                'requested_by'  => (string) (session('username') ?? 'inconnu'),
                'format'        => $format,
                'filters'       => json_encode($criteria->toArray()),
                'filters_label' => $this->labelFor($criteria),
                'status'        => 'pending',
                'row_count'     => $count,
            ], true);

            return $this->response->setJSON([
                'mode'      => 'async',
                'count'     => $count,
                'jobId'     => (int) $jobId,
                'statusUrl' => site_url("exports/{$jobId}"),
            ]);
        });
    }

    /**
     * One-shot download of a just-generated synchronous export. Stateless:
     * the link carries the file name plus an HMAC signature, so nothing is
     * stored server-side and the URL cannot be guessed or tampered with. The
     * file is deleted right after it has been sent.
     */
    public function downloadSync()
    {
        $name = (string) $this->request->getGet('f');
        $sig  = (string) $this->request->getGet('sig');

        if ($name === '' || ! hash_equals($this->signDownload($name), $sig)) {
            return $this->response->setStatusCode(403)->setJSON(['error' => 'forbidden']);
        }

        if (preg_match('/^customer_list_[A-Za-z0-9_]+\.(csv|xlsx)$/', $name) !== 1) {
            return $this->response->setStatusCode(404)->setJSON(['error' => 'not_found']);
        }

        $path = rtrim(WRITEPATH . 'uploads/exports', '/\\') . DIRECTORY_SEPARATOR . $name;

        if (! is_file($path)) {
            return $this->response->setStatusCode(410)->setJSON([
                'error'   => 'expired',
                'message' => "Le fichier n'est plus disponible. Relancez l'export.",
            ]);
        }

        register_shutdown_function(static function () use ($path): void {
            if (is_file($path)) {
                @unlink($path);
            }
        });

        return $this->response->download($path, null)->setFileName($name);
    }

    /**
     * No valid snapshot: a controlled, logged 503 — deliberately no Oracle
     * extraction instead. The message is shown as-is by dashboard.js.
     */
    private function snapshotUnavailable(SnapshotUnavailableException $e)
    {
        $ref = bscd_error_reference('SNAP');
        log_message('error', '[SNAPSHOT] export refusé [{ref}] : aucun snapshot valide ({message})', ['ref' => $ref, 'message' => $e->getMessage()]);

        return $this->response->setStatusCode(503)->setJSON([
            'error'   => 'snapshot_unavailable',
            'message' => "Les données de référence ne sont pas disponibles actuellement (réf. {$ref}). Réessayez plus tard ou contactez l'administrateur",
        ]);
    }

    private function signDownload(string $filename): string
    {
        $key = (string) (config(\Config\Encryption::class)->key ?: 'bscd-export-fallback-secret');

        return hash_hmac('sha256', 'export|' . $filename, $key);
    }

    // ---- helpers -----------------------------------------------------

    /**
     * The request's filters, validated against the values of the source the
     * filter options came from (the active snapshot in snapshot mode).
     */
    private function criteria(): FilterCriteria
    {
        $source = $this->snapshotSourceOrNull();

        return FilterCriteria::fromRequest(
            $this->request->getGet(),
            $source?->allowedValues() ?? $this->dashboard->allowedValues(),
        );
    }

    /**
     * The active snapshot's row source in snapshot mode, null in Oracle mode.
     * Resolved once per request, so every figure of a request describes the
     * same version.
     *
     * @throws SnapshotUnavailableException in snapshot mode with no valid snapshot.
     */
    private function snapshotSource(): ?SnapshotRowSource
    {
        if ($this->snapshotSource === false) {
            $this->snapshotSource = null;
            if ((new SnapshotConfig())->usesSnapshot()) {
                $this->snapshotSource = new SnapshotRowSource();
            }
        }

        return $this->snapshotSource;
    }

    /**
     * snapshotSource(), or null when no valid snapshot is available: the
     * read-only screens (options, stats, table) then fall back to live
     * Oracle, while /count and exports refuse (503) — no count or export
     * silently switches base.
     */
    private function snapshotSourceOrNull(): ?SnapshotRowSource
    {
        try {
            return $this->snapshotSource();
        } catch (SnapshotUnavailableException $e) {
            log_message('warning', '[SNAPSHOT] aucun snapshot valide, filtres du dashboard lus sur Oracle ({message})', ['message' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * What the page may say about the snapshot in use — only metadata really
     * present in its meta file, never a guessed date.
     *
     * @return array{id: string, rows: int, generatedAt: string|null, sourceUpdatedAt: string|null}
     */
    private function snapshotInfo(SnapshotRowSource $source): array
    {
        $snapshot = $source->snapshot();
        $meta     = $snapshot->meta;
        $text     = static fn ($v): ?string => is_string($v) && trim($v) !== '' ? trim($v) : null;

        return [
            'id'              => $snapshot->id,
            'rows'            => $snapshot->rows(),
            // When the snapshot file was extracted.
            'generatedAt'     => $text($meta['generated_at'] ?? $meta['manifest']['generated_at'] ?? null),
            // Last reload of the Oracle table it was extracted from (its UPDATED_AT), when known.
            'sourceUpdatedAt' => $text($meta['manifest']['source_updated_at'] ?? null),
        ];
    }

    /**
     * Wraps an endpoint body with the shared error handling: filter problems
     * become 422, Oracle/anything-else a sanitised 503, nothing leaks.
     */
    private function guarded(callable $body)
    {
        try {
            return $body();
        } catch (InvalidFilterException $e) {
            return $this->response->setStatusCode(422)->setJSON(['error' => 'filter', 'message' => $e->getMessage()]);
        } catch (Throwable $e) {
            $reference = bscd_error_reference('DASH');
            log_message('error', 'Dashboard endpoint KO [{ref}] {uri}: {message}', [
                'ref'     => $reference,
                'uri'     => (string) $this->request->getUri(),
                'message' => $e->getMessage(),
            ]);

            return $this->response->setStatusCode(503)->setJSON(['error' => 'oracle', 'reference' => $reference]);
        }
    }

    private function labelFor(FilterCriteria $criteria): string
    {
        $described = $criteria->describe();
        if ($described === []) {
            return 'Aucun filtre (tout le référentiel)';
        }

        $parts = [];
        foreach ($described as $label => $value) {
            $parts[] = "{$label} : {$value}";
        }

        return mb_substr(implode(' · ', $parts), 0, 500);
    }
}
