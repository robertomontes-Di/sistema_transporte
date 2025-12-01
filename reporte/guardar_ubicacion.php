<?php
// reporte/guardar_ubicacion.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/db.php'; // conexión PDO

// Aseguramos que sea POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'success' => false,
        'msg'     => 'Método no permitido'
    ]);
    exit;
}

try {
    // Leer parámetros
    $latRaw    = isset($_POST['lat'])    ? trim($_POST['lat'])    : null;
    $lngRaw    = isset($_POST['lng'])    ? trim($_POST['lng'])    : null;
    $idruta    = isset($_POST['idruta']) ? (int)$_POST['idruta']  : 0;

    // Validaciones básicas
    if ($idruta <= 0) {
        throw new Exception("Ruta inválida (idruta = {$idruta})");
    }

    if ($latRaw === null || $latRaw === '' || $lngRaw === null || $lngRaw === '') {
        throw new Exception("Faltan coordenadas (lat/lng vacíos)");
    }

    // Convertir a float para evitar problemas de formato
    $lat = (float)$latRaw;
    $lng = (float)$lngRaw;

    // Validar rango geográfico básico
    if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
        throw new Exception("Coordenadas fuera de rango: lat={$lat}, lng={$lng}");
    }

    // Insert
    $sql = "INSERT INTO ubicaciones (idruta, lat, lng)
            VALUES (:idruta, :lat, :lng)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':idruta' => $idruta,
        ':lat'    => $lat,
        ':lng'    => $lng,
    ]);

    echo json_encode([
        'success' => true,
        'msg'     => 'Ubicación guardada correctamente',
    ]);
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'msg'     => 'Error DB: '.$e->getMessage(),
    ]);
} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'msg'     => 'Error PHP: '.$e->getMessage(),
    ]);
}
