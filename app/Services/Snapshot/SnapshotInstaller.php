<?php

namespace App\Services\Snapshot;

use App\Models\ExportJobModel;
use Closure;
use Throwable;

/**
 * Turns a delivery sitting in incoming/ into the active snapshot — or
 * refuses it and leaves the current one active.
 *
 * Protocol with the source server (docs/snapshot/README.md): the push
 * uploads customers_list.csv.part and customers_list.manifest.part, renames
 * the CSV then the manifest to their final names (manifest last = "delivery
 * complete"), then triggers `php spark snapshot:install`, which runs this.
 *
 *   incoming/ (final names only; *.part = transfer not finished -> refused)
 *     -> staging/<id>/            moved out at once: a new push can't interfere
 *     -> SnapshotValidator        size, SHA-256, header, columns, UTF-8, lines
 *     OK -> versions/<id>/ + meta.json -> DuckDB query index built from
 *           that CSV (SnapshotIndex; failure = rejected) -> current.json
 *           switched -> prune
 *           (never a version a queued / running export was launched on)
 *     KO -> rejected/<id>/ (manifest + reason.json; CSV deleted) — the
 *           previous snapshot stays active and exports keep working
 *
 * One install at a time (exclusive non-blocking lock on install.lock).
 */
final class SnapshotInstaller
{
    public const RESULT_ACTIVATED  = 'activated';
    public const RESULT_REJECTED   = 'rejected';
    public const RESULT_INCOMPLETE = 'incomplete';
    public const RESULT_BUSY       = 'busy';

    private SnapshotStore $store;

    /** @var Closure(): list<string> versions referenced by queued / running exports */
    private Closure $pinnedVersions;

    /** @var Closure(ActiveSnapshot): (array{rows:int, seconds:float, size:int}|null) */
    private Closure $buildIndex;

    /**
     * @param (callable(): list<string>)|null $pinnedVersions tests only; default: export_jobs
     * @param (callable(ActiveSnapshot): (array|null))|null $buildIndex tests only; default: SnapshotIndex::build()
     */
    public function __construct(?SnapshotStore $store = null, ?callable $pinnedVersions = null, ?callable $buildIndex = null)
    {
        $this->store          = $store ?? new SnapshotStore();
        $config               = $this->store->config();
        $this->buildIndex     = $buildIndex !== null
            ? Closure::fromCallable($buildIndex)
            : static fn (ActiveSnapshot $snapshot): array => (new SnapshotIndex($config))->build($snapshot);
        $this->pinnedVersions = $pinnedVersions !== null
            ? Closure::fromCallable($pinnedVersions)
            : static function (): array {
                $db = db_connect();

                // No export_jobs table (fresh install): nothing can be pinned.
                return $db->tableExists('export_jobs') ? (new ExportJobModel($db))->pinnedSnapshotVersions() : [];
            };
    }

