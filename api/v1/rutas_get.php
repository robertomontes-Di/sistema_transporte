<?php
// api/v1/rutas_get.php
require_once __DIR__ . '/_bootstrap.php';
$auth = require_auth();

$idruta = isset($_GET['idruta']) ? (int)$_GET['idruta'] : (int)($auth['idruta'] ?? 0);
if ($idruta <= 0) out(['success' => false, 'message' => 'idruta requerido'], 400);

$sql = "
SELECT r.idruta,
       r.nombre AS ruta_nombre,
       r.destino,
       r.flag_arrival,
       " . (db_has_column($pdo, 'ruta', 'activa') ? "r.activa," : "0 AS activa,") . "
       b.idbus, b.placa, b.conductor, b.telefono AS telefono_motorista,
       e.idencargado_ruta, e.nombre AS encargado_nombre, e.telefono AS telefono_encargado
FROM ruta r
LEFT JOIN bus b ON r.idbus = b.idbus
LEFT JOIN encargado_ruta e ON r.idencargado_ruta = e.idencargado_ruta
WHERE r.idruta = :idruta
LIMIT 1";

$st = $pdo->prepare($sql);
$st->execute([':idruta' => $idruta]);
$row = $st->fetch(PDO::FETCH_ASSOC);

if (!$row) out(['success' => false, 'message' => 'Ruta no encontrada'], 404);

out([
  'success' => true,
  'ruta' => [
    'idruta' => (int)$row['idruta'],
    'ruta_nombre' => $row['ruta_nombre'],
    'destino' => $row['destino'],
    'activa' => (int)$row['activa'],
    'flag_arrival' => (int)$row['flag_arrival'],
    'encargado' => [
      'id' => (int)$row['idencargado_ruta'],
      'nombre' => $row['encargado_nombre'],
      'telefono' => $row['telefono_encargado'],
    ],
    'bus' => [
      'idbus' => (int)($row['idbus'] ?? 0),
      'placa' => $row['placa'],
      'conductor' => $row['conductor'],
      'telefono' => $row['telefono_motorista'],
    ]
  ]
]);