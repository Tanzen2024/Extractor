<?php

namespace App\Models;

use CodeIgniter\Model;

class ExportJobModel extends Model
{
    protected $table         = 'export_jobs';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $allowedFields  = [
        'uuid', 'requested_by', 'format', 'filters', 'filters_label', 'status',
        'row_count', 'rows_total', 'rows_processed', 'rows_exported',
        'file_path', 'file_name', 'file_size', 'error_reference',
        'started_at', 'finished_at',
    ];
    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $beforeInsert = ['assignUuid'];

    protected function assignUuid(array $data): array
    {
        if (empty($data['data']['uuid'])) {
            $data['data']['uuid'] = bscd_uuid();
        }

        return $data;
    }

    /**
     * Atomically claims the oldest pending job for this worker: the UPDATE
     * only touches a row still in 'pending', so two concurrent workers can
     * never grab the same job (the second one's affectedRows() is 0).
     *
     * @return array<string, mixed>|null
     */
    public function claimNext(): ?array
    {
        $db = $this->db;

        $row = $this->where('status', 'pending')->orderBy('id', 'ASC')->first();
        if ($row === null) {
            return null;
        }

        $db->table($this->table)
            ->where('id', $row['id'])
            ->where('status', 'pending')
            ->update([
                'status'     => 'running',
                'started_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

        if ($db->affectedRows() !== 1) {
            return null; // another worker got it first
        }

        return $this->find($row['id']);
    }

    /**
     * Batched progress write from the worker (see Export\ThrottledProgress —
     * never called per row). Only touches a job still 'running', so a late
     * write can never resurrect a finished, failed or cancelled job — the
     * counters of a cancelled job stay where the cancellation found them.
     *
     * @return bool false = the job is no longer 'running' (cancelled): the
     *     worker must stop. This is the cooperative cancellation check.
     */
    public function updateProgress(int $id, int $processed, int $total, int $exported): bool
    {
        $this->db->table($this->table)
            ->where('id', $id)
            ->where('status', 'running')
            ->update([
                // Unsigned columns: never send a negative (strict mode error).
                'rows_total'     => max(0, $total),
                'rows_processed' => max(0, $processed),
                'rows_exported'  => max(0, $exported),
                'updated_at'     => date('Y-m-d H:i:s'),
            ]);

        // MySQL counts *changed* rows: an identical write within the same
        // second also gives 0 — confirm with the status before stopping.
        return $this->db->affectedRows() === 1 || $this->statusOf($id) === 'running';
    }

    public function statusOf(int $id): ?string
    {
        $row = $this->db->table($this->table)->select('status')->where('id', $id)->get()->getRowArray();

        return $row['status'] ?? null;
    }

    /**
     * User cancellation: pending|running -> cancelled, atomically (the UPDATE
     * only matches the status just read, so it can never overwrite a 'done'
     * or 'error' written by the worker in between). finished_at is the
     * cancellation time: the duration stops there.
     *   pending: the worker never claims it (claimNext() only takes 'pending');
     *   running: the worker sees it at its next progress write, stops and
     *            deletes its partial file; it can no longer mark it done.
     *
     * @return string|null the status the job was cancelled from, or null when
     *     it was already terminal (done / error / cancelled) or does not exist.
     */
    public function cancel(int $id): ?string
    {
        // Two tries: a lost race (e.g. pending -> running under our feet)
        // is re-read once; a terminal status then stops it.
        for ($try = 0; $try < 2; $try++) {
            $status = $this->statusOf($id);
            if (! in_array($status, ['pending', 'running'], true)) {
                return null;
            }

            $now = date('Y-m-d H:i:s');
            $this->db->table($this->table)
                ->where('id', $id)
                ->where('status', $status)
                ->update(['status' => 'cancelled', 'finished_at' => $now, 'updated_at' => $now]);

            if ($this->db->affectedRows() === 1) {
                return $status;
            }
        }

        return null;
    }

    /**
     * Progress as reported by GET /exports/{id}. The percentage is derived
     * here, never stored: rows_processed / rows_total, always within 0..100 —
     * pending = 0, running = 0..99 (the file is still being finalised after
     * the scan), done = 100, cancelled / error = where the scan stopped
     * (0..99, kept for diagnosis), unknown or zero total = 0 (never a
     * division by zero).
     *
     * @param array<string, mixed> $job
     *
     * @return array{percent: int, processed: int, total: int|null, exported: int}
     */
    public static function progress(array $job): array
    {
        $total     = isset($job['rows_total']) ? max(0, (int) $job['rows_total']) : null;
        $processed = max(0, (int) ($job['rows_processed'] ?? 0));
        $exported  = max(0, (int) ($job['rows_exported'] ?? 0));

        $percent = match ($job['status'] ?? null) {
            'done'    => 100,
            'running', 'cancelled', 'error' => $total > 0 ? min(99, intdiv($processed * 100, $total)) : 0,
            default   => 0,
        };

        return [
            'percent'   => $percent,
            'processed' => $processed,
            'total'     => $total,
            'exported'  => $exported,
        ];
    }

    /**
     * Processing time as reported by GET /exports/{id}, in whole seconds,
     * measured on the server clock from the job's own timestamps (never from
     * the row counts) — the browser only animates it between two polls:
     *   pending        waitSeconds    = now - created_at (queue time, not processing);
     *   running        elapsedSeconds = now - started_at;
     *   done / error / cancelled
     *                  elapsedSeconds = finished_at - started_at, final = true
     *                  (cancelled: finished_at = cancellation time; a job
     *                  cancelled while pending has no started_at -> null).
     * null when the timestamp it needs is missing.
     *
     * @param array<string, mixed> $job
     *
     * @return array{waitSeconds: int|null, elapsedSeconds: int|null, final: bool}
     */
    public static function timing(array $job, ?int $now = null): array
    {
        $now ??= time();
        $at      = static fn (string $field): ?int => empty($job[$field]) ? null : (strtotime((string) $job[$field]) ?: null);
        $seconds = static fn (?int $from, ?int $to): ?int => $from !== null && $to !== null ? max(0, $to - $from) : null;

        $status = $job['status'] ?? null;
        $final  = in_array($status, ['done', 'error', 'cancelled'], true);

        return [
            'waitSeconds'    => $status === 'pending' ? $seconds($at('created_at'), $now) : null,
            'elapsedSeconds' => match (true) {
                $status === 'running' => $seconds($at('started_at'), $now),
                $final                => $seconds($at('started_at'), $at('finished_at')),
                default               => null,
            },
            'final' => $final,
        ];
    }

    /**
     * running -> done, only if the job is still 'running': a job cancelled
     * while its file was being finalised never becomes 'done' (no download).
     *
     * @return bool false = not finalised (cancelled meanwhile) — the caller
     *     must delete the file it produced.
     */
    public function markDone(int $id, string $filePath, string $fileName, int $fileSize, int $rowCount): bool
    {
        return $this->finish($id, [
            'status'        => 'done',
            'file_path'     => $filePath,
            'file_name'     => $fileName,
            'file_size'     => $fileSize,
            'row_count'     => $rowCount,
            // Exact final count, also for sources that report no progress
            // (Oracle) — the batched counters may stop one batch short.
            'rows_exported' => $rowCount,
        ]);
    }

    /**
     * Ends jobs left 'running' by a worker that died (killed during a
     * restart, OOM, server reboot): no progress write for $silentSeconds.
     * Only the worker holding the single-watcher lock calls this, at start.
     * Same conditional UPDATE as finish(): a job that moves meanwhile is
     * left alone.
     *
     * @param callable(): string $reference new error_reference per job
     *
     * @return list<array{id: int, uuid: string, reference: string, updated_at: string|null}>
     */
    public function failStale(int $silentSeconds, callable $reference): array
    {
        $limit  = date('Y-m-d H:i:s', time() - max(60, $silentSeconds));
        $failed = [];

        $rows = $this->db->table($this->table)
            ->select('id, uuid, updated_at, started_at')
            ->where('status', 'running')
            ->groupStart()
                ->where('updated_at <', $limit)
                ->orGroupStart()->where('updated_at', null)->where('started_at <', $limit)->groupEnd()
            ->groupEnd()
            ->get()->getResultArray();

        foreach ($rows as $row) {
            $ref = $reference();
            if ($this->markError((int) $row['id'], $ref)) {
                $failed[] = ['id' => (int) $row['id'], 'uuid' => (string) $row['uuid'], 'reference' => $ref, 'updated_at' => $row['updated_at']];
            }
        }

        return $failed;
    }

    /** running -> error; a cancelled job stays cancelled. */
    public function markError(int $id, string $reference): bool
    {
        return $this->finish($id, [
            'status'          => 'error',
            'error_reference' => $reference,
        ]);
    }

    /** @param array<string, mixed> $fields */
    private function finish(int $id, array $fields): bool
    {
        $now = date('Y-m-d H:i:s');

        $this->db->table($this->table)
            ->where('id', $id)
            ->where('status', 'running')
            ->update($fields + ['finished_at' => $now, 'updated_at' => $now]);

        return $this->db->affectedRows() === 1;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function pending(): array
    {
        return $this->whereIn('status', ['pending', 'running'])->orderBy('id', 'ASC')->findAll();
    }
}
