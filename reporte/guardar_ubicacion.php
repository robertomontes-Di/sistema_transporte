<?php
require_once __DIR__ . "/../includes/db.php"; // tu conexión PDO

try {
    $lat = $_POST['lat'] ?? null;
    $lng = $_POST['lng'] ?? null;
  
    $idruta = $_POST['idruta'] ?? null;

    if (!$lat || !$lng) {
        throw new Exception("Faltan coordenadas");
    }

    $sql = "INSERT INTO ubicaciones (lat, lng,  idruta)
            VALUES (:lat, :lng,  :idruta)";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':lat' => $lat,
        ':lng' => $lng,       
        ':idruta' => $idruta
    ]);

    echo json_encode(['success' => true, 'msg' => 'Ubicación guardada correctamente']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'msg' => $e->getMessage()]);
}
