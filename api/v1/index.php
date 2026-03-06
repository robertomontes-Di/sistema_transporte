<?php
// api/v1/index.php — Router simple
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '';
// Si tu API vive bajo /sistema_transporte/api/v1
$path = preg_replace('#^/sistema_transporte/api/v1#', '', $path);
// Si la usas directo /api/v1, comenta la línea de arriba y usa esta:
// $path = preg_replace('#^/api/v1#', '', $path);

$path = rtrim($path, '/');
if ($path === '') $path = '/';
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

switch (true) {
  case $method === 'POST' && $path === '/auth/login':
    require __DIR__ . '/auth_login.php';
    break;

  case $method === 'GET' && $path === '/acciones':
    require __DIR__ . '/acciones.php';
    break;

  case $method === 'GET' && preg_match('#^/rutas/(\d+)$#', $path, $m):
    $_GET['idruta'] = $m[1];
    require __DIR__ . '/rutas_get.php';
    break;

  case $method === 'POST' && $path === '/reportes':
    require __DIR__ . '/reportes_create.php';
    break;

  case $method === 'POST' && $path === '/ubicaciones':
    require __DIR__ . '/ubicaciones_create.php';
    break;

  case $method === 'POST' && $path === '/ruta-config-bus':
    require __DIR__ . '/ruta_config_bus_save.php';
    break;

  default:
    out(['success' => false, 'message' => 'Endpoint no encontrado', 'path' => $path], 404);
}