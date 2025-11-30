<?php
// admin/ajax/delete_encargado_ruta.php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/config.php';

try {
    $id = isset($_POST['idencargado_ruta']) ? (int)$_POST['idencargado_ruta'] : 0;

    if ($id <= 0) {
        echo json_encode([
            'success' => false,
            'error'   => 'ID inválido.'
        ]);
        exit;
    }

    $sql = "DELETE FROM encargado_ruta WHERE idencargado_ruta = :id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id' => $id]);

    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    // Si hay FK con ruta, aquí va a lanzar error de integridad
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage()
    ]);
}
exit;