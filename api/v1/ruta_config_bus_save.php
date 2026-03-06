<?php
// api/v1/ruta_config_bus_save.php
require_once __DIR__ . '/_bootstrap.php';
$auth = require_auth();

$data = json_in();
$idruta = isset($data['idruta']) ? (int)$data['idruta'] : (int)($auth['idruta'] ?? 0);

$nombre = trim((string)($data['nombre_motorista'] ?? ''));
$tel = trim((string)($data['telefono_motorista'] ?? ''));
$cap = isset($data['capacidad_aprox']) ? (int)$data['capacidad_aprox'] : null;
$placa = trim((string)($data['placa'] ?? ''));

if ($idruta <= 0) out(['success' => false, 'message' => 'idruta requerido'], 400);
if ($nombre === '' || $tel === '') out(['success' => false, 'message' => 'nombre_motorista y telefono_motorista requeridos'], 400);

$sql = "
INSERT INTO ruta_config_bus (idruta, nombre_motorista, telefono_motorista, capacidad_aprox, placa)
VALUES (:idruta, :nombre, :tel, :cap, :placa)
ON DUPLICATE KEY UPDATE
  nombre_motorista = VALUES(nombre_motorista),
  telefono_motorista = VALUES(telefono_motorista),
  capacidad_aprox = VALUES(capacidad_aprox),
  placa = VALUES(placa),
  fecha_actualizado = CURRENT_TIMESTAMP
";
$st = $pdo->prepare($sql);
$st->execute([
  ':idruta' => $idruta,
  ':nombre' => $nombre,
  ':tel' => $tel,
  ':cap' => $cap,
  ':placa' => $placa
]);

out(['success' => true, 'message' => 'Datos del autobús actualizados.']);