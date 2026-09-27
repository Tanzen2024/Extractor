<?php

namespace App\Services\Snapshot;

use Config\Snapshot as SnapshotConfig;

/**
 * On-disk layout of the snapshot area and the "active version" pointer.
 *
 *   <baseDir>/incoming/                 SFTP drop (only writable dir for the push account)
 *   <baseDir>/staging/<id>/             delivery being validated
 *   <baseDir>/versions/<id>/customers_list.csv + customers_list.meta.json   (immutable)
 *   <baseDir>/rejected/<id>/            manifest + reason.json of a refused delivery
 *   <baseDir>/current.json              {"version": "<id>", ...}
 *
 * Switching versions only rewrites current.json (write to a temp file, then
 * rename over it — atomic on the same volume). Version folders are never
 * modified after install, so an export that resolved version A keeps
 * reading A to the end even if B is activated meanwhile. Pruning skips any
 * file still open (Windows refuses the delete; it is retried at the next
 * install) and never touches the active version.
 *
 * Every path is built from the configured base dir and a version id matching
 * VERSION_PATTERN — no user input ever reaches a path.
 */
final class SnapshotStore
{
    public const VERSION_PATTERN = '/^\d{8}T\d{6}_[0-9a-f]{8}$/';
    public const META_FILE       = 'customers_list.meta.json';
    public const POINTER_FILE    = 'current.json';

    private SnapshotConfig $config;

    public function __construct(?SnapshotConfig $config = null)
    {
        $this->config = $config ?? new SnapshotConfig();
    }

    public function config(): SnapshotConfig
    {
        return $this->config;
    }

    public function baseDir(): string
    {
        return rtrim($this->config->baseDir, '/\\');
    }

    public function dir(string $name): string
    {
        if ($name === 'incoming' && $this->config->incomingDir !== '') {
            return rtrim($this->config->incomingDir, '/\\');
        }

        return $this->baseDir() . DIRECTORY_SEPARATOR . $name;
    }

    public function versionDir(string $id): string
    {
        self::assertId($id);

        return $this->dir('versions') . DIRECTORY_SEPARATOR . $id;
    }

    public function ensureLayout(): void
    {
        foreach (['', 'incoming', 'staging', 'versions', 'rejected'] as $sub) {
            $dir = $sub === '' ? $this->baseDir() : $this->dir($sub);
            if (! is_dir($dir) && ! @mkdir($dir, 0750, true) && ! is_dir($dir)) {
                throw new SnapshotException('layout', "Impossible de créer le répertoire snapshot {$dir}.");
            }
        }
    }

    public static function newVersionId(): string
    {
        return date('Ymd\THis') . '_' . bin2hex(random_bytes(4));
    }

    public static function assertId(string $id): void
    {
        if (preg_match(self::VERSION_PATTERN, $id) !== 1) {
            throw new SnapshotException('invalid_version', 'Identifiant de version snapshot invalide.');
        }
    }

    /**
     * The active version, resolved from current.json. Callers keep the
     * returned object for the whole operation (see class doc).
     *
     * @throws SnapshotUnavailableException
     */
    public function active(): ActiveSnapshot
    {
        $pointer = $this->readJson($this->dir(self::POINTER_FILE));
        $id      = is_array($pointer) ? (string) ($pointer['version'] ?? '') : '';

        if ($id === '' || preg_match(self::VERSION_PATTERN, $id) !== 1) {
            throw new SnapshotUnavailableException('Aucun snapshot actif (pointeur absent ou invalide).');
        }

        return $this->load($id);
    }

    public function activeId(): ?string
    {
        try {
            return $this->active()->id;
        } catch (SnapshotException) {
            return null;
        }
    }

    /**
     * @throws SnapshotUnavailableException
     */
    public function load(string $id): ActiveSnapshot
    {
        self::assertId($id);

        $dir  = $this->versionDir($id);
        $csv  = $dir . DIRECTORY_SEPARATOR . $this->config->csvFileName;
        $meta = $this->readJson($dir . DIRECTORY_SEPARATOR . self::META_FILE);

        if (! is_array($meta) || ($meta['status'] ?? null) !== 'valid' || ! isset($meta['rows'], $meta['delimiter'])) {
            throw new SnapshotUnavailableException("Métadonnées absentes ou invalides pour la version {$id}.");
        }
        if (! is_file($csv) || ! is_readable($csv)) {
            throw new SnapshotUnavailableException("Fichier absent pour la version {$id}.");
        }

        return new ActiveSnapshot($id, $csv, $meta);
    }

