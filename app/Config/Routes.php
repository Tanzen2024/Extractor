<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */

$routes->get('/', 'DashboardController::index');

// ---- Authentication (Active Directory) --------------------------------
$routes->get('login', 'AuthController::showLogin');
$routes->post('login', 'AuthController::attemptLogin');
$routes->get('logout', 'AuthController::logout');

// ---- CUSTOMERS_LIST analytics dashboard = the application core --------
$routes->get('dashboard', 'DashboardController::index');
$routes->get('dashboard/stats', 'DashboardController::stats');
$routes->get('dashboard/count', 'DashboardController::count');
$routes->get('dashboard/rows', 'DashboardController::rows');
$routes->get('dashboard/segmentation-counts', 'DashboardController::segmentationCounts');
$routes->get('dashboard/filter-options', 'DashboardController::filterOptions');
$routes->post('dashboard/export', 'DashboardController::export');
$routes->get('dashboard/export/download', 'DashboardController::downloadSync');

// ---- Asynchronous export jobs ---------------------------------------
$routes->get('exports/(:num)', 'ExportJobController::show/$1');
$routes->get('exports/(:num)/download', 'ExportJobController::download/$1');
$routes->post('exports/(:num)/cancel', 'ExportJobController::cancel/$1');

// ---- Extraction back-end (no menu entry; reachable by deep link / tools) ----
$routes->get('extractor', 'ExtractorController::index');
$routes->get('extractions/(:segment)/(:segment)', 'ExtractionController::show/$1/$2');
$routes->post('extractions/(:segment)/(:segment)', 'ExtractionController::execute/$1/$2');

// ---- Administration -------------------------------------------------
// Sous-menus directs de Administration : Utilisateur / Rôles / Permissions / Audit.
// The `permission` filter also enforces authentication (redirects to /login).
$routes->group('admin', static function (RouteCollection $routes): void {

    // Roles  (declared before users/(:num) so "roles" is not caught as an id)
    $routes->get('users/roles', 'Admin\RolesController::index', ['filter' => 'permission:ROLE_VIEW']);
    $routes->get('users/roles/create', 'Admin\RolesController::create', ['filter' => 'permission:ROLE_CREATE']);
    $routes->post('users/roles', 'Admin\RolesController::store', ['filter' => 'permission:ROLE_CREATE']);
    $routes->get('users/roles/(:num)/edit', 'Admin\RolesController::edit/$1', ['filter' => 'permission:ROLE_EDIT']);
    $routes->post('users/roles/(:num)', 'Admin\RolesController::update/$1', ['filter' => 'permission:ROLE_EDIT']);
    $routes->match(['GET', 'POST'], 'users/roles/(:num)/permissions', 'Admin\RolesController::permissions/$1', ['filter' => 'permission:ROLE_EDIT']);

    // Permissions
    $routes->get('users/permissions', 'Admin\PermissionsController::index', ['filter' => 'permission:PERMISSION_VIEW']);
    $routes->post('users/permissions/(:num)/toggle', 'Admin\PermissionsController::toggle/$1', ['filter' => 'permission:PERMISSION_EDIT']);

    // Users
    $routes->get('users', 'Admin\UsersController::index', ['filter' => 'permission:USER_VIEW']);
    $routes->get('users/create', 'Admin\UsersController::create', ['filter' => 'permission:USER_CREATE']);
    $routes->post('users', 'Admin\UsersController::store', ['filter' => 'permission:USER_CREATE']);
    $routes->get('users/(:num)', 'Admin\UsersController::show/$1', ['filter' => 'permission:USER_VIEW']);
    $routes->get('users/(:num)/edit', 'Admin\UsersController::edit/$1', ['filter' => 'permission:USER_EDIT']);
    $routes->post('users/(:num)', 'Admin\UsersController::update/$1', ['filter' => 'permission:USER_EDIT']);
    $routes->post('users/(:num)/disable', 'Admin\UsersController::disable/$1', ['filter' => 'permission:USER_DISABLE']);
    $routes->post('users/(:num)/enable', 'Admin\UsersController::enable/$1', ['filter' => 'permission:USER_DISABLE']);

    // Audit
    $routes->get('audit', 'Admin\AuditController::index', ['filter' => 'permission:AUDIT_VIEW']);
});
