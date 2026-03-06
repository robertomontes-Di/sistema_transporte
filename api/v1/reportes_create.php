<?php
// api/v1/reportes_create.php
require_once __DIR__ . '/_bootstrap.php';
$auth = require_auth();

$data = json_in();

$idruta = isset($data['idruta']) ? (int)$data['idruta'] : (int)($auth['idruta'] ?? 0);
$idaccion = isset($data['idaccion']) ? (int)$data['idaccion'] : 0;
$total = isset($data['total_personas']) ? (int)$data['total_personas'] : 0;
$comentario = isset($data['comentario']) ? trim((string)$data['comentario']) : '';
$idparada = isset($data['idparada']) ? (int)$data['idparada'] : 0;

if ($idruta <= 0) out(['success' => false, 'message' => 'idruta requerido'], 400);
if ($idaccion <= 0) out(['success' => false, 'message' => 'idaccion requerido'], 400);

// Obtener nombre de acción para regla requiere_personas
$st = $pdo->prepare("SELECT nombre FROM acciones WHERE idaccion = :id LIMIT 1");
$st->execute([':id' => $idaccion]);
$accionNombre = (string)$st->fetchColumn();
if ($accionNombre === '') out(['success' => false, 'message' => 'Acción no encontrada'], 404);

$n = mb_strtolower($accionNombre, 'UTF-8');
$requiere = (strpos($n, 'abordaje de personas') !== false) || (strpos($n, 'salida del punto de inicio') !== false);

if ($requiere && $total <= 0) {
  out(['success' => false, 'message' => 'total_personas debe ser > 0 para esta acción'], 400);
}
if (!$requiere) $total = 0;

// Insert reporte
$sql = "INSERT INTO reporte (idruta, total_personas, comentario, idaccion, idparada)
        VALUES (:idruta, :total, :comentario, :idaccion, :idparada)";
$st = $pdo->prepare($sql);
$st->execute([
  ':idruta' => $idruta,
  ':total' => $total,
  ':comentario' => $comentario,
  ':idaccion' => $idaccion,
  ':idparada' => $idparada
]);

$idreporte = (int)$pdo->lastInsertId();

// Activar ruta (si existe columna)
if (db_has_column($pdo, 'ruta', 'activa')) {
  try {
    $pdo->prepare("UPDATE ruta SET activa = 1 WHERE idruta = :idruta")->execute([':idruta' => $idruta]);
  } catch (Throwable $e) {}
}

// Llegada estadio (si tu regla es idaccion=16)
if ($idaccion === 16) {
  try {
    $pdo->prepare("UPDATE ruta SET flag_arrival = 1 WHERE idruta = :idruta")->execute([':idruta' => $idruta]);
  } catch (Throwable $e) {}
}

out([
  'success' => true,
  'idreporte' => $idreporte,
  'message' => 'Reporte registrado correctamente.'
]);