<?php

namespace App\Services\Export;

use App\Services\Snapshot\SnapshotStore;
use CodeIgniter\Database\BaseConnection;
use Config\Snapshot as SnapshotConfig;
use Throwable;

/**
 * Checks behind `php spark export:doctor`: everything an asynchronous export
 * needs, as seen by the OS user running the command — run it as the worker's
 * user (e.g. `sudo -u www-data php spark export:doctor`), since most
 * local / server differences are about who may read or write what.
 *
 * Directories are judged on behaviour (create + delete a file and a
 * sub-folder as this user, see WritableDirectory), not on their owner.
 * Without $fix nothing is created: a diagnostic run as root must not leave
 * root-owned folders behind (it used to, which is how writable/tmp/openspout
 * ended up root:root 0755 on the server).
 *
 * Levels: ERROR (current problem, exit 1), WARNING, OK, INFO (history, e.g.
 * the last failed job — never blocking). Each check belongs to a category
 * (see category()) so configuration, filesystem, worker and job history are
 * told apart in the summary.
 *
 * Never prints a secret: database password, encryption key and .env values
 * are not read back; only hostnames, names, paths, owners and versions.
 */
final class ExportDoctor
{
    public const OK      = 'OK';
    public const INFO    = 'INFO';
    public const WARNING = 'WARNING';
    public const ERROR   = 'ERROR';

    /** Shown when only root can repair a directory. */
    public const SYSTEM_FIX = 'sudo /usr/local/sbin/extractor-prepare-writable (ou sudo systemctl restart extractor-export-worker) — docs/deploy/export-worker.md';

    /** Needed by CodeIgniter, MySQLi and OpenSpout (XLSX) — see composer.lock. */
    private const REQUIRED_EXTENSIONS = ['mysqli', 'intl', 'mbstring', 'json', 'ctype', 'dom', 'fileinfo', 'filter', 'libxml', 'xmlreader', 'zip', 'zlib'];

    /** A full CUSTOMERS_LIST CSV is ~0.8 GB, OpenSpout scratch for XLSX ~1.7 GB. */
    private const MIN_FREE_BYTES = 3 * 1024 ** 3;

    /** @var list<array{level: string, check: string, detail: string}> */
    private array $results = [];

    public function __construct(
        private readonly ?BaseConnection $db = null,
        private readonly ?ExportWorkerState $worker = null,
        private readonly ?SnapshotConfig $snapshot = null,
        private readonly string $writePath = WRITEPATH,
        private readonly ?string $openSpoutTempPath = null,
        private readonly bool $fix = false,
    ) {
    }

    /**
     * `export:doctor --preflight` (systemd ExecStartPre, as the worker's
     * user): only what the worker needs on disk before its first poll —
     * missing directories are created (as this user, never as root), every
     * one must pass the write/delete test. No database: MySQL may still be
     * starting at boot, and the worker waits for it on its own.
     *
     * @return list<array{level: string, check: string, detail: string}>
     */
    public function preflight(): array
    {
        $this->results = [];
        $this->checkProcessUser();

        foreach ($this->workerDirectories() as $label => $dir) {
            $this->checkDirectory($label, $dir, true);
        }

        return $this->results;
    }

    /**
     * Every directory the worker writes to, label => path (no trailing separator).
     *
     * @return array<string, string>
     */
    public function workerDirectories(): array
    {
        $base = rtrim($this->writePath, '/\\') . DIRECTORY_SEPARATOR;

        return [
            'logs'          => $base . 'logs',
            'exports'       => $base . 'uploads' . DIRECTORY_SEPARATOR . 'exports',
            'openspout_tmp' => $this->openSpoutTempPath(),
            'cache'         => $base . 'cache',
            'worker_state'  => rtrim(($this->worker ?? new ExportWorkerState())->dir(), '/\\'),
        ];
    }

