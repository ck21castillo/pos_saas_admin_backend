<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Middleware/requireAdmin.php';

use Dotenv\Dotenv;
use PosAdmin\Core\Response;
use PosAdmin\Controller\HealthController;
use PosAdmin\Controller\AdminAuthController;
use PosAdmin\Controller\AdminEmpresaController;
use PosAdmin\Controller\AdminEmpresaUsuarioController;
use PosAdmin\Controller\AdminEmpresaModuloController;
use PosAdmin\Controller\AdminEmpresaPermisoController;
use PosAdmin\Controller\AdminEmpresaConfiguracionController;
use PosAdmin\Controller\AdminOnboardingController;
use PosAdmin\Controller\AdminHelpController;
use PosAdmin\Controller\AdminNotificationController;
use PosAdmin\Controller\LandingAnalyticsController;
use function PosAdmin\Middleware\requireAdmin as requireAdminMiddleware;



$dotenv = Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

$route  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// ======= Normalizar ruta quitando /pos_saas_admin/public =======
$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
if ($scriptDir !== '' && $scriptDir !== '/' && strpos($route, $scriptDir) === 0) {
  $route = substr($route, strlen($scriptDir));
}
$route = $route === '' ? '/' : $route;

// ====================== CORS (básico por ahora) ======================
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');

// ⚠️ OJO: con cookies HttpOnly + navegador, NO puede ser "*" con credentials.
// Puedes ampliar orígenes con CORS_ALLOWED_ORIGINS="https://dominio1.com,https://dominio2.com"
$allowed = [
  'http://localhost:5173',
  'http://localhost:5174',
  'http://localhost:5175',
  'https://bersanopos.com',
  'https://www.bersanopos.com',
];
$extraAllowed = array_filter(
  array_map(
    static fn($v) => trim((string)$v),
    explode(',', (string)($_ENV['CORS_ALLOWED_ORIGINS'] ?? ''))
  ),
  static fn($v) => $v !== ''
);
if (!empty($extraAllowed)) {
  $allowed = array_values(array_unique(array_merge($allowed, $extraAllowed)));
}
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin && in_array($origin, $allowed, true)) {
  header("Access-Control-Allow-Origin: $origin");
  header('Vary: Origin'); // recomendado
}

if ($method === 'OPTIONS') {
  http_response_code(204);
  exit;
}

// ====================== Helpers ======================

/**
 * Auth del SuperAdmin (estilo POS): valida cookie JWT + estado en BD,
 * e inyecta adminId/adminEmail en $_REQUEST.
 */
function requireAdmin(): void
{
  $admin = requireAdminMiddleware();
  $_REQUEST['adminId'] = (int)$admin['id_superadmin'];
  $_REQUEST['adminEmail'] = (string)$admin['email'];
}

// ====================== Body JSON ======================
$body = [];
if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
  $raw = (string)file_get_contents('php://input');
  $decoded = json_decode($raw, true);
  if (is_array($decoded)) {
    $body = $decoded;
  }
}

// ====================== ROUTES ======================

// Health
if ($route === '/health' && $method === 'GET') {
  (new HealthController())->ping();
  exit;
}

// Admin Auth (no requiere requireAdmin)
if ($route === '/admin/auth/login' && $method === 'POST') {
  (new AdminAuthController())->login($body);
  exit;
}
if ($route === '/admin/auth/me' && $method === 'GET') {
  (new AdminAuthController())->me();
  exit;
}
if ($route === '/admin/auth/logout' && $method === 'POST') {
  (new AdminAuthController())->logout();
  exit;
}

// Tracking público de visitas landing (sin auth)
if ($route === '/analytics/landing-visit' && $method === 'POST') {
  (new LandingAnalyticsController())->ingestVisit();
  exit;
}

// Empresas (PROTEGIDO)
if ($route === '/onboarding/requests' && $method === 'GET') {
  requireAdmin();
  (new AdminOnboardingController())->listRequests();
  exit;
}

// PATCH /onboarding/requests/{id}
if (preg_match('#^/onboarding/requests/(\d+)$#', $route, $m) && $method === 'PATCH') {
  requireAdmin();
  (new AdminOnboardingController())->updateRequest(['id' => (int)$m[1]]);
  exit;
}

// POST /onboarding/invitations
if ($route === '/onboarding/invitations' && $method === 'POST') {
  requireAdmin();
  (new AdminOnboardingController())->createInvitation(); // <-- sin $body
  exit;
}

// GET /onboarding/invitations?email=...
if ($route === '/onboarding/invitations' && $method === 'GET') {
  requireAdmin();
  (new AdminOnboardingController())->listInvitations();
  exit;
}

