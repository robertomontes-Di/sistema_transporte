<?php
// api/v1/auth_login.php
require_once __DIR__ . '/_bootstrap.php';

$data = json_in();
$idruta = isset($data['idruta']) ? (int)$data['idruta'] : 0;

if ($idruta <= 0) out(['success' => false, 'message' => 'idruta requerido'], 400);

// Validar que la ruta exista
$st = $pdo->prepare("SELECT idruta, nombre, destino FROM ruta WHERE idruta = :idruta LIMIT 1");
$st->execute([':idruta' => $idruta]);
$r = $st->fetch(PDO::FETCH_ASSOC);
if (!$r) out(['success' => false, 'message' => 'Ruta no encontrada'], 404);

$token = sign_token(['idruta' => $idruta]);

out([
  'success' => true,
  'token' => $token,
  'ruta' => $r
]);