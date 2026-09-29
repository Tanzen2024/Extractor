<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Local CUSTOMERS_LIST snapshot — the data source of user exports.
 *
 * The snapshot is customers_list.csv as produced on the source server
 * (ExtractData.gvy) and pushed here over SFTP together with a manifest
 * (size, SHA-256, line count, column count, header). `php spark
 * snapshot:install` validates it against that manifest and only then makes
 * it the active version. Nothing here encodes the file's size, row count or
 * column count: those come from each manifest. See docs/snapshot/README.md.
 *
 * Every property can be overridden from .env (snapshot.<name>).
 */
class Snapshot extends BaseConfig
{
    /**
     * Where user exports read their rows from:
     *   'snapshot' — the local validated snapshot, never Oracle (target).
     *   'oracle'   — the historical live extraction (rollback switch).
     * With 'snapshot' and no valid snapshot installed, exports fail with a
     * controlled "données de référence indisponibles" error — they never
     * fall back to Oracle on their own.
     */
    public string $exportSource = 'snapshot';

    /**
     * Root of the snapshot area:
     *   incoming/   the only folder the SFTP account writes to
     *   staging/    a received pair being validated (never read by exports)
     *   versions/   validated, immutable versions — one folder each
     *   rejected/   manifest + reason of refused deliveries (CSV deleted)
     *   current.json  pointer to the active version
     */
    public string $baseDir = WRITEPATH . 'data/snapshots/customers';

    /**
     * SFTP drop folder. Empty = <baseDir>/incoming. Set it when the SFTP
     * account is chrooted (the chroot root must be root-owned and not
     * writable, which <baseDir> cannot be), e.g. /srv/extractor_sftp/incoming.
     * Keep it on the same volume as baseDir so the move to staging/ is a
     * rename, not a copy.
     */
    public string $incomingDir = '';

    /** File names expected in incoming/ (final names, after the .part rename). */
    public string $csvFileName      = 'customers_list.csv';
    public string $manifestFileName = 'customers_list.manifest';

    /**
     * Validated versions kept on disk, the active one included. 2 = active +
     * the previous one, so `snapshot:rollback` always has a target.
     */
    public int $keepVersions = 2;

    /** Rejected deliveries whose reason file is kept for diagnosis. */
    public int $keepRejected = 5;

    /**
     * Filtered row counts are cached per (snapshot version, filters). A
     * version never changes once installed, so this only bounds cache size.
     */
    public int $countCacheTtl = 86400;

    /**
     * Candidate formats for DATE_AB (the date filter column), tried in order
     * on the first non-blank value when a snapshot is installed. The
     * detected format is stored in the version's meta and reused by every
     * export of that version.
     *
     * @var list<string>
     */
    public array $dateFormats = [
        'Y-m-d', 'Y-m-d H:i:s', 'd/m/Y', 'd/m/Y H:i:s', 'd-M-y', 'd-M-Y', 'd-M-y H:i:s', 'd-M-Y H:i:s', 'Y/m/d', 'Ymd',
    ];

    /**
     * `php spark customers:refresh` (daily Oracle -> CSV pull, cron 05:30 —
     * see docs/snapshot/README.md §0).
     *
     * The extraction is written to refreshTmpFile, never read by anything,
     * and only becomes a snapshot version through SnapshotInstaller once
     * complete and checked. publishedLink is a symlink kept pointing at the
     * active version's CSV, for operators and external tools — the
     * application itself resolves the active version through current.json.
     * '' disables the link.
     */
    public string $refreshTmpFile  = WRITEPATH . 'data/customers_list.csv.tmp';
    public string $refreshLockFile = WRITEPATH . 'data/customers_refresh.lock';
    public string $refreshLogFile  = WRITEPATH . 'logs/customers_refresh.log';
    public string $publishedLink   = WRITEPATH . 'data/customers_list.csv';

    /**
     * An extraction with fewer rows than this fraction of the active
     * version is refused (--force overrides): the source table is truncated
     * and reloaded by SQL*Loader around 04:30, so a late or failed reload
     * must not become the working file.
     */
    public float $refreshMinRowRatio = 0.9;

    /** Progress line every N extracted rows. */
    public int $refreshProgressEvery = 250_000;

    public function __construct()
    {
        parent::__construct();

        $this->refreshTmpFile       = (string) env('snapshot.refreshTmpFile', $this->refreshTmpFile);
        $this->refreshLockFile      = (string) env('snapshot.refreshLockFile', $this->refreshLockFile);
        $this->refreshLogFile       = (string) env('snapshot.refreshLogFile', $this->refreshLogFile);
        $this->publishedLink        = (string) env('snapshot.publishedLink', $this->publishedLink);
        $this->refreshMinRowRatio   = max(0.0, min(1.0, (float) env('snapshot.refreshMinRowRatio', $this->refreshMinRowRatio)));
        $this->refreshProgressEvery = max(1000, (int) env('snapshot.refreshProgressEvery', $this->refreshProgressEvery));

        $this->exportSource  = strtolower((string) env('snapshot.exportSource', $this->exportSource));
        $this->baseDir       = rtrim((string) env('snapshot.baseDir', $this->baseDir), '/\\');
        $this->incomingDir   = rtrim((string) env('snapshot.incomingDir', $this->incomingDir), '/\\');
        $this->keepVersions  = max(1, (int) env('snapshot.keepVersions', $this->keepVersions));
        $this->keepRejected  = max(0, (int) env('snapshot.keepRejected', $this->keepRejected));
        $this->countCacheTtl = max(60, (int) env('snapshot.countCacheTtl', $this->countCacheTtl));
    }

    public function usesSnapshot(): bool
    {
        return $this->exportSource !== 'oracle';
    }
}
