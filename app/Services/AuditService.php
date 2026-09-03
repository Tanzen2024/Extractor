<?php

namespace App\Services;

use App\Models\AuditLogModel;
use Throwable;

/**
 * Security & administration audit trail (app_audit_logs).
 *
 * A failure to write an audit row must never break the user-facing action, so
 * every write is guarded. A password is never part of $context.
 */
class AuditService
{
    private AuditLogModel $model;

    public function __construct(?AuditLogModel $model = null)
    {
        $this->model = $model ?? new AuditLogModel();
    }

    /**
     * @param array{
     *     username?:string|null, user_id?:int|null, module?:string|null,
     *     target_type?:string|null, target_id?:int|string|null, description?:string|null
     * } $context
     */
    public function log(string $action, array $context = []): void
    {
        try {
            $request = service('request');
            $session = session();

            $this->model->insert([
                'user_id'     => $context['user_id']  ?? $session->get('user_id'),
                'username'    => $context['username'] ?? $session->get('username'),
                'action'      => $action,
                'module'      => $context['module']      ?? null,
                'target_type' => $context['target_type'] ?? null,
                'target_id'   => isset($context['target_id']) ? (string) $context['target_id'] : null,
                'description' => $context['description'] ?? null,
                'ip_address'  => method_exists($request, 'getIPAddress') ? $request->getIPAddress() : null,
                'user_agent'  => mb_substr((string) ($request->getUserAgent() ?? ''), 0, 255) ?: null,
                'created_at'  => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
            log_message('error', 'AuditService: ecriture impossible ({action}): {msg}', [
                'action' => $action,
                'msg'    => $e->getMessage(),
            ]);
        }
    }
}