    /**
     * Versions prune() must keep for queued / running exports; null when they
     * cannot be read (database down, migration not run) — prune then deletes
     * no version at all rather than one an export may still need.
     *
     * @return list<string>|null
     */
    private function pinnedVersions(): ?array
    {
        try {
            return ($this->pinnedVersions)();
        } catch (Throwable $e) {
            log_message('warning', '[SNAPSHOT] versions utilisées par les exports illisibles ({message}) : aucune version purgée', ['message' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * @return array{result: string, reason: ?string, message: string, version: ?string, previous: ?string, meta: array<string, mixed>}
     */
    public function install(): array
    {
        return $this->withLock(fn (): array => $this->installLocked());
    }

    /**
     * Installs a CSV + manifest pair produced locally (customers:refresh)
     * instead of one received in incoming/: same lock, same validation,
     * same activation and rejection rules. Both files are moved away (into
     * staging/, then versions/ or rejected/) whatever the outcome.
     *
     * @return array{result: string, reason: ?string, message: string, version: ?string, previous: ?string, meta: array<string, mixed>}
     */
    public function installFile(string $csvPath, string $manifestPath): array
    {
        return $this->withLock(function () use ($csvPath, $manifestPath): array {
            clearstatcache();
            if (! is_file($csvPath) || ! is_file($manifestPath)) {
                return $this->result(self::RESULT_INCOMPLETE, 'nothing_to_install', 'Fichier CSV ou manifeste local absent. Snapshot actif conservé.', null, $this->store->activeId());
            }

            return $this->stageAndActivate($csvPath, $manifestPath, $this->store->activeId());
        });
    }

    /**
     * @param callable(): array<string, mixed> $install
     *
     * @return array{result: string, reason: ?string, message: string, version: ?string, previous: ?string, meta: array<string, mixed>}
     */
    private function withLock(callable $install): array
    {
        $this->store->ensureLayout();

        $lock = @fopen($this->store->dir('install.lock'), 'c');
        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            return $this->result(self::RESULT_BUSY, 'busy', 'Une installation de snapshot est déjà en cours.');
        }

        try {
            return $install();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * @return array{result: string, reason: ?string, message: string, version: ?string, previous: ?string, meta: array<string, mixed>}
     */
    private function installLocked(): array
    {
        $config   = $this->store->config();
        $incoming = $this->store->dir('incoming');
        $csvIn    = $incoming . DIRECTORY_SEPARATOR . $config->csvFileName;
        $manIn    = $incoming . DIRECTORY_SEPARATOR . $config->manifestFileName;
        $previous = $this->store->activeId();

        clearstatcache();
        if (! is_file($csvIn) || ! is_file($manIn)) {
            $parts   = glob($incoming . DIRECTORY_SEPARATOR . '*.part') ?: [];
            $message = $parts !== []
                ? 'Transfert incomplet : fichier(s) .part présent(s) sans livraison finalisée. Snapshot actif conservé.'
                : 'Aucune livraison dans incoming/ (CSV + manifeste attendus). Snapshot actif conservé.';
            log_message('warning', '[SNAPSHOT] installation ignorée : {message} actif={active}', ['message' => $message, 'active' => $previous ?? 'aucun']);

            return $this->result(self::RESULT_INCOMPLETE, $parts !== [] ? 'transfer_incomplete' : 'nothing_to_install', $message, null, $previous);
        }

        return $this->stageAndActivate($csvIn, $manIn, $previous);
    }

    /**
     * @return array{result: string, reason: ?string, message: string, version: ?string, previous: ?string, meta: array<string, mixed>}
     */
    private function stageAndActivate(string $csvIn, string $manIn, ?string $previous): array
    {
        $config  = $this->store->config();
        $id      = SnapshotStore::newVersionId();
        $staging = $this->store->dir('staging') . DIRECTORY_SEPARATOR . $id;
        $csv     = $staging . DIRECTORY_SEPARATOR . $config->csvFileName;
        $man     = $staging . DIRECTORY_SEPARATOR . $config->manifestFileName;

        log_message('info', '[SNAPSHOT] installation démarrée version={id} taille_recue={size} actif={active}', [
            'id'     => $id,
            'size'   => (int) @filesize($csvIn),
            'active' => $previous ?? 'aucun',
        ]);

        if (! @mkdir($staging, 0750, true) || ! @rename($manIn, $man) || ! @rename($csvIn, $csv)) {
            return $this->reject($id, $staging, new SnapshotException('staging_failed', 'Impossible de déplacer la livraison vers staging/.'), $previous);
        }

        $receivedAt = date(DATE_ATOM, (int) (@filemtime($csv) ?: time()));

        try {
            $manifest = SnapshotManifest::fromFile($man);
            $facts    = (new SnapshotValidator($config->dateFormats))->validate($csv, $manifest);
        } catch (Throwable $e) {
            return $this->reject($id, $staging, $e, $previous);
        }

        $meta = [
            'status'           => 'valid',
            'version'          => $id,
            'file'             => $config->csvFileName,
            'source_host'      => $manifest->sourceHost,
            'generated_at'     => $manifest->generatedAt,
            'received_at'      => $receivedAt,
            'validated_at'     => date(DATE_ATOM),
            'manifest'         => array_diff_key($manifest->raw, ['header' => true]),
        ] + $facts;

        try {
            $this->store->writeJsonAtomic($staging . DIRECTORY_SEPARATOR . SnapshotStore::META_FILE, $meta);
            @unlink($man);

            if (! @rename($staging, $this->store->versionDir($id))) {
                throw new SnapshotException('publish_failed', 'Impossible de publier la version dans versions/.');
            }

            // The dashboard's query index, built from THIS version's CSV
            // before it becomes active: an active version always has one, and
            // a failure here rejects the version (the previous one stays).
            $index = ($this->buildIndex)($this->store->load($id));
            if ($index !== null) {
                log_message('info', '[SNAPSHOT] index construit version={id} lignes={rows} secondes={s} taille={size}', [
                    'id' => $id, 'rows' => $index['rows'], 's' => $index['seconds'], 'size' => $index['size'],
                ]);
            }

            $this->store->activate($id);
        } catch (Throwable $e) {
            SnapshotStore::deleteTree($this->store->versionDir($id));

            return $this->reject($id, $staging, $e, $previous);
        }

        $removed = $this->store->prune($this->pinnedVersions());

        log_message('info', '[SNAPSHOT] nouveau snapshot activé version={id} lignes={rows} colonnes={cols} taille={size} sha256={sha} genere_le={gen} validation_s={secs} precedent={prev} purges={removed}', [
            'id'      => $id,
            'rows'    => $meta['rows'],
            'cols'    => $meta['columns'],
            'size'    => $meta['size'],
            'sha'     => $meta['sha256'],
            'gen'     => $meta['generated_at'] ?? 'n/d',
            'secs'    => $meta['validate_seconds'],
            'prev'    => $previous ?? 'aucun',
            'removed' => $removed === [] ? '-' : implode(',', $removed),
        ]);

        return $this->result(self::RESULT_ACTIVATED, null, 'Snapshot validé et activé.', $id, $previous, $meta);
    }

    /**
     * @return array{result: string, reason: ?string, message: string, version: ?string, previous: ?string, meta: array<string, mixed>}
     */
    private function reject(string $id, string $staging, Throwable $e, ?string $previous): array
    {
        $reason = $e instanceof SnapshotException ? $e->reason : 'internal_error';

        $dest = $this->store->dir('rejected') . DIRECTORY_SEPARATOR . $id;
        if (is_dir($staging) && ! @rename($staging, $dest)) {
            $dest = $staging;
        }
        // Keep the manifest + reason for diagnosis, not ~1 GB of refused data.
        @unlink($dest . DIRECTORY_SEPARATOR . $this->store->config()->csvFileName);
        if (is_dir($dest)) {
            try {
                $this->store->writeJsonAtomic($dest . DIRECTORY_SEPARATOR . 'reason.json', [
                    'version'     => $id,
                    'reason'      => $reason,
                    'message'     => $e->getMessage(),
                    'rejected_at' => date(DATE_ATOM),
                    'kept_active' => $previous,
                ]);
            } catch (Throwable) {
                // diagnosis file only
            }
        }

        log_message('error', '[SNAPSHOT] échec validation version={id} raison={reason} : {message} — snapshot précédent conservé : {prev}', [
            'id'      => $id,
            'reason'  => $reason,
            'message' => $e->getMessage(),
            'prev'    => $previous ?? 'aucun',
        ]);

        $this->store->prune($this->pinnedVersions());

        return $this->result(self::RESULT_REJECTED, $reason, $e->getMessage(), $id, $previous);
    }

    /**
     * @param array<string, mixed> $meta
     *
     * @return array{result: string, reason: ?string, message: string, version: ?string, previous: ?string, meta: array<string, mixed>}
     */
    private function result(string $result, ?string $reason, string $message, ?string $version = null, ?string $previous = null, array $meta = []): array
    {
        return compact('result', 'reason', 'message', 'version', 'previous', 'meta');
    }
}
