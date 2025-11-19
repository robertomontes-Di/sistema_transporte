<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../../includes/db.php';

$nombre = trim($_POST['nombre'] ?? '');
$destino = trim($_POST['destino'] ?? '');
$idenc = intval($_POST['idencargado_ruta'] ?? 0);
$idbus = intval($_POST['idbus'] ?? 0);
$idruta = intval($_POST['idruta'] ?? 0);

if(!$nombre || !$destino || !$idenc || !$idbus){
    echo json_encode(['success'=>false,'error'=>'Faltan campos']);
    exit;
}

try {
    if($idruta > 0){
        $stmt = $pdo->prepare('UPDATE ruta SET nombre=?, destino=?, idencargado_ruta=?, idbus=? WHERE idruta=?');
        $stmt->execute([$nombre, $destino, $idenc, $idbus,  $idruta]);
        echo json_encode(['success'=>true,'idruta'=>$idruta]);
    } else {
        $stmt = $pdo->prepare('INSERT INTO ruta (idencargado_ruta, idbus, nombre,  destino) VALUES (?, ?, ?, ?)');
        $stmt->execute([$idenc, $idbus, $nombre, $destino]);
        echo json_encode(['success'=>true,'idruta'=>$pdo->lastInsertId()]);
    }

} catch (PDOException $e) {
    echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
}