    public function openSpoutTempPath(): string
    {
        if ($this->openSpoutTempPath !== null) {
            return rtrim($this->openSpoutTempPath, '/\\');
        }

        // A test pointing the doctor at another writable/ gets its own tmp.
        return rtrim($this->writePath, '/\\') === rtrim(WRITEPATH, '/\\')
            ? config('Export')->openSpoutTempPath()
            : rtrim($this->writePath, '/\\') . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'openspout_tmp';
    }

    /** configuration | filesystem | data | worker | history */
    public static function category(string $check): string
    {
        return match (true) {
            str_starts_with($check, 'dir.'), str_starts_with($check, 'disk.') => 'filesystem',
            str_starts_with($check, 'worker.') => 'worker',
            str_starts_with($check, 'jobs.')   => 'history',
            str_starts_with($check, 'snapshot') => 'data',
            default => 'configuration',
        };
    }

    /** @return list<array{level: string, check: string, detail: string}> */
    public function run(): array
    {
        $this->results = [];

        $this->checkPhp();
        $this->checkConfiguration();
        $db = $this->checkDatabase();
        if ($db !== null) {
            $this->checkSchema($db);
            $this->checkMigrations($db);
            $this->checkJobs($db);
        }
        $this->checkDirectories();
        $this->checkSnapshot();
        $this->checkWorker();

        return $this->results;
    }

    public static function worstLevel(array $results): string
    {
        $levels = array_column($results, 'level');

        return in_array(self::ERROR, $levels, true) ? self::ERROR : (in_array(self::WARNING, $levels, true) ? self::WARNING : self::OK);
    }

    private function add(string $level, string $check, string $detail): void
    {
        $this->results[] = ['level' => $level, 'check' => $check, 'detail' => $detail];
    }

    private function checkPhp(): void
    {
        $this->add(
            version_compare(PHP_VERSION, '8.2.0', '>=') ? self::OK : self::ERROR,
            'php.version',
            PHP_VERSION . ' ' . PHP_SAPI . ' (' . PHP_BINARY . ', ini: ' . (php_ini_loaded_file() ?: 'aucun') . ') — composer exige ^8.2',
        );

        $missing = array_values(array_filter(self::REQUIRED_EXTENSIONS, static fn (string $ext): bool => ! extension_loaded($ext)));
        $this->add($missing === [] ? self::OK : self::ERROR, 'php.extensions', $missing === []
            ? 'toutes présentes : ' . implode(', ', self::REQUIRED_EXTENSIONS)
            : 'manquantes : ' . implode(', ', $missing));

        foreach (['pcntl' => "arrêt propre sur SIGTERM (systemd) : sans elle, un arrêt interrompt le job en cours", 'posix' => 'nom de l\'utilisateur système dans les diagnostics'] as $ext => $why) {
            if (! extension_loaded($ext)) {
                $this->add(self::WARNING, "php.ext.{$ext}", "absente ({$why})" . (PHP_OS_FAMILY === 'Windows' ? ' — normal sous Windows' : ''));
            }
        }

        $memory = (string) ini_get('memory_limit');
        $bytes  = self::iniBytes($memory);
        $this->add($bytes === -1 || $bytes >= 256 * 1024 ** 2 ? self::OK : self::WARNING, 'php.memory_limit', $memory . ' (streaming : ~10 Mo utiles, 256M conseillé)');

        $tz  = (string) ini_get('date.timezone');
        $app = config('App')->appTimezone;
        $this->add(self::OK, 'php.timezone', "application : {$app} (forcée par CodeIgniter), php.ini : " . ($tz !== '' ? $tz : 'non défini') . ', courant : ' . date_default_timezone_get());

        $this->checkProcessUser();
    }

    private function checkProcessUser(): void
    {
        if (ExportWorkerState::isRoot()) {
            $this->add(self::WARNING, 'process.user', ExportWorkerState::processUser() . ' — exécuté en root : les tests d\'écriture ne disent rien du worker et rien n\'est créé ; relancer avec `sudo -u www-data php spark export:doctor`');

            return;
        }

        $this->add(self::OK, 'process.user', ExportWorkerState::processUser() . ' — doit être le même utilisateur que le worker (et, idéalement, le serveur web)');
    }

