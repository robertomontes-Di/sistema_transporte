<?php
// reporte/guardar_ubicacion.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'success' => false,
        'msg'     => 'Método no permitido'
    ]);
    exit;
}

// La ruta debería venir de la sesión del login del líder
if (empty($_SESSION['idruta'])) {
    echo json_encode([
        'success' => false,
        'msg'     => 'Sesión inválida. Vuelva a iniciar sesión.'
    ]);
    exit;
}

$idruta = (int)$_SESSION['idruta'];

// Coordenadas desde el navegador
$lat = isset($_POST['lat']) ? trim($_POST['lat']) : '';
$lng = isset($_POST['lng']) ? trim($_POST['lng']) : '';

if ($lat === '' || $lng === '') {
    echo json_encode([
        'success' => false,
        'msg'     => 'Coordenadas incompletas.'
    ]);
    exit;
}

if (!is_numeric($lat) || !is_numeric($lng)) {
    echo json_encode([
        'success' => false,
        'msg'     => 'Coordenadas inválidas.'
    ]);
    exit;
}

try {
    $stmt = $pdo->prepare("
        INSERT INTO ubicaciones (idruta, lat, lng, fecha_registro)
        VALUES (:idruta, :lat, :lng, NOW())
    ");
    $stmt->execute([
        ':idruta' => $idruta,
        ':lat'    => $lat,
        ':lng'    => $lng,
    ]);

    echo json_encode([
        'success' => true,
        'msg'     => 'Ubicación guardada correctamente.'
    ]);
} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'msg'     => 'Error al guardar la ubicación.'
    ]);
}
