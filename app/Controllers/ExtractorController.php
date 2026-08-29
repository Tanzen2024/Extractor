<?php

namespace App\Controllers;

use App\Models\ModuleModel;

/**
 * Landing page for the EXTRACTOR sidebar entry: one card per active module,
 * each listing its active tools as extraction shortcuts. Reuses the same
 * dynamic module/tool catalog as the dashboard and sidebar.
 */
class ExtractorController extends BaseController
{
    public function index()
    {
        $modules = (new ModuleModel())->getActiveModulesWithTools();

        return view('extractor/index', [
            'title'   => 'EXTRACTOR',
            'modules' => $modules,
        ]);
    }
}