    private function checkConfiguration(): void
    {
        $this->add(is_file(ROOTPATH . '.env') ? (is_readable(ROOTPATH . '.env') ? self::OK : self::ERROR) : self::WARNING, 'config.env',
            is_file(ROOTPATH . '.env') ? (is_readable(ROOTPATH . '.env') ? '.env présent et lisible' : '.env illisible par ' . ExportWorkerState::processUser()) : '.env absent (variables d\'environnement système uniquement)');

        $this->add(ENVIRONMENT === 'production' ? self::OK : self::WARNING, 'config.environment', 'CI_ENVIRONMENT=' . ENVIRONMENT);

        $threshold = config('Logger')->threshold;
        $logsErrors = is_array($threshold) ? in_array(4, $threshold, true) : (int) $threshold >= 4;
        $this->add($logsErrors ? self::OK : self::ERROR, 'config.logger', 'threshold=' . json_encode($threshold) . ($logsErrors ? ' (erreurs journalisées)' : ' — les erreurs ne sont PAS journalisées (mettre logger.threshold >= 4)'));

        $snapshot = $this->snapshot ?? new SnapshotConfig();
        $this->add(self::OK, 'config.exportSource', $snapshot->exportSource . ($snapshot->usesSnapshot() ? ' (fichier snapshot local)' : ' (Oracle en direct)'));
        if (! $snapshot->usesSnapshot() && ! extension_loaded('oci8')) {
            $this->add(self::ERROR, 'php.ext.oci8', 'absente alors que exportSource=oracle');
        }
    }

    private function checkDatabase(): ?BaseConnection
    {
        try {
            $db = $this->db ?? db_connect();
            $db->initialize();
            $version = method_exists($db, 'getVersion') ? $db->getVersion() : '?';
            $this->add(self::OK, 'db.connection', sprintf('%s %s sur %s/%s (version %s)', $db->DBDriver, $db->username ?: '-', $db->hostname ?: '-', $db->getDatabase(), $version));

            return $db;
        } catch (Throwable $e) {
            $this->add(self::ERROR, 'db.connection', 'connexion impossible : ' . ExportJobFailure::sanitize($e->getMessage()));

            return null;
        }
    }

    private function checkSchema(BaseConnection $db): void
    {
        try {
            $missing = ExportJobSchema::missingColumns($db);
        } catch (Throwable $e) {
            $this->add(self::ERROR, 'db.export_jobs', 'lecture impossible : ' . ExportJobFailure::sanitize($e->getMessage()));

            return;
        }

        if ($missing === null) {
            $this->add(self::ERROR, 'db.export_jobs', 'table absente — lancer `php spark migrate`');
        } elseif ($missing !== []) {
            $this->add(self::ERROR, 'db.export_jobs', 'colonnes manquantes : ' . implode(', ', $missing) . ' — lancer `php spark migrate`');
        } else {
            $this->add(self::OK, 'db.export_jobs', count(ExportJobSchema::COLUMNS) . ' colonnes requises présentes (dont rows_total, rows_processed, rows_exported)');
        }
    }

    private function checkMigrations(BaseConnection $db): void
    {
        try {
            if (! $db->tableExists($db->prefixTable('migrations'), false)) {
                $this->add(self::ERROR, 'db.migrations', 'table migrations absente — base jamais migrée (`php spark migrate`)');

                return;
            }

            $runner  = service('migrations', null, $db, false);
            $applied = [];
            foreach ($db->table('migrations')->select('class')->get()->getResultArray() as $row) {
                $applied[ltrim((string) $row['class'], '\\')] = true;
            }

            $pending = [];
            foreach ($runner->findNamespaceMigrations('App') as $migration) {
                if (! isset($applied[ltrim($migration->class, '\\')])) {
                    $pending[] = $migration->version . '_' . substr(strrchr('\\' . $migration->class, '\\'), 1);
                }
            }

            $this->add($pending === [] ? self::OK : self::ERROR, 'db.migrations', $pending === []
                ? 'aucune migration en attente'
                : count($pending) . ' en attente : ' . implode(', ', $pending) . ' — lancer `php spark migrate`');
        } catch (Throwable $e) {
            $this->add(self::WARNING, 'db.migrations', 'état non vérifiable : ' . ExportJobFailure::sanitize($e->getMessage()));
        }
    }

