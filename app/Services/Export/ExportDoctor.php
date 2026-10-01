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
 * Never prints a secret: database password, encryption key and .env values
 * are not read back; only hostnames, names, paths, owners and versions.
 */
final class ExportDoctor
{
    public const OK      = 'OK';
    public const WARNING = 'WARNING';
    public const ERROR   = 'ERROR';

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
    ) {
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
                $this->add(self::OK, 'jobs.last_error', "#{$last['id']} {$last['error_reference']} à {$last['finished_at']} — détail : grep {$last['error_reference']} writable/logs/*.log ou journalctl");
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
        $dirs = [
            'logs'           => $base . 'logs',
            'exports'        => $base . 'uploads/exports',
            'openspout tmp'  => $base . 'tmp/openspout',
            'cache'          => $base . 'cache',
            'worker state'   => ($this->worker ?? new ExportWorkerState())->dir(),
        ];

        foreach ($dirs as $label => $dir) {
            $this->add(...$this->probeDirectory($label, rtrim($dir, '/\\')));
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

    /** @return array{0: string, 1: string, 2: string} */
    private function probeDirectory(string $label, string $dir): array
    {
        $check = 'dir.' . str_replace(' ', '_', $label);

        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            return [self::ERROR, $check, "{$dir} absent et impossible à créer"];
        }

        // A real write, not is_writable(): ACLs, read-only mounts, SELinux.
        $probe = $dir . DIRECTORY_SEPARATOR . '.doctor_' . getmypid() . '_' . bin2hex(random_bytes(3));
        $ok    = @file_put_contents($probe, 'ok') === 2;
        @unlink($probe);

        $owner = ExportWorkerState::ownerOf($dir);

        return $ok
            ? [self::OK, $check, "{$dir} accessible en écriture" . ($owner !== '' ? " (propriétaire : {$owner})" : '')]
            : [self::ERROR, $check, "{$dir} NON accessible en écriture pour " . ExportWorkerState::processUser() . ($owner !== '' ? " (propriétaire : {$owner})" : '')];
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
