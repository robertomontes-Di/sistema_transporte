<?php
require_once __DIR__ . "/../includes/db.php"; // tu conexión PDO

header('Content-Type: application/json; charset=utf-8');

try {
    $lat    = $_POST['lat']    ?? null;
    $lng    = $_POST['lng']    ?? null;
    $idruta = $_POST['idruta'] ?? null;

    if (!$lat || !$lng) {
        throw new Exception("Faltan coordenadas.");
    }

    if (empty($idruta) || !ctype_digit((string)$idruta)) {
        throw new Exception("Ruta inválida.");
    }

    $idruta = (int)$idruta;

    // 1) Verificar que exista la acción "Salida hacia el estadio"
    $sqlAccion = "SELECT idaccion FROM acciones WHERE nombre = 'Salida hacia el estadio' LIMIT 1";
    $idAccionSalida = $pdo->query($sqlAccion)->fetchColumn();

    if (!$idAccionSalida) {
        throw new Exception("No está configurada la acción 'Salida hacia el estadio'.");
    }

    // 2) Verificar que YA exista al menos un reporte de esa acción hoy para esa ruta
    $sqlCheck = "
        SELECT COUNT(*) 
        FROM reporte 
        WHERE idruta = :idruta
          AND idaccion = :idaccion
          AND DATE(fecha_reporte) = CURRENT_DATE
    ";
    $stmtCheck = $pdo->prepare($sqlCheck);
    $stmtCheck->execute([
        ':idruta'   => $idruta,
        ':idaccion' => $idAccionSalida
    ]);

    $tienePrimerReporte = (int)$stmtCheck->fetchColumn() > 0;
    if (!$tienePrimerReporte) {
        throw new Exception("No se ha registrado aún la 'Salida hacia el estadio' para esta ruta hoy.");
    }

    // 3) Guardar SOLO la ubicación en la tabla ubicaciones
    $sql = "INSERT INTO ubicaciones (lat, lng, idruta)
            VALUES (:lat, :lng, :idruta)";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':lat'    => $lat,
        ':lng'    => $lng,
        ':idruta' => $idruta
    ]);

    echo json_encode([
        'success' => true,
        'msg'     => 'Ubicación guardada correctamente'
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'msg'     => $e->getMessage()
    ]);
}
