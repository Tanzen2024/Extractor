<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use Config\Oracle as OracleConfig;

/**
 * Minimal Oracle connectivity check for administrators. The full read-only
 * catalog browser (tables/views/columns) is a later phase; this screen only
 * answers "can we currently reach cmsprod with the configured credentials?".
 */
class OracleController extends BaseController
{
    public function index()
    {
        $this->denyUnlessAdmin();

        $config = new OracleConfig();

        return view('admin/oracle/index', [
            'title'       => 'Connexion Oracle',
            'dsn'         => $config->dsn,
            'username'    => $config->username,
            'isConfigured' => $config->isConfigured(),
        ]);
    }

    public function test()
    {
        $this->denyUnlessAdmin();

        $config = new OracleConfig();

        if (! $config->isConfigured()) {
            return $this->response->setJSON([
                'success' => false,
                'message' => 'La connexion Oracle n\'est pas configurée (variables .env manquantes).',
            ]);
        }

        try {
            $connection = oci_connect($config->username, $config->password, $config->dsn, $config->charset);

            if (! $connection) {
                $error = oci_error();

                log_message('error', 'Echec connexion Oracle (DSN={dsn}): {error}', [
                    'dsn'   => $config->dsn,
                    'error' => $error['message'] ?? 'unknown',
                ]);

                return $this->response->setJSON([
                    'success' => false,
                    'message' => 'Connexion échouée. Consultez les logs applicatifs pour le détail technique.',
                ]);
            }

            $statement = oci_parse($connection, 'SELECT 1 FROM dual');
            oci_execute($statement);
            oci_free_statement($statement);
            oci_close($connection);

            return $this->response->setJSON([
                'success' => true,
                'message' => 'Connexion Oracle réussie (' . $config->dsn . ').',
            ]);
        } catch (\Throwable $e) {
            $reference = bscd_error_reference('ORA');

            log_message('error', 'Exception test connexion Oracle [{ref}]: {message}', [
                'ref'     => $reference,
                'message' => $e->getMessage(),
            ]);

            return $this->response->setJSON([
                'success' => false,
                'message' => "Une erreur est survenue lors du test de connexion. Référence : {$reference}.",
            ]);
        }
    }

    private function denyUnlessAdmin(): void
    {
        if (session()->get('roleCode') !== 'ADMIN') {
            throw new \CodeIgniter\Exceptions\PageNotFoundException();
        }
    }
}
