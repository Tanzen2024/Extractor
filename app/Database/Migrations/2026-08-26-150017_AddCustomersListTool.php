<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Introduces the first real Oracle-backed extraction ("Customers List") and
 * retires the four Phase 1 CMS placeholder tools (deactivated, not deleted:
 * they were never wired to a real query, but the rows/history stay intact).
 * The SQL itself is set separately via a direct UPDATE once provided —
 * this migration only creates the row with an empty query_definition.
 */
class AddCustomersListTool extends Migration
{
    private array $legacyPlaceholderCodes = [
        'LISTE_UTILISATEURS', 'EXTRACTION_ASC', 'ANNULATION_BT_MT', 'ACI_INTEGRE',
    ];

    public function up()
    {
        $cms = $this->db->table('modules')->where('code', 'CMS')->get()->getRowArray();

        if (! $cms) {
            return;
        }

        $this->db->table('tools')
            ->where('module_id', $cms['id'])
            ->whereIn('code', $this->legacyPlaceholderCodes)
            ->update(['is_active' => 0]);

        $exists = $this->db->table('tools')
            ->where('module_id', $cms['id'])
            ->where('code', 'CUSTOMERS_LIST')
            ->get()
            ->getRowArray();

        if ($exists) {
            return;
        }

        $this->db->table('tools')->insert([
            'uuid'             => bscd_uuid(),
            'module_id'        => $cms['id'],
            'code'             => 'CUSTOMERS_LIST',
            'name'             => 'Customers List',
            'icon'             => 'fa-regular fa-address-card',
            'route'            => 'extractions/cms/customers_list',
            'query_definition' => null,
            'display_order'    => 0,
            'is_active'        => 1,
            'created_at'       => date('Y-m-d H:i:s'),
            'updated_at'       => date('Y-m-d H:i:s'),
        ]);
    }

    public function down()
    {
        $cms = $this->db->table('modules')->where('code', 'CMS')->get()->getRowArray();

        if (! $cms) {
            return;
        }

        $this->db->table('tools')
            ->where('module_id', $cms['id'])
            ->where('code', 'CUSTOMERS_LIST')
            ->delete();

        $this->db->table('tools')
            ->where('module_id', $cms['id'])
            ->whereIn('code', $this->legacyPlaceholderCodes)
            ->update(['is_active' => 1]);
    }
}
