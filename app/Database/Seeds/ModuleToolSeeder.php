<?php

namespace App\Database\Seeds;

use App\Models\ModuleModel;
use App\Models\ToolModel;
use CodeIgniter\Database\Seeder;

class ModuleToolSeeder extends Seeder
{
    public function run()
    {
        $moduleModel = new ModuleModel();
        $toolModel   = new ToolModel();

        $catalog = [
            [
                'code'  => 'CMS',
                'name'  => 'CMS',
                'icon'  => 'fa-solid fa-database',
                'color' => 'primary',
                'tools' => [
                    ['code' => 'LISTE_UTILISATEURS', 'name' => 'Liste des utilisateurs'],
                    ['code' => 'EXTRACTION_ASC', 'name' => 'Extraction ASC'],
                    ['code' => 'ANNULATION_BT_MT', 'name' => 'Annulation BT et MT'],
                    ['code' => 'ACI_INTEGRE', 'name' => 'ACI intégré'],
                ],
            ],
        ];

        foreach ($catalog as $order => $moduleDef) {
            $module = $moduleModel->where('code', $moduleDef['code'])->first();

            if (! $module) {
                $moduleId = $moduleModel->insert([
                    'code'          => $moduleDef['code'],
                    'name'          => $moduleDef['name'],
                    'icon'          => $moduleDef['icon'],
                    'color'         => $moduleDef['color'],
                    'display_order' => $order,
                ], true);
            } else {
                $moduleId = $module['id'];
            }

            foreach ($moduleDef['tools'] as $toolOrder => $toolDef) {
                $exists = $toolModel
                    ->where('module_id', $moduleId)
                    ->where('code', $toolDef['code'])
                    ->first();

                if ($exists) {
                    continue;
                }

                $toolModel->insert([
                    'module_id'     => $moduleId,
                    'code'          => $toolDef['code'],
                    'name'          => $toolDef['name'],
                    'route'         => 'extractions/' . strtolower($moduleDef['code']) . '/' . strtolower($toolDef['code']),
                    'display_order' => $toolOrder,
                ]);
            }
        }
    }
}
