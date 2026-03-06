<?php
// api/v1/ubicaciones_create.php
require_once __DIR__ . '/_bootstrap.php';
$auth = require_auth();

$data = json_in();
$idruta = isset($data['idruta']) ? (int)$data['idruta'] : (int)($auth['idruta'] ?? 0);

$lat = isset($data['latitud']) ? (float)$data['latitud'] : null;
$lng = isset($data['longitud']) ? (float)$data['longitud'] : null;
$prec = isset($data['precision_gps']) ? (float)$data['precision_gps'] : null;

if ($idruta <= 0) out(['success' => false, 'message' => 'idruta requerido'], 400);
if ($lat === null || $lng === null) out(['success' => false, 'message' => 'latitud y longitud requeridas'], 400);

$st = $pdo->prepare("INSERT INTO ubicaciones (idruta, latitud, longitud, precision_gps)
                     VALUES (:idruta, :lat, :lng, :prec)");
$st->execute([
  ':idruta' => $idruta,
  ':lat' => $lat,
  ':lng' => $lng,
  ':prec' => $prec
]);

out(['success' => true, 'message' => 'Ubicación enviada correctamente.']);