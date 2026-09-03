<?php

namespace App\Models;

use CodeIgniter\Model;

class AuditLogModel extends Model
{
    protected $table        = 'app_audit_logs';
    protected $primaryKey   = 'id';
    protected $returnType   = 'array';
    protected $allowedFields = [
        'user_id', 'username', 'action', 'module', 'target_type',
        'target_id', 'description', 'ip_address', 'user_agent', 'created_at',
    ];
    protected $useTimestamps = false;

    /**
     * Filtered + paginated feed for the audit screen.
     *
     * @param array{username?:string, action?:string, date_from?:string, date_to?:string} $filters
     */
    public function feed(array $filters, int $perPage = 30): array
    {
        if (! empty($filters['username'])) {
            $this->like('username', $filters['username']);
        }
        if (! empty($filters['action'])) {
            $this->where('action', $filters['action']);
        }
        if (! empty($filters['date_from'])) {
            $this->where('created_at >=', $filters['date_from'] . ' 00:00:00');
        }
        if (! empty($filters['date_to'])) {
            $this->where('created_at <=', $filters['date_to'] . ' 23:59:59');
        }

        return [
            'rows'  => $this->orderBy('id', 'DESC')->paginate($perPage),
            'pager' => $this->pager,
        ];
    }

    /**
     * @return list<string>
     */
    public function knownActions(): array
    {
        return array_map(
            static fn ($row) => (string) $row['action'],
            $this->select('action')->distinct()->orderBy('action', 'ASC')->findAll()
        );
    }
}