    /**
     * Installed versions (valid meta), newest first.
     *
     * @return list<string>
     */
    public function versions(): array
    {
        $ids = [];
        foreach (glob($this->dir('versions') . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [] as $dir) {
            $id = basename($dir);
            if (preg_match(self::VERSION_PATTERN, $id) !== 1) {
                continue;
            }
            $meta = $this->readJson($dir . DIRECTORY_SEPARATOR . self::META_FILE);
            if (is_array($meta) && ($meta['status'] ?? null) === 'valid') {
                $ids[] = $id;
            }
        }
        rsort($ids, SORT_STRING);

        return $ids;
    }

    /**
     * Points current.json at $id (which must load cleanly). Atomic: readers
     * see either the old or the new pointer, never a partial file.
     */
    public function activate(string $id): void
    {
        $this->load($id);

        $this->writeJsonAtomic($this->dir(self::POINTER_FILE), [
            'version'      => $id,
            'activated_at' => date(DATE_ATOM),
        ]);

        $warning = $this->publishLink($id);
        if ($warning !== null) {
            log_message('warning', '[SNAPSHOT] lien {link} non mis à jour : {warning}', ['link' => $this->config->publishedLink, 'warning' => $warning]);
        }
    }

    /**
     * Points Config\Snapshot::$publishedLink (writable/data/customers_list.csv)
     * at version $id's CSV: symlink to a temp name, then rename over the
     * link — atomic on Linux. Best effort: the pointer (current.json) is the
     * source of truth, so a failure here (Windows without symlink privilege,
     * a regular file in the way) is returned, never thrown.
     */
    public function publishLink(string $id): ?string
    {
        $link = $this->config->publishedLink;
        if ($link === '') {
            return null;
        }

        clearstatcache(true, $link);
        if (file_exists($link) && ! is_link($link)) {
            return 'un fichier ordinaire occupe ce chemin (non remplacé)';
        }

        $dir = dirname($link);
        if (! is_dir($dir) && ! @mkdir($dir, 0750, true) && ! is_dir($dir)) {
            return "répertoire {$dir} impossible à créer";
        }

        $target = $this->versionDir($id) . DIRECTORY_SEPARATOR . $this->config->csvFileName;
        $tmp    = $link . '.' . bin2hex(random_bytes(4)) . '.lnk';

        if (! @symlink($target, $tmp)) {
            return 'symlink() refusé par le système';
        }
        if (! @rename($tmp, $link)) {
            @unlink($tmp);

            return 'remplacement du lien impossible';
        }

        return null;
    }

    /**
     * Deletes installed versions beyond keepVersions (newest kept), never the
     * active one. Best effort: a version still open by an export stays and is
     * retried next time.
     *
     * @return list<string> Removed version ids.
     */
    public function prune(): array
    {
        $active  = $this->activeId();
        $others  = array_values(array_filter($this->versions(), static fn (string $id): bool => $id !== $active));
        $slots   = max(0, $this->config->keepVersions - ($active === null ? 0 : 1));
        $removed = [];

        foreach (array_slice($others, $slots) as $id) {
            if (self::deleteTree($this->versionDir($id))) {
                $removed[] = $id;
            }
        }

        // Leftover staging folders (an install killed mid-validation).
        foreach (glob($this->dir('staging') . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (@filemtime($dir) < time() - 86400) {
                self::deleteTree($dir);
            }
        }

        // Rejected deliveries: keep the most recent reasons only.
        $rejected = glob($this->dir('rejected') . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [];
        rsort($rejected, SORT_STRING);
        foreach (array_slice($rejected, $this->config->keepRejected) as $dir) {
            self::deleteTree($dir);
        }

        return $removed;
    }

    /** @return array<string, mixed>|null */
    public function readJson(string $path): ?array
    {
        // A reader may hit the instant the pointer is being replaced
        // (Windows sharing violation) — retry briefly.
        for ($attempt = 0; $attempt < 20; $attempt++) {
            clearstatcache(true, $path);
            if (! is_file($path)) {
                return null;
            }
            $raw = @file_get_contents($path);
            if ($raw !== false) {
                $data = json_decode($raw, true);

                return is_array($data) ? $data : null;
            }
            usleep(25_000);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function writeJsonAtomic(string $path, array $data): void
    {
        $tmp  = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        if (@file_put_contents($tmp, $json, LOCK_EX) !== strlen($json)) {
            @unlink($tmp);

            throw new SnapshotException('write_failed', 'Écriture impossible : ' . basename($path));
        }

        // rename() replaces the target atomically; on Windows it fails while
        // another process has the target open — retry for up to ~5 s.
        for ($attempt = 0; $attempt < 100; $attempt++) {
            if (@rename($tmp, $path)) {
                return;
            }
            usleep(50_000);
        }
        @unlink($tmp);

        throw new SnapshotException('write_failed', 'Remplacement impossible : ' . basename($path));
    }

    /**
     * Best-effort recursive delete, warnings suppressed (CodeIgniter turns
     * them into exceptions). Returns whether the folder is gone.
     */
    public static function deleteTree(string $dir): bool
    {
        if (! is_dir($dir)) {
            return true;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            is_dir($path) && ! is_link($path) ? self::deleteTree($path) : @unlink($path);
        }

        return @rmdir($dir) || ! is_dir($dir);
    }
}
