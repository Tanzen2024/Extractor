<?php

namespace App\Services\Export;

/**
 * What the export worker really needs from a directory, tested by doing it
 * as the current OS user — not by reading its owner: a root-owned folder in
 * group www-data with g+w (or an ACL) is fine, a www-data-owned one on a
 * read-only mount is not.
 *
 *   (link)      the directory itself a symlink: refused, like prepare-writable.sh
 *   exists      is_dir()
 *   writable    is_writable() (indicative only)
 *   write_test  a uniquely named file created with fopen('x') — exclusive
 *               create, so an existing file (an export...) is never opened
 *   delete_test that file unlinked and really gone
 *   subdir_test a sub-folder created and removed (OpenSpout creates its own
 *               xlsx<uniqid>/ tree under its temp folder)
 *
 * Probe entries are removed in a finally block, whatever happens; nothing
 * else in the directory is read or touched.
 *
 * create = true makes a missing directory (mkdir -p, 0775 & umask) — never
 * as root: a folder root creates belongs to root, which is exactly the
 * failure this class exists to detect. Permissions of an existing directory
 * are never changed (PHP running as www-data cannot fix a root-owned folder
 * anyway): that is the deployment's job (docs/deploy/prepare-writable.sh).
 */
final class WritableDirectory
{
    /**
     * @return array{path: string, exists: bool, created: bool, writable: bool, write_test: bool,
     *     delete_test: bool, subdir_test: bool, user: string, owner: string, mode: string, error: string|null}
     */
    public static function probe(string $path, bool $create = false): array
    {
        $path   = rtrim($path, '/\\');
        $result = [
            'path'        => $path,
            'exists'      => false,
            'created'     => false,
            'writable'    => false,
            'write_test'  => false,
            'delete_test' => false,
            'subdir_test' => false,
            'user'        => ExportWorkerState::processUser(),
            'owner'       => '',
            'mode'        => '',
            'error'       => null,
        ];

        clearstatcache(true, $path);

        // Same rule as prepare-writable.sh: the managed directory itself must
        // not be a link (the probe would test, and the worker write to,
        // another place than the one configured). Its parents (APP_DIR,
        // writable/) may be links — release layouts, separate volume.
        $target = self::linkTarget($path);
        if ($target !== null) {
            $result['error'] = "est un lien symbolique (vers {$target}) : refusé, configurer le chemin réel (export.openSpoutTempPath)";

            return $result;
        }

        if (file_exists($path) && ! is_dir($path)) {
            $result['error'] = 'existe mais n\'est pas un répertoire';

            return $result;
        }

        if (! is_dir($path)) {
            if (! $create) {
                $result['error'] = 'absent';

                return $result;
            }
            if (ExportWorkerState::isRoot()) {
                $result['error'] = 'absent — non créé : exécuté en root, il appartiendrait à root (lancer en www-data, ou docs/deploy/prepare-writable.sh)';

                return $result;
            }
            // @: a concurrent worker / web request may create it meanwhile.
            if (! @mkdir($path, 0775, true) && ! is_dir($path)) {
                $result['error'] = 'absent et impossible à créer (parent ' . self::nearestExistingParent($path) . ' non inscriptible)';

                return $result;
            }
            $result['created'] = true;
        }

        $result['exists']   = true;
        $result['writable'] = is_writable($path);
        $result['owner']    = ExportWorkerState::ownerOf($path);
        $perms              = @fileperms($path);
        $result['mode']     = $perms === false ? '' : substr(sprintf('%o', $perms), -4);

        $token = $path . DIRECTORY_SEPARATOR . '.doctor_probe_' . getmypid() . '_' . bin2hex(random_bytes(6));
        $file  = $token . '.tmp';
        $dir   = $token . '.d';

        try {
            $fp = @fopen($file, 'xb');
            if ($fp !== false) {
                $result['write_test'] = @fwrite($fp, 'ok') === 2;
                fclose($fp);
                $result['delete_test'] = @unlink($file) && ! file_exists($file);
            }

            if (@mkdir($dir, 0775)) {
                $result['subdir_test'] = @rmdir($dir) && ! is_dir($dir);
            }
        } finally {
            // Only our own uniquely named entries, even after an exception.
            if (is_file($file)) {
                @unlink($file);
            }
            if (is_dir($dir)) {
                @rmdir($dir);
            }
        }

        if (! self::usable($result)) {
            $result['error'] = 'NON utilisable par ' . $result['user'];
        }

        return $result;
    }

    /** @param array<string, mixed> $result probe() output */
    public static function usable(array $result): bool
    {
        return $result['exists'] && $result['write_test'] && $result['delete_test'] && $result['subdir_test'];
    }

    /**
     * Would the current user be able to create this missing directory?
     * (nearest existing ancestor writable — indicative, is_writable()).
     */
    public static function creatable(string $path): bool
    {
        $parent = self::nearestExistingParent($path);

        return $parent !== '' && is_dir($parent) && is_writable($parent);
    }

    /** "exists=yes writable=yes write_test=yes ..." */
    public static function describe(array $result): string
    {
        $yes   = static fn (bool $b): string => $b ? 'yes' : 'no';
        $parts = [
            'exists=' . $yes($result['exists']) . ($result['created'] ? ' (créé)' : ''),
            'writable=' . $yes($result['writable']),
            'write_test=' . $yes($result['write_test']),
            'delete_test=' . $yes($result['delete_test']),
            'subdir_test=' . $yes($result['subdir_test']),
            'user=' . $result['user'],
        ];
        if ($result['owner'] !== '') {
            $parts[] = 'owner=' . $result['owner'];
        }
        if ($result['mode'] !== '' && PHP_OS_FAMILY !== 'Windows') {
            $parts[] = 'mode=' . $result['mode'];
        }

        return implode(' ', $parts);
    }

    /**
     * Where $path leads when its last component is a link, else null.
     * is_link() alone misses Windows junctions; comparing with the resolved
     * parent also leaves linked ancestors (writable/, APP_DIR) out of it.
     */
    public static function linkTarget(string $path): ?string
    {
        if (is_link($path)) {
            return realpath($path) ?: (@readlink($path) ?: '?');
        }

        $real   = realpath($path);
        $parent = realpath(dirname($path));
        if ($real === false || $parent === false) {
            return null;
        }

        $expected = rtrim($parent, '/\\') . DIRECTORY_SEPARATOR . basename($path);
        $same     = PHP_OS_FAMILY === 'Windows' ? strcasecmp($real, $expected) === 0 : $real === $expected;

        return $same ? null : $real;
    }

    /** First existing ancestor — may be a file, which then blocks creation. */
    private static function nearestExistingParent(string $path): string
    {
        $dir = dirname($path);
        while (! file_exists($dir)) {
            $up = dirname($dir);
            if ($up === $dir) {
                return '';
            }
            $dir = $up;
        }

        return $dir;
    }
}
