<?php

namespace App\Commands;

use App\Models\ExportJobModel;
use App\Services\Snapshot\SnapshotException;
use App\Services\Snapshot\SnapshotIndex;
use App\Services\Snapshot\SnapshotStore;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

/**
 * Builds the DuckDB query index (SnapshotIndex) of installed versions that
 * do not have one yet: the active version first, then the versions queued
 * or running exports still reference. New versions get theirs during
 * installation (SnapshotInstaller); this command is for versions installed
 * before the index existed (first deployment) or an index deleted by hand.
 *
 *   php spark snapshot:index              missing indexes only
 *   php spark snapshot:index --rebuild    rebuild the active version's index
 *
 * Read-only on the CSV; never touches Oracle. Exit 1 if a build failed.
 */
class SnapshotIndexCommand extends BaseCommand
{
    protected $group       = 'Snapshot';
    protected $name        = 'snapshot:index';
    protected $description = 'Build the DuckDB query index of the active snapshot version (and of versions used by queued exports) when missing.';
    protected $usage       = 'snapshot:index [--rebuild]';
    protected $options     = ['--rebuild' => "Rebuild the active version's index even if it exists."];

    public function run(array $params): int
    {
        $store   = new SnapshotStore();
        $index   = new SnapshotIndex($store->config());
        $rebuild = CLI::getOption('rebuild') !== null;

        try {
            $active = $store->active();
        } catch (SnapshotException $e) {
            CLI::error('Aucun snapshot actif : ' . $e->getMessage());

            return EXIT_ERROR;
        }

        $ids = [$active->id];
        try {
            $ids = array_values(array_unique(array_merge($ids, (new ExportJobModel())->pinnedSnapshotVersions())));
        } catch (Throwable $e) {
            CLI::write('Versions des exports en attente illisibles (' . $e->getMessage() . ') : version active seulement.', 'yellow');
        }

        $failed = false;
        foreach ($ids as $id) {
            try {
                $snapshot = $store->load($id);
            } catch (SnapshotException $e) {
                CLI::write("{$id} : version absente ({$e->getMessage()})", 'yellow');

                continue;
            }

            if ($index->exists($snapshot) && ! ($rebuild && $id === $active->id)) {
                CLI::write("{$id} : index présent ({$index->path($snapshot)})", 'green');

                continue;
            }

            CLI::write("{$id} : construction de l'index (" . number_format($snapshot->rows(), 0, ',', ' ') . ' lignes)…');
            try {
                $r = $index->build($snapshot);
                CLI::write(sprintf('%s : index construit — %d lignes, %.1f s, %.0f Mo', $id, $r['rows'], $r['seconds'], $r['size'] / 1048576), 'green');
                log_message('info', '[SNAPSHOT] index construit version={id} lignes={rows} secondes={s}', ['id' => $id, 'rows' => $r['rows'], 's' => $r['seconds']]);
            } catch (SnapshotException $e) {
                $failed = true;
                CLI::error("{$id} : échec — " . $e->getMessage());
                log_message('error', '[SNAPSHOT] index en échec version={id} : {message}', ['id' => $id, 'message' => $e->getMessage()]);
            }
        }

        return $failed ? EXIT_ERROR : EXIT_SUCCESS;
    }
}
