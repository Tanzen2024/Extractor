<?php

use App\Database\Seeds\AdminUsersSeeder;
use App\Models\AppUserModel;
use App\Services\AuthorizationService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * AdminUsersSeeder grants "hugues.nwameh" the ADMIN role, idempotently, with
 * no local password.
 *
 * Runs against the default test connection (SQLite :memory:, shared within a
 * test). Forges only the RBAC tables it needs — the full migration set is not
 * replayable from scratch (a legacy migration reads a since-removed .sql).
 *
 * @internal
 */
final class AdminUsersSeederTest extends CIUnitTestCase
{
    private \CodeIgniter\Database\BaseConnection $conn;

    private const RBAC_TABLES = [
        'app_role_permissions', 'app_user_roles', 'app_permissions', 'app_roles', 'app_users',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->conn = db_connect();
        $this->forgeTables();
        $this->seedRoleAndPermissions();
    }

    protected function tearDown(): void
    {
        $forge = \Config\Database::forge();
        foreach (self::RBAC_TABLES as $t) {
            $forge->dropTable($t, true);
        }
        parent::tearDown();
    }

    private function runSeeder(): void
    {
        (new AdminUsersSeeder(config('Database')))->run();
    }

    public function testCreatesHuguesAsActiveAdmin(): void
    {
        $this->runSeeder();

        $user = (new AppUserModel())->findByUsername('hugues.nwameh');

        $this->assertNotNull($user);
        $this->assertSame('Hugues NWAMEH', $user['display_name']);
        $this->assertSame(1, (int) $user['is_active']);
        $this->assertArrayNotHasKey('password', $user);
        $this->assertArrayNotHasKey('password_hash', $user);

        $authz = AuthorizationService::forUser((int) $user['id']);
        $this->assertTrue($authz->hasRole('ADMIN'));
        $this->assertTrue($authz->can('USER_CREATE'));
        $this->assertTrue($authz->can('AUDIT_VIEW'));
        $this->assertCount(11, $authz->permissions());
    }

    public function testIsIdempotent(): void
    {
        $this->runSeeder();
        $this->runSeeder();
        $this->runSeeder();

        $this->assertSame(1, $this->conn->table('app_users')->where('username', 'hugues.nwameh')->countAllResults());

        $userId = (int) (new AppUserModel())->findByUsername('hugues.nwameh')['id'];
        $this->assertSame(1, $this->conn->table('app_user_roles')->where('user_id', $userId)->countAllResults());
    }

    public function testReactivatesADisabledExistingRow(): void
    {
        $this->conn->table('app_users')->insert([
            'username' => 'hugues.nwameh', 'display_name' => 'Hugues NWAMEH', 'is_active' => 0,
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $this->runSeeder();

        $this->assertSame(1, (int) (new AppUserModel())->findByUsername('hugues.nwameh')['is_active']);
    }

    // --- fixtures --------------------------------------------------------

    private function forgeTables(): void
    {
        $forge = \Config\Database::forge();
        foreach (self::RBAC_TABLES as $t) {
            $forge->dropTable($t, true);
        }

        $id  = ['type' => 'INTEGER', 'auto_increment' => true];
        $ts  = ['created_at' => ['type' => 'DATETIME', 'null' => true], 'updated_at' => ['type' => 'DATETIME', 'null' => true]];

        $forge->addField(['id' => $id, 'username' => ['type' => 'VARCHAR', 'constraint' => 100],
            'display_name' => ['type' => 'VARCHAR', 'constraint' => 190, 'null' => true],
            'is_active' => ['type' => 'INTEGER', 'default' => 1]] + $ts);
        $forge->addKey('id', true);
        $forge->createTable('app_users', true);

        foreach (['app_roles' => 60, 'app_permissions' => 80] as $table => $codeLen) {
            $forge->addField(['id' => $id, 'code' => ['type' => 'VARCHAR', 'constraint' => $codeLen],
                'name' => ['type' => 'VARCHAR', 'constraint' => 120],
                'description' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
                'is_active' => ['type' => 'INTEGER', 'default' => 1]] + $ts);
            $forge->addKey('id', true);
            $forge->createTable($table, true);
        }

        $forge->addField(['id' => $id, 'user_id' => ['type' => 'INTEGER'], 'role_id' => ['type' => 'INTEGER'],
            'created_at' => ['type' => 'DATETIME', 'null' => true]]);
        $forge->addKey('id', true);
        $forge->addUniqueKey(['user_id', 'role_id']);
        $forge->createTable('app_user_roles', true);

        $forge->addField(['id' => $id, 'role_id' => ['type' => 'INTEGER'], 'permission_id' => ['type' => 'INTEGER'],
            'created_at' => ['type' => 'DATETIME', 'null' => true]]);
        $forge->addKey('id', true);
        $forge->addUniqueKey(['role_id', 'permission_id']);
        $forge->createTable('app_role_permissions', true);
    }

    private function seedRoleAndPermissions(): void
    {
        $now = date('Y-m-d H:i:s');

        $this->conn->table('app_roles')->insert(['code' => 'ADMIN', 'name' => 'Administrateur', 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $roleId = (int) $this->conn->insertID();

        $codes = ['DASHBOARD_VIEW', 'USER_VIEW', 'USER_CREATE', 'USER_EDIT', 'USER_DISABLE',
            'ROLE_VIEW', 'ROLE_CREATE', 'ROLE_EDIT', 'PERMISSION_VIEW', 'PERMISSION_EDIT', 'AUDIT_VIEW'];
        foreach ($codes as $c) {
            $this->conn->table('app_permissions')->insert(['code' => $c, 'name' => $c, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
            $this->conn->table('app_role_permissions')->insert(['role_id' => $roleId, 'permission_id' => (int) $this->conn->insertID(), 'created_at' => $now]);
        }
    }
}
