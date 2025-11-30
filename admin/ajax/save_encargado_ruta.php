<?php
// admin/ajax/save_encargado_ruta.php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/config.php';

try {
    $id        = isset($_POST['idencargado_ruta']) ? (int)$_POST['idencargado_ruta'] : 0;
    $nombre    = trim($_POST['nombre']   ?? '');
    $telefono  = trim($_POST['telefono'] ?? '');

    if ($nombre === '' || $telefono === '') {
        echo json_encode([
            'success' => false,
            'error'   => 'Nombre y teléfono son obligatorios.'
        ]);
        exit;
    }

    if ($id > 0) {
        // UPDATE
        $sql = "
            UPDATE encargado_ruta
            SET nombre = :nombre,
                telefono = :telefono
            WHERE idencargado_ruta = :id
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':nombre'   => $nombre,
            ':telefono' => $telefono,
            ':id'       => $id,
        ]);
    } else {
        // INSERT
        $sql = "
            INSERT INTO encargado_ruta (nombre, telefono)
            VALUES (:nombre, :telefono)
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':nombre'   => $nombre,
            ':telefono' => $telefono,
        ]);
        $id = (int)$pdo->lastInsertId();
    }

    echo json_encode([
        'success' => true,
        'idencargado_ruta' => $id
    ]);
} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage()
    ]);
}
exit;