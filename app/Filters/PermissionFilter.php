<?php

namespace App\Filters;

use App\Services\AuthorizationService;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Permission-based route guard.
 *
 * Usage in Config\Routes:
 *   $routes->get('admin/users', '...', ['filter' => 'permission:USER_VIEW']);
 *
 * Several permissions may be given (comma = OR):
 *   ['filter' => 'permission:USER_VIEW,ROLE_VIEW']
 *
 * Not authenticated  -> redirect to /login
 * Authenticated, missing permission -> 403 page
 */
class PermissionFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $session = session();

        if (! $session->get('isLoggedIn')) {
            $session->setFlashdata('error', 'Veuillez vous connecter pour accéder à cette page.');

            return redirect()->to(site_url('login'));
        }

        $required = array_filter(array_map('trim', (array) ($arguments ?? [])));
        if ($required === []) {
            return null; // no permission named -> auth is enough
        }

        if (AuthorizationService::fromSession()->canAny($required)) {
            return null;
        }

        log_message('notice', 'Acces refuse: "{user}" sur {uri} (permission requise: {perm})', [
            'user' => (string) $session->get('username'),
            'uri'  => $request->getUri()->getPath(),
            'perm' => implode('|', $required),
        ]);

        return service('response')
            ->setStatusCode(403)
            ->setBody(view('errors/html/error_403', ['permission' => implode(', ', $required)]));
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // nothing
    }
}
