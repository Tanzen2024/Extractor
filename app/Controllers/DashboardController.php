<?php

namespace App\Controllers;

use App\Models\ExportJobModel;
use App\Services\CustomerListExportService;
use App\Services\CustomersList\DashboardService;
use App\Services\CustomersList\FilterCriteria;
use App\Services\CustomersList\InvalidFilterException;
use App\Services\CustomersList\PrepaidSegmentations;
use App\Services\CustomersList\QueryBuilder;
use App\Services\Snapshot\SnapshotRowSource;
use App\Services\Snapshot\SnapshotUnavailableException;
use Config\Oracle as OracleConfig;
use Throwable;

/**
 * The CUSTOMERS_LIST analytics dashboard — every figure from the ACTIVE
 * SNAPSHOT, the very version the exports read:
 *
 *   filter options + filter validation, KPIs, charts, segmentation counts,
 *   table (search / sort / pages), count, CSV / XLSX exports.
 *
 * Each request resolves ONE version (DashboardService::engine(), its DuckDB
 * index) and uses it for everything it answers; an export job records that
 * version (export_jobs.snapshot_version) and is generated from it, even
 * after a newer version is activated. Oracle is never read here: no valid
 * snapshot (or its index) -> a controlled 503, never a fallback.
 *
 *   GET  /dashboard                  the page shell (no data access)
 *   GET  /dashboard/stats            KPIs + chart datasets for a filter set
 *   GET  /dashboard/count            rows matching the filters (+ snapshot metadata)
 *   GET  /dashboard/rows             one server-side page of the data table
 *   GET  /dashboard/segmentation-counts  rows per segmentation for the other filters
 *   GET  /dashboard/filter-options   region→division→agence tree + value lists
 *   POST /dashboard/export           run (small) or queue (large) a filtered export
 *   GET  /dashboard/export/download  one-shot signed download of a sync export
 *
 * Every AJAX endpoint validates the incoming filters against the values of
 * the version it reads; an unknown value is a 422, a data failure a
 * sanitised 503.
 */
class DashboardController extends BaseController
{
    private DashboardService $dashboard;
    private OracleConfig $oracleConfig;

    public function __construct()
    {
        // Engine resolved lazily, once per request (one version per request).
        $this->dashboard    = new DashboardService();
        $this->oracleConfig = new OracleConfig();
    }