if ($route === '/admin/empresas' && $method === 'GET') {
  requireAdmin();
  (new AdminEmpresaController())->list();
  exit;
}

if (preg_match('#^/admin/empresas/(\d+)/estado$#', $route, $m) && $method === 'PATCH') {
  requireAdmin();
  (new AdminEmpresaController())->setEstado((int)$m[1], $body);
  exit;
}

// GET /admin/empresas/{id}/configuracion-negocio
if (preg_match('#^/admin/empresas/(\d+)/configuracion-negocio$#', $route, $m) && $method === 'GET') {
  requireAdmin();
  (new AdminEmpresaConfiguracionController())->show((int)$m[1]);
  exit;
}

// PUT /admin/empresas/{id}/configuracion-negocio
if (preg_match('#^/admin/empresas/(\d+)/configuracion-negocio$#', $route, $m) && $method === 'PUT') {
  requireAdmin();
  (new AdminEmpresaConfiguracionController())->save((int)$m[1], $body);
  exit;
}

// GET /admin/empresas/{id}/modulos
if (preg_match('#^/admin/empresas/(\d+)/modulos$#', $route, $m) && $method === 'GET') {
  requireAdmin();
  (new AdminEmpresaModuloController())->list((int)$m[1]);
  exit;
}

// GET /admin/empresas/{id}/usuarios
if (preg_match('#^/admin/empresas/(\d+)/usuarios$#', $route, $m) && $method === 'GET') {
  requireAdmin();
  (new AdminEmpresaUsuarioController())->list((int)$m[1]);
  exit;
}

// GET /admin/empresas/{id}/usuario-admin
if (preg_match('#^/admin/empresas/(\d+)/usuario-admin$#', $route, $m) && $method === 'GET') {
  requireAdmin();
  (new AdminEmpresaUsuarioController())->adminUser((int)$m[1]);
  exit;
}

// PUT /admin/empresas/{id}/modulos
if (preg_match('#^/admin/empresas/(\d+)/modulos$#', $route, $m) && $method === 'PUT') {
  requireAdmin();
  (new AdminEmpresaModuloController())->save((int)$m[1], $body);
  exit;
}

if (preg_match('#^/admin/empresas/(\d+)/permisos$#', $route, $m) && $method === 'GET') {
  requireAdmin();
  (new AdminEmpresaPermisoController())->list((int)$m[1]);
  exit;
}

if (preg_match('#^/admin/empresas/(\d+)/permisos$#', $route, $m) && $method === 'PUT') {
  requireAdmin();
  (new AdminEmpresaPermisoController())->save((int)$m[1], $body);
  exit;
}

if ($route === '/admin/help/tickets' && $method === 'GET') {
  requireAdmin();
  (new AdminHelpController())->listTickets();
  exit;
}

if (preg_match('#^/admin/help/tickets/(\d+)$#', $route, $m) && $method === 'GET') {
  requireAdmin();
  (new AdminHelpController())->showTicket(['id' => (int)$m[1]]);
  exit;
}

if (preg_match('#^/admin/help/tickets/(\d+)/messages$#', $route, $m) && $method === 'POST') {
  requireAdmin();
  (new AdminHelpController())->reply(['id' => (int)$m[1]]);
  exit;
}

if (preg_match('#^/admin/help/tickets/(\d+)/estado$#', $route, $m) && $method === 'PATCH') {
  requireAdmin();
  (new AdminHelpController())->updateEstado(['id' => (int)$m[1]]);
  exit;
}

if ($route === '/admin/notifications' && $method === 'GET') {
  requireAdmin();
  (new AdminNotificationController())->list();
  exit;
}

if ($route === '/admin/notifications' && $method === 'POST') {
  requireAdmin();
  (new AdminNotificationController())->create();
  exit;
}

if (preg_match('#^/admin/notifications/(\d+)$#', $route, $m) && $method === 'PATCH') {
  requireAdmin();
  (new AdminNotificationController())->update(['id' => (int)$m[1]]);
  exit;
}

if (preg_match('#^/admin/notifications/(\d+)/estado$#', $route, $m) && $method === 'PATCH') {
  requireAdmin();
  (new AdminNotificationController())->setEstado(['id' => (int)$m[1]]);
  exit;
}

if ($route === '/admin/analytics/landing-visits' && $method === 'GET') {
  requireAdmin();
  (new LandingAnalyticsController())->summary();
  exit;
}

Response::json(['error' => 'NOT_FOUND', 'route' => $route], 404);
