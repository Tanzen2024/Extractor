<?php

namespace App\Controllers;

use App\Models\ToolModel;
use App\Services\OracleExtractionService;
use CodeIgniter\Exceptions\PageNotFoundException;
use Throwable;

/**
 * Entry point for tool pages linked from the dashboard/sidebar/EXTRACTOR page.
 *
 * A tool with a query_definition shows a "launch" screen (show) and only
 * executes against Oracle on explicit user action (execute) — these queries
 * can be expensive (parallel hints, cross-database links), so nothing runs
 * on a bare page visit. A tool without a query yet falls back to the
 * "coming soon" placeholder.
 */
class ExtractionController extends BaseController
{
    public function show(string $moduleCode, string $toolCode)
    {
        // CUSTOMERS_LIST now has its own dedicated analytics dashboard
        // (filters, KPIs, charts, server-side table, filtered export) — it is
        // the dashboard, so the generic "launch extraction" screen redirects
        // there instead of showing a capped preview.
        if (strtoupper($toolCode) === 'CUSTOMERS_LIST') {
            return redirect()->to(site_url('dashboard'));
        }

        $tool = $this->resolveTool($moduleCode, $toolCode);

        if (empty($tool['query_definition'])) {
            return view('extraction/placeholder', [
                'title' => $tool['name'],
                'tool'  => $tool,
            ]);
        }

        return view('extraction/run', [
            'title'    => $tool['name'],
            'tool'     => $tool,
            'executed' => false,
        ]);
    }

    public function execute(string $moduleCode, string $toolCode)
    {
        $tool = $this->resolveTool($moduleCode, $toolCode);

        if (empty($tool['query_definition'])) {
            throw new PageNotFoundException();
        }

        $data = [
            'title'    => $tool['name'],
            'tool'     => $tool,
            'executed' => true,
            'columns'  => [],
            'rows'     => [],
        ];

        // Release the session file lock before the (potentially long) Oracle
        // call: CI4's default FileHandler holds an exclusive lock on the
        // session file for the whole request, which otherwise blocks every
        // other page the same logged-in user tries to open (dashboard,
        // extractor, ...) until this request finishes. Nothing below writes
        // to the session, so closing it early is safe.
        session()->close();

        try {
            $result = (new OracleExtractionService())->run($tool['query_definition']);

            $data['columns'] = $result['columns'];
            $data['rows']    = $result['rows'];
        } catch (Throwable $e) {
            $reference = bscd_error_reference('EXT');

            log_message('error', 'Echec extraction [{ref}] tool={code}: {message}', [
                'ref'     => $reference,
                'code'    => $tool['code'],
                'message' => $e->getMessage(),
            ]);

            $data['errorMessage'] = "Une erreur est survenue lors de l'extraction. Référence : {$reference}. Veuillez contacter l'administrateur.";
        }

        $renderStart = microtime(true);
        $html        = view('extraction/run', $data);
        $renderMs    = round((microtime(true) - $renderStart) * 1000, 1);

        log_message('info', 'Extraction view render: render_ms={renderMs} response_bytes={bytes} rows={rows}', [
            'renderMs' => $renderMs,
            'bytes'    => strlen($html),
            'rows'     => count($data['rows']),
        ]);

        return $html;
    }

    private function resolveTool(string $moduleCode, string $toolCode): array
    {
        $tool = (new ToolModel())
            ->join('modules', 'modules.id = tools.module_id')
            ->where('modules.code', strtoupper($moduleCode))
            ->where('tools.code', strtoupper($toolCode))
            ->where('tools.is_active', 1)
            ->select('tools.*, modules.name as module_name, modules.code as module_code')
            ->first();

        if (! $tool) {
            throw new PageNotFoundException();
        }

        return $tool;
    }
}
