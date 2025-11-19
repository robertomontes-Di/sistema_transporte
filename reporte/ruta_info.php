<?php
header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../includes/db.php";  // conexión PDO

try {
    if (!isset($_GET["idruta"])) {
        http_response_code(400);
        echo json_encode(["error" => "Falta parámetro idruta"], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $idruta = intval($_GET["idruta"]);

    /* ============================================================
       1. OBTENER ENCARGADO DE LA RUTA
    ============================================================ */
    $sqlEnc = "
        SELECT er.nombre, er.telefono
        FROM ruta r
        INNER JOIN encargado_ruta er 
            ON r.idencargado_ruta = er.idencargado_ruta
        WHERE r.idruta = :idruta
    ";
    $stmtEnc = $pdo->prepare($sqlEnc);
    $stmtEnc->execute(["idruta" => $idruta]);
    $encargado = $stmtEnc->fetch(PDO::FETCH_ASSOC);

    /* ============================================================
       2. OBTENER SIGUIENTE PARADA NO ATENDIDA
    ============================================================ */
    $sqlParada = "
        SELECT idparada, punto_abordaje
        FROM paradas
        WHERE idruta = :idruta AND atendido = 0
        ORDER BY idparada ASC
        LIMIT 1
    ";
    $stmtPar = $pdo->prepare($sqlParada);
    $stmtPar->execute(["idruta" => $idruta]);
    $parada = $stmtPar->fetch(PDO::FETCH_ASSOC);

    /* ============================================================
       3. OBTENER ACCIONES DISPONIBLES PARA LA RUTA
    ============================================================ */
    $sqlAcc = "
        SELECT idaccion, nombre
        FROM acciones
        WHERE flag_arrival = 0
        ORDER BY nombre ASC
    ";
    $stmtAcc = $pdo->prepare($sqlAcc);
    // SQL no usa :idruta, por eso no pasamos parámetros aquí
    $stmtAcc->execute();
    $acciones = $stmtAcc->fetchAll(PDO::FETCH_ASSOC);

    /* ============================================================
       4. RESPUESTA JSON
    ============================================================ */
    echo json_encode([
        "encargado" => $encargado ?: null,
        "parada"    => $parada ?: null,
        "acciones"  => $acciones
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    // Log internal error
    error_log("ruta_info.php error: " . $e->getMessage());
    http_response_code(500);

    // If debug=1 is present in the query string, return detailed error (temporary)
    if (isset($_GET['debug']) && $_GET['debug'] == '1') {
        echo json_encode([
            "error" => "Error interno en ruta_info.php",
            "detalle" => $e->getMessage(),
            "trace" => $e->getTraceAsString()
        ], JSON_UNESCAPED_UNICODE);
    } else {
        // Generic message for normal operation
        echo json_encode([
            "error" => "Error interno en ruta_info.php"
        ], JSON_UNESCAPED_UNICODE);
    }
}
