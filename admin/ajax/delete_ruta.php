<?php
require_once __DIR__ . '/../../includes/db.php';
$idruta = intval($_POST['idruta'] ?? $_POST['id'] ?? 0);
if(!$idruta){ echo json_encode(['success'=>false]); exit; }
$pdo->beginTransaction();
try{
  $pdo->prepare('DELETE FROM paradas WHERE idruta=?')->execute([$idruta]);
  $pdo->prepare('DELETE FROM ruta WHERE idruta=?')->execute([$idruta]);
  $pdo->commit(); echo json_encode(['success'=>true]);
}catch(Exception $e){ $pdo->rollBack(); echo json_encode(['success'=>false,'error'=>$e->getMessage()]); }
?>