    private function checkJobs(BaseConnection $db): void
    {
        try {
            $counts = [];
            foreach ($db->table(ExportJobSchema::TABLE)->select('status, COUNT(*) AS n')->groupBy('status')->get()->getResultArray() as $row) {
                $counts[$row['status']] = (int) $row['n'];
            }
            $this->add(self::OK, 'jobs.status', $counts === [] ? 'aucun job' : http_build_query($counts, '', ', '));

            $last = $db->table(ExportJobSchema::TABLE)->select('id, error_reference, finished_at')->where('status', 'error')
                ->orderBy('id', 'DESC')->limit(1)->get()->getRowArray();
            if ($last !== null) {
                // History, not a configuration problem: never blocking.
                $this->add(self::INFO, 'jobs.last_error', "#{$last['id']} {$last['error_reference']} à {$last['finished_at']} (historique, non bloquant) — détail : grep {$last['error_reference']} writable/logs/*.log ou journalctl");
            }

            $stuck = $db->table(ExportJobSchema::TABLE)->where('status', 'running')
                ->where('updated_at <', date('Y-m-d H:i:s', time() - 3600))->countAllResults();
            if ($stuck > 0) {
                $this->add(self::WARNING, 'jobs.stale', "{$stuck} job(s) 'running' sans progression depuis plus d'une heure (worker mort ?) — ils seront passés en error au prochain démarrage du worker --watch");
            }
        } catch (Throwable $e) {
            $this->add(self::WARNING, 'jobs.status', 'non lisible : ' . ExportJobFailure::sanitize($e->getMessage()));
        }
    }

    private function checkDirectories(): void
    {
        $base = rtrim($this->writePath, '/\\') . DIRECTORY_SEPARATOR;

        foreach ($this->workerDirectories() as $label => $dir) {
            $this->checkDirectory($label, $dir, $this->fix);
        }

        // The file CodeIgniter appends to today: it may exist but belong to
        // another user (web server vs worker) — then every log line of this
        // user is dropped silently.
        $log = $base . 'logs/log-' . date('Y-m-d') . '.log';
        if (is_file($log)) {
            $fp = @fopen($log, 'ab');
            $owner = ExportWorkerState::ownerOf($log);
            $this->add($fp !== false ? self::OK : self::ERROR, 'dir.logs.today', $fp !== false
                ? basename($log) . ' accessible en écriture'
                : basename($log) . ' NON accessible en écriture pour ' . ExportWorkerState::processUser() . ($owner !== '' ? " (propriétaire : {$owner})" : '') . ' : ses erreurs sont perdues sans trace');
            if ($fp !== false) {
                fclose($fp);
            }
        }

        $free = @disk_free_space($base);
        if ($free !== false) {
            $this->add($free >= self::MIN_FREE_BYTES ? self::OK : self::WARNING, 'disk.free', round($free / 1024 ** 3, 1) . ' Go libres sous ' . $base . ' (export complet : ~1 Go CSV, ~2 Go de temporaire XLSX)');
        }
    }

