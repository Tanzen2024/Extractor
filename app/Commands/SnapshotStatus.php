<?php

namespace App\Commands;

use App\Services\Snapshot\SnapshotException;
use App\Services\Snapshot\SnapshotStore;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Shows the active snapshot, the installed versions, pending deliveries and
 * the latest rejections. Read-only.
 */
class SnapshotStatus extends BaseCommand
{
    protected $group       = 'Snapshot';
    protected $name        = 'snapshot:status';
    protected $description = 'Show the active CUSTOMERS_LIST snapshot, installed versions and recent rejections.';
    protected $usage       = 'snapshot:status';

    public function run(array $params): int
    {
        $store = new SnapshotStore();

        CLI::write('Source des exports : ' . $store->config()->exportSource);
        CLI::write('Répertoire         : ' . $store->baseDir());
        CLI::write('');

        try {
            $active = $store->active();
            $m      = $active->meta;
            CLI::write('Snapshot actif : ' . $active->id, 'green');
            CLI::table([
                ['Lignes de données', number_format($active->rows(), 0, ',', ' ')],
                ['Lignes (avec en-tête)', (string) ($m['lines'] ?? '?')],
                ['Colonnes', (string) ($m['columns'] ?? '?')],
                ['Taille', ($m['size'] ?? '?') . ' octets'],
                ['SHA-256', (string) ($m['sha256'] ?? '?')],
                ['Généré (source)', (string) ($m['generated_at'] ?? 'n/d')],
                ['Reçu', (string) ($m['received_at'] ?? '?')],
                ['Validé', (string) ($m['validated_at'] ?? '?')],
                ['Format DATE_AB', (string) ($m['date_ab_format'] ?? 'non détecté')],
                ['DATE_AB illisibles', (string) ($m['date_ab_unparsed'] ?? '?')],
            ], ['Métadonnée', 'Valeur']);
        } catch (SnapshotException $e) {
            CLI::write('Aucun snapshot actif : ' . $e->getMessage(), 'red');
        }

        CLI::write('');
        CLI::write('Versions installées : ' . (implode(', ', $store->versions()) ?: 'aucune'));

        $incoming = glob($store->dir('incoming') . DIRECTORY_SEPARATOR . '*') ?: [];
        CLI::write('incoming/          : ' . ($incoming === [] ? 'vide' : implode(', ', array_map('basename', $incoming))));

        $rejected = glob($store->dir('rejected') . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . 'reason.json') ?: [];
        rsort($rejected, SORT_STRING);
        foreach (array_slice($rejected, 0, 3) as $file) {
            $r = $store->readJson($file) ?? [];
            CLI::write(sprintf('Rejet %s : %s — %s', $r['version'] ?? '?', $r['reason'] ?? '?', $r['message'] ?? ''), 'yellow');
        }

        return EXIT_SUCCESS;
    }
}
