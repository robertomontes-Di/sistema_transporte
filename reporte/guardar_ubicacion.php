<?php
// reporte/guardar_ubicacion.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/db.php'; // conexión PDO

try {
    // Leer parámetros
    $lat    = isset($_POST['lat'])    ? trim($_POST['lat'])    : null;
    $lng    = isset($_POST['lng'])    ? trim($_POST['lng'])    : null;
    $idruta = isset($_POST['idruta']) ? (int)$_POST['idruta']  : 0;

    // Validaciones básicas
    if ($idruta <= 0) {
        throw new Exception("Ruta inválida");
    }

    // Ojo: usamos comparación estricta contra null, no `!$lat`
    if ($lat === null || $lat === '' || $lng === null || $lng === '') {
        throw new Exception("Faltan coordenadas");
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
} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'msg'     => $e->getMessage(),
    ]);
}
?>