    /**
     * Real write / delete / sub-folder test as this user (WritableDirectory).
     * A missing directory this user could create itself is only a WARNING
     * outside $create: the worker creates it on demand.
     */
    private function checkDirectory(string $label, string $dir, bool $create): void
    {
        $check  = 'dir.' . $label;
        $result = WritableDirectory::probe($dir, $create);
        $state  = WritableDirectory::describe($result);

        if (WritableDirectory::usable($result)) {
            $this->add(self::OK, $check, "{$dir}\n{$state}");

            return;
        }

        if ($result['error'] === 'absent' && WritableDirectory::creatable($dir)) {
            $this->add(self::WARNING, $check, "{$dir}\n{$state}\nabsent, créable par {$result['user']} : sera créé à la demande (ou maintenant : php spark export:doctor --fix)");

            return;
        }

        $fix = match (true) {
            WritableDirectory::linkTarget($dir) !== null => 'remplacer le lien par un vrai répertoire (prepare-writable.sh le refuse aussi)',
            file_exists($dir) && ! is_dir($dir)          => 'supprimer ou renommer ce fichier (le dossier sera recréé au démarrage)',
            PHP_OS_FAMILY === 'Windows'                  => 'vérifier les droits NTFS du dossier pour ' . $result['user'],
            default                                      => 'correction système requise (PHP ne peut pas changer les droits d\'un dossier qui ne lui appartient pas) : ' . self::SYSTEM_FIX,
        };

        $this->add(self::ERROR, $check, "{$dir}\n{$state}\n{$result['error']} — {$fix}");
    }

    private function checkSnapshot(): void
    {
        $config = $this->snapshot ?? new SnapshotConfig();
        if (! $config->usesSnapshot()) {
            return;
        }

        try {
            $active = (new SnapshotStore($config))->active();
            $fp     = @fopen($active->csvPath, 'rb');
            if ($fp === false) {
                $this->add(self::ERROR, 'snapshot', "version {$active->id} active mais {$active->csvPath} illisible pour " . ExportWorkerState::processUser());

                return;
            }
            fclose($fp);
            $this->add(self::OK, 'snapshot', "version {$active->id}, " . number_format($active->rows(), 0, ',', ' ') . ' lignes, fichier lisible');
        } catch (Throwable $e) {
            $this->add(self::ERROR, 'snapshot', 'aucun snapshot exploitable : ' . ExportJobFailure::sanitize($e->getMessage()) . ' (php spark snapshot:status)');
        }
    }

    private function checkWorker(): void
    {
        $worker  = $this->worker ?? new ExportWorkerState();
        $running = $worker->isWatcherRunning();
        $beat    = $worker->readHeartbeat();

        if ($running === null) {
            $this->add(self::WARNING, 'worker.watch', 'verrou ' . $worker->lockPath() . ' illisible pour cet utilisateur');
        } elseif (! $running) {
            $this->add(self::WARNING, 'worker.watch', 'aucun worker --watch actif (normal si le worker est lancé par cron/tâche planifiée sans --watch)');
        } else {
            $this->add(self::OK, 'worker.watch', 'worker --watch actif' . ($beat !== null ? " (pid {$beat['pid']}, utilisateur {$beat['user']}, PHP {$beat['php']}, démarré {$beat['started_at']})" : ''));
        }

        if ($beat === null) {
            return;
        }

        $age = time() - (int) strtotime((string) ($beat['last_poll_at'] ?? ''));
        if ($running && empty($beat['current_job']) && $age > 120) {
            $this->add(self::WARNING, 'worker.heartbeat', "dernier passage il y a {$age} s alors qu'aucun job n'est en cours — worker bloqué ?");
        }

        if ($running && ($beat['code'] ?? null) !== $worker->codeFingerprint()) {
            $this->add(self::WARNING, 'worker.code', 'le worker tourne sur une version du code antérieure aux fichiers présents : il redémarrera après son job en cours (ou : systemctl restart)');
        }

        if ($running && ($beat['user'] ?? null) !== ExportWorkerState::processUser()) {
            $this->add(self::WARNING, 'worker.user', "le worker tourne sous {$beat['user']}, ce diagnostic sous " . ExportWorkerState::processUser() . ' : relancer le diagnostic avec le même utilisateur');
        }
    }

    private static function iniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return -1;
        }
        $unit = strtolower(substr($value, -1));
        $n    = (int) $value;

        return match ($unit) {
            'g'     => $n * 1024 ** 3,
            'm'     => $n * 1024 ** 2,
            'k'     => $n * 1024,
            default => $n,
        };
    }
}
