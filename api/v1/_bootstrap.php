<?php
// api/v1/_bootstrap.php
declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

// CORS (ajusta el origin si quieres restringir)
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(204);
  exit;
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';

if (!defined('API_SECRET') || strlen((string)API_SECRET) < 16) {
  http_response_code(500);
  echo json_encode(['success' => false, 'message' => 'API_SECRET no está definido o es muy corto.']);
  exit;
}

require_once __DIR__ . '/_helpers.php';