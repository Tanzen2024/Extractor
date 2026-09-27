<?php

namespace App\Commands;

use App\Services\Snapshot\SnapshotInstaller;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Validates the delivery in the snapshot incoming/ folder and, only if every
 * check passes, activates it. Triggered by the source server right after its
 * SFTP push (restricted SSH key, forced command — see docs/snapshot/README.md);
 * also runnable by hand. Never polls, never touches Oracle.
 *
 * Prints ONE summary line (captured in the source server's log) and exits:
 *   0 activated · 1 rejected (previous snapshot kept) · 2 nothing/incomplete · 3 busy
 */
class SnapshotInstall extends BaseCommand
{
    protected $group       = 'Snapshot';
    protected $name        = 'snapshot:install';
    protected $description = 'Validate the pushed customers_list.csv and activate it (previous snapshot kept on any failure).';
    protected $usage       = 'snapshot:install';

    public function run(array $params): int
    {
        $r = (new SnapshotInstaller())->install();

        $line = match ($r['result']) {
            SnapshotInstaller::RESULT_ACTIVATED => sprintf(
                'SNAPSHOT OK version=%s lignes=%d colonnes=%d taille=%d sha256=%s validation=%ss precedent=%s',
                $r['version'],
                $r['meta']['rows'],
                $r['meta']['columns'],
                $r['meta']['size'],
                $r['meta']['sha256'],
                $r['meta']['validate_seconds'],
                $r['previous'] ?? 'aucun',
            ),
            default => sprintf(
                'SNAPSHOT %s raison=%s actif_conserve=%s : %s',
                strtoupper($r['result']),
                $r['reason'] ?? '-',
                $r['previous'] ?? 'aucun',
                $r['message'],
            ),
        };

        CLI::write($line, $r['result'] === SnapshotInstaller::RESULT_ACTIVATED ? 'green' : 'red');

        return match ($r['result']) {
            SnapshotInstaller::RESULT_ACTIVATED  => 0,
            SnapshotInstaller::RESULT_REJECTED   => 1,
            SnapshotInstaller::RESULT_INCOMPLETE => 2,
            default                              => 3,
        };
    }
}
