<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */

$routes->get('/', 'DashboardController::index');

$routes->get('login', 'AuthController::showLogin');
$routes->post('login', 'AuthController::attemptLogin');
$routes->get('logout', 'AuthController::logout');

// ---- CUSTOMERS_LIST analytics dashboard (live Oracle) -------------------
$routes->get('dashboard', 'DashboardController::index');
$routes->get('dashboard/stats', 'DashboardController::stats');
$routes->get('dashboard/rows', 'DashboardController::rows');
$routes->get('dashboard/filter-options', 'DashboardController::filterOptions');
$routes->post('dashboard/export', 'DashboardController::export');
$routes->get('dashboard/export/download', 'DashboardController::downloadSync');

// ---- Asynchronous export jobs -----------------------------------------
$routes->get('exports/(:num)', 'ExportJobController::show/$1');
$routes->get('exports/(:num)/download', 'ExportJobController::download/$1');

$routes->get('extractor', 'ExtractorController::index');

$routes->get('extractions/(:segment)/(:segment)', 'ExtractionController::show/$1/$2');
$routes->post('extractions/(:segment)/(:segment)', 'ExtractionController::execute/$1/$2');

$routes->group('admin', static function (RouteCollection $routes) {
    $routes->get('oracle', 'Admin\OracleController::index');
    $routes->post('oracle/test', 'Admin\OracleController::test');
});
