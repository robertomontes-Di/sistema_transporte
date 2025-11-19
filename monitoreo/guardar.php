<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . "/../includes/db.php";

// Read and sanitize inputs
$idruta = isset($_POST['idruta']) ? intval($_POST['idruta']) : 0;
$idparada = isset($_POST['idparada']) ? intval($_POST['idparada']) : 0;
$idaccion = isset($_POST['idaccion']) ? intval($_POST['idaccion']) : 0;
$total_personas = isset($_POST['total_personas']) ? intval($_POST['total_personas']) : 0;
$comentario = isset($_POST['comentario']) ? trim($_POST['comentario']) : '';
$idagente = isset($_POST['idagente']) ? intval($_POST['idagente']) : 0;

// Optional debug flag (use only for local debugging)
$debug = (isset($_GET['debug']) && $_GET['debug'] == '1') || (isset($_POST['debug']) && $_POST['debug'] == '1');

// Basic validation
if ($idruta <= 0 || $idparada <= 0 || $idaccion <= 0 || $idagente <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Faltan campos obligatorios: idruta, idparada, idaccion, idagente'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $sql = "INSERT INTO reporte (idruta, idparada, idaccion, total_personas, comentario, idagente) VALUES (:idruta, :idparada, :idaccion, :total_personas, :comentario, :idagente)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':idruta' => $idruta,
        ':idparada' => $idparada,
        ':idaccion' => $idaccion,
        ':total_personas' => $total_personas,
        ':comentario' => $comentario,
        ':idagente' => $idagente,
    ]);

    $insertId = $pdo->lastInsertId();
    echo json_encode(['success' => true, 'message' => 'Reporte guardado correctamente', 'insert_id' => $insertId], JSON_UNESCAPED_UNICODE);
    exit;

} catch (Exception $e) {
    // Log server-side for debugging
    error_log('guardar.php exception: ' . $e->getMessage());
    http_response_code(500);
    if ($debug) {
        echo json_encode(['success' => false, 'error' => 'Error al guardar el reporte', 'detalle' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(['success' => false, 'error' => 'Error al guardar el reporte'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}