    public function index()
    {
        // The page shell reads no data — it renders instantly and the browser
        // then fetches filter-options / stats / rows in parallel.
        session()->close();

        return view('dashboard/index', [
            'title'     => 'Customer Data Analytics',
            'bootstrap' => [
                'endpoints' => [
                    'stats'         => site_url('dashboard/stats'),
                    'count'         => site_url('dashboard/count'),
                    'rows'          => site_url('dashboard/rows'),
                    'segmentationCounts' => site_url('dashboard/segmentation-counts'),
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
        session()->close();

        return $this->guarded(function () {
            $fresh = $this->request->getGet('fresh') === '1';

            return $this->response->setJSON($this->dashboard->stats($this->criteria(), $fresh));
        });
    }

    /**
     * Rows matching the filters in the active snapshot — the count the
     * export decision uses too (POST /dashboard/export), same engine, same
     * cache. Session lock released first: it runs alongside /stats and /rows.
     */
    public function count()
    {
        session()->close();

        return $this->guarded(function () {
            return $this->response->setJSON([
                'count'    => $this->dashboard->count($this->criteria()),
                'source'   => 'snapshot',
                'snapshot' => $this->snapshotInfo(),
            ]);
        });
    }

    /**
     * GET /dashboard/segmentation-counts — the numbers next to each option of
     * the Segmentation filter, for the filters currently in the form (the
     * segmentation filter itself is ignored).
     */
    public function segmentationCounts()
    {
        session()->close();

        return $this->guarded(function () {
            return $this->response->setJSON([
                'counts' => $this->dashboard->segmentationCounts($this->criteria()),
            ]);
        });
    }

    public function rows()
    {
        session()->close();

        return $this->guarded(function () {
            $criteria = $this->criteria();
            $get      = $this->request->getGet();

            return $this->response->setJSON($this->dashboard->rows(
                $criteria,
                (int) ($get['page'] ?? 1),
                (int) ($get['per_page'] ?? 50),
                $get['sort'] ?? null,
                (string) ($get['dir'] ?? 'asc'),
                trim((string) ($get['search'] ?? '')),
                ($get['fresh'] ?? null) === '1',
            ));
        });
    }

    public function filterOptions()
    {
        session()->close();

        // The values (with counts) computed when the active version was
        // installed — instant, and exactly the values a filter may take. The
        // 8 PREPAID categories are always offered (absent ones at 0).
        return $this->guarded(fn () => $this->response->setJSON(PrepaidSegmentations::completeOptions($this->dashboard->filterOptions())));
    }

    /**
     * Decide sync vs async and either generate the file now or queue a job —
     * all on the version this request resolved: the filters are validated
     * against its values, counted on it, the sync file is read from it and a
     * queued job records it (snapshot_version) so the worker reads it too.
     * The count is ALWAYS recomputed here — the browser's figure is never
     * trusted. Small exports are streamed back via a short-lived signed URL
     * and create NO export_jobs row.
     */
    public function export()
    {
        return $this->guarded(function () {
            $format = strtolower((string) $this->request->getPost('format'));
            if (! in_array($format, ['csv', 'xlsx'], true)) {
                return $this->response->setStatusCode(422)->setJSON(['error' => 'format', 'message' => 'Format invalide.']);
            }

            $criteria = FilterCriteria::fromRequest((array) $this->request->getPost(), $this->dashboard->allowedValues());
            $snapshot = $this->dashboard->snapshot();
            $count    = $this->dashboard->count($criteria);
            $decision = $this->dashboard->exportDecision($count);

            if ($decision === 'empty') {
                return $this->response->setJSON(['mode' => 'empty', 'count' => 0]);
            }

            if ($decision === 'sync') {
                $service = new CustomerListExportService(rowSource: new SnapshotRowSource(snapshot: $snapshot));

                try {
                    $meta = $format === 'xlsx' ? $service->exportXlsx($criteria) : $service->exportCsv($criteria);
                } catch (Throwable $e) {
                    $ref = bscd_error_reference('EXP');
                    log_message('error', 'Echec export synchrone [{ref}] format={format} snapshot={version}: {message}', ['ref' => $ref, 'format' => $format, 'version' => $snapshot->id, 'message' => $e->getMessage()]);

                    return $this->response->setStatusCode(500)->setJSON(['error' => 'export', 'reference' => $ref]);
                }

                return $this->response->setJSON([
                    'mode'              => 'sync',
                    'count'             => $count,
                    'rows'              => (int) $meta['rows'],
                    // Shown by the export window like an async job's fileSize / timing.
                    'fileSize'          => (int) $meta['fileSize'],
                    'generationSeconds' => (int) round($meta['totalDurationMs'] / 1000),
                    'downloadUrl'       => site_url('dashboard/export/download') . '?' . http_build_query([
                        'f'   => $meta['filename'],
                        'sig' => $this->signDownload($meta['filename']),
                    ]),
                ]);
            }

            // Large export -> queued job, pinned to this version.
            $jobs  = new ExportJobModel();
            $jobId = $jobs->insert([
                'requested_by'     => (string) (session('username') ?? 'inconnu'),
                'format'           => $format,
                'filters'          => json_encode($criteria->toArray()),
                'filters_label'    => $this->labelFor($criteria),
                'snapshot_version' => $snapshot->id,
                'status'           => 'pending',
                'row_count'        => $count,
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
     * No valid snapshot (or no index for it): a controlled, logged 503 —
     * deliberately no Oracle access instead. The message is shown as-is.
     */
    private function snapshotUnavailable(SnapshotUnavailableException $e)
    {
        $ref = bscd_error_reference('SNAP');
        log_message('error', '[SNAPSHOT] requête refusée [{ref}] {uri} : aucun snapshot exploitable ({message})', [
            'ref' => $ref, 'uri' => (string) $this->request->getUri(), 'message' => $e->getMessage(),
        ]);

        return $this->response->setStatusCode(503)->setJSON([
            'error'     => 'snapshot_unavailable',
            'reference' => $ref,
            'message'   => "Les données de référence ne sont pas disponibles actuellement (réf. {$ref}). Réessayez plus tard ou contactez l'administrateur",
        ]);
    }

    private function signDownload(string $filename): string
    {
        $key = (string) (config(\Config\Encryption::class)->key ?: 'bscd-export-fallback-secret');

        return hash_hmac('sha256', 'export|' . $filename, $key);
    }

    // ---- helpers -----------------------------------------------------

    /** The request's filters, validated against the values of this request's version. */
    private function criteria(): FilterCriteria
    {
        return FilterCriteria::fromRequest($this->request->getGet(), $this->dashboard->allowedValues());
    }

    /**
     * What the page may say about the snapshot in use — only metadata really
     * present in its meta file, never a guessed date.
     *
     * @return array{id: string, rows: int, generatedAt: string|null, sourceUpdatedAt: string|null}
     */
    private function snapshotInfo(): array
    {
        $snapshot = $this->dashboard->snapshot();
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
     * become 422, no snapshot a 503 "données indisponibles", anything else a
     * sanitised 503 — nothing leaks.
     */
    private function guarded(callable $body)
    {
        try {
            return $body();
        } catch (InvalidFilterException $e) {
            return $this->response->setStatusCode(422)->setJSON(['error' => 'filter', 'message' => $e->getMessage()]);
        } catch (SnapshotUnavailableException $e) {
            return $this->snapshotUnavailable($e);
        } catch (Throwable $e) {
            $reference = bscd_error_reference('DASH');
            log_message('error', 'Dashboard endpoint KO [{ref}] {uri}: {message}', [
                'ref'     => $reference,
                'uri'     => (string) $this->request->getUri(),
                'message' => $e->getMessage(),
            ]);

            return $this->response->setStatusCode(503)->setJSON(['error' => 'unavailable', 'reference' => $reference]);
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
