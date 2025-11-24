<?php
// reporte/estado.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

if (empty($_SESSION['idruta'])) {
    echo json_encode([
        'success' => false,
        'error'   => 'Sesión inválida.',
    ]);
    exit;
}

$idruta     = (int)$_SESSION['idruta'];
$rutaNombre = $_SESSION['ruta_nombre'] ?? null;

// ¿Bus configurado?
$busConfigurado = false;
try {
    $stmt = $pdo->prepare("
        SELECT 1
        FROM ruta_config_bus
        WHERE idruta = :idruta
        LIMIT 1
    ");
    $stmt->execute([':idruta' => $idruta]);
    $busConfigurado = (bool)$stmt->fetchColumn();
} catch (Throwable $e) {
    $busConfigurado = false;
}

// idaccion “Salida hacia el estadio”
$idAccionSalida = null;
try {
    $stmt = $pdo->prepare("
        SELECT idaccion
        FROM acciones
        WHERE nombre = 'Salida hacia el estadio'
        LIMIT 1
    ");
    $stmt->execute();
    $idAccionSalida = $stmt->fetchColumn();
} catch (Throwable $e) {
    $idAccionSalida = null;
}

// ¿Primer reporte ya existe hoy?
$tienePrimerReporte = false;
if ($idAccionSalida) {
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM reporte
            WHERE idruta = :idruta
              AND idaccion = :idaccion
              AND DATE(fecha_reporte) = CURRENT_DATE
        ");
        $stmt->execute([
            ':idruta'   => $idruta,
            ':idaccion' => $idAccionSalida
        ]);
        $tienePrimerReporte = ((int)$stmt->fetchColumn() > 0);
    } catch (Throwable $e) {
        $tienePrimerReporte = false;
    }
}

echo json_encode([
    'success'             => true,
    'idruta'              => $idruta,
    'ruta_nombre'         => $rutaNombre,
    'bus_configurado'     => $busConfigurado,
    'tiene_primer_reporte'=> $tienePrimerReporte,
]);
