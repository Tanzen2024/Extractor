<?php

namespace App\Commands;

use App\Services\Snapshot\SnapshotException;
use App\Services\Snapshot\SnapshotStore;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Re-activates an already validated version: by default the most recent one
 * older than the active version, or the one given. Only rewrites the
 * current.json pointer — no file is copied or re-validated.
 */
class SnapshotRollback extends BaseCommand
{
    protected $group       = 'Snapshot';
    protected $name        = 'snapshot:rollback';
    protected $description = 'Re-activate the previous validated snapshot version (or a given one).';
    protected $usage       = 'snapshot:rollback [<version>]';
    protected $arguments   = ['version' => 'Version id to activate (default: the one before the active version)'];

    public function run(array $params): int
    {
        $store    = new SnapshotStore();
        $activeId = $store->activeId();
        $target   = $params[0] ?? null;

        if ($target === null) {
            foreach ($store->versions() as $id) {
                if ($activeId === null || strcmp($id, $activeId) < 0) {
                    $target = $id;
                    break;
                }
            }
        }

        if ($target === null) {
            CLI::error('Aucune version antérieure validée disponible.');

            return EXIT_ERROR;
        }

        try {
            $store->activate($target);
        } catch (SnapshotException $e) {
            CLI::error('Rollback impossible : ' . $e->getMessage());

            return EXIT_ERROR;
        }

        log_message('warning', '[SNAPSHOT] rollback manuel : {from} -> {to}', ['from' => $activeId ?? 'aucun', 'to' => $target]);
        CLI::write("Snapshot actif : {$target} (précédent : " . ($activeId ?? 'aucun') . ')', 'green');

        return EXIT_SUCCESS;
    }
}
