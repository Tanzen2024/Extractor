<?php

namespace App\Services\Snapshot;

use Config\Snapshot as SnapshotConfig;

/**
 * Runs SQL with the DuckDB CLI (Config\Snapshot::$duckdbBinary) — the PHP
 * build has no DuckDB extension. One process per call:
 *
 *   proc_open([binary, (-readonly), database]) — an argument array, so no
 *   shell ever parses anything; the SQL goes on stdin (escapeshellarg()
 *   would also mangle '%' on Windows). Output: one JSON object per row
 *   (.mode jsonlines); each statement tags its rows with a `q` column when
 *   several results come back from one call.
 *
 * Threads and memory are capped per process; read-only processes can open
 * the same database concurrently.
 */
final class DuckDb
{
    private string $binary;
    private int $threads;
    private string $memoryLimit;

    public function __construct(?SnapshotConfig $config = null)
    {
        $config            = $config ?? new SnapshotConfig();
        $this->binary      = $config->duckdbBinary;
        $this->threads     = max(1, $config->duckdbThreads);
        // Goes into a SET statement: only a plain size is accepted.
        $this->memoryLimit = preg_match('/^\d+(\.\d+)?\s*(KB|MB|GB|TB)$/i', trim($config->duckdbMemoryLimit)) === 1
            ? trim($config->duckdbMemoryLimit)
            : '1GB';
    }

    /**
     * @return list<array<string, mixed>> every row of every SELECT, in order
     *
     * @throws SnapshotException 'duckdb_failed' (binary missing, SQL error, ...)
     */
    public function query(string $database, string $sql, bool $readOnly = true): array
    {
        $script = ".mode jsonlines\n"
            . "SET threads = {$this->threads};\n"
            . "SET memory_limit = '{$this->memoryLimit}';\n"
            . rtrim(trim($sql), ';') . ";\n";

        $err = tmpfile();
        $cmd = array_merge([$this->binary], $readOnly ? ['-readonly'] : [], [$database]);
        // stderr into a temp file, not a pipe: reading two pipes one after
        // the other can deadlock when the second one fills up.
        $proc = @proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => $err], $pipes);
        if (! is_resource($proc)) {
            throw new SnapshotException('duckdb_failed', "DuckDB introuvable ou non exécutable ({$this->binary}).");
        }

        fwrite($pipes[0], $script);
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $code = proc_close($proc);

        rewind($err);
        $stderr = trim((string) stream_get_contents($err));
        fclose($err);

        if ($code !== 0 || $stderr !== '') {
            $detail = $stderr !== '' ? strtok($stderr, "\n") : "code de sortie {$code}";
            if ($code === 1 && $stderr === '' && $out === '') {
                $detail = "DuckDB introuvable ou non exécutable ({$this->binary})";
            }

            throw new SnapshotException('duckdb_failed', 'Requête DuckDB en échec : ' . $detail);
        }

        $rows = [];
        foreach (explode("\n", $out) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $row = json_decode($line, true);
            if (! is_array($row)) {
                throw new SnapshotException('duckdb_failed', 'Réponse DuckDB illisible : ' . substr($line, 0, 120));
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /** "v1.1.3 …" — or null when the binary cannot be run. */
    public function version(): ?string
    {
        try {
            $proc = @proc_open([$this->binary, '-version'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (! is_resource($proc)) {
                return null;
            }
            fclose($pipes[0]);
            $out = trim((string) stream_get_contents($pipes[1]));
            fclose($pipes[1]);
            fclose($pipes[2]);

            return proc_close($proc) === 0 && $out !== '' ? $out : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** SQL string literal — every value interpolated into SQL goes through here. */
    public static function literal(string $value): string
    {
        // NUL cannot appear in a DuckDB string literal; nothing legitimate has one.
        return "'" . str_replace(["\0", "'"], ['', "''"], $value) . "'";
    }
}
