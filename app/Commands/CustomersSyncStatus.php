<?php

namespace App\Commands;

use App\Services\Snapshot\SyncFreshness;
use App\Services\Snapshot\SyncStatusReader;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Freshness of the customer data and history of the synchronisations — the
 * same state the dashboard shows. No Oracle access.
 *
 * Exit codes: 0 up to date · 1 late or failed (usable by a monitoring probe).
 */
class CustomersSyncStatus extends BaseCommand
{
    protected $group       = 'Snapshot';
    protected $name        = 'customers:sync-status';
    protected $description = 'Shows the freshness of the customer data and the latest synchronisations.';
    protected $usage       = 'customers:sync-status [--limit 10]';
    protected $options     = ['--limit' => 'Number of runs to list (default 10).'];

    public function run(array $params): int
    {
        $reader = new SyncStatusReader();
        $status = $reader->read();
        $limit  = max(1, (int) (CLI::getOption('limit') ?? 10));

        CLI::write('État                : ' . $status['state'] . ($status['running'] ? ' (synchronisation en cours)' : ''), $status['state'] === SyncFreshness::FRESH ? 'green' : 'red');
        CLI::write('Dernière réussite   : ' . ($status['lastSuccessAt'] ?? 'aucune'));
        CLI::write('Chargement Oracle   : ' . ($status['sourceUpdatedAt'] ?? 'n/d'));
        CLI::write('Version active      : ' . ($status['version'] ?? 'aucune') . ' (' . ($status['rows'] ?? 0) . ' lignes)');
        CLI::write('Dernière tentative  : ' . ($status['lastStatus'] ?? 'aucune') . ' ' . ($status['lastAttemptAt'] ?? '') . ($status['lastReason'] !== null ? " [{$status['lastReason']}]" : ''));
        CLI::write('Prochaine exécution : ' . ($status['nextRunAt'] ?? 'non programmée'));
        CLI::newLine();

        $rows = [];
        foreach ($reader->history($limit) as $run) {
            $rows[] = [
                $run['id'], $run['started_at'], $run['trigger_type'], $run['status'], $run['duration_seconds'] ?? '-',
                $run['rows_read'] ?? '-', $run['rows_added'] ?? '-', $run['rows_modified'] ?? '-', $run['rows_ignored'] ?? '-',
                $run['rows_removed'] ?? '-', $run['error_reason'] ?? '',
            ];
        }
        if ($rows !== []) {
            CLI::table($rows, ['#', 'Début', 'Origine', 'Statut', 'Durée (s)', 'Lus', 'Ajoutés', 'Modifiés', 'Ignorés', 'Retirés', 'Raison']);
        }

        return $status['state'] === SyncFreshness::FRESH ? EXIT_SUCCESS : EXIT_ERROR;
    }
}
