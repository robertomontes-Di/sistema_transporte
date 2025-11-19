<?php
require_once __DIR__ . '/../../includes/db.php';
$ordenJSON = $_POST['orden'] ?? '[]'; $orden = json_decode($ordenJSON, true);
if(!is_array($orden)){ echo json_encode(['success'=>false]); exit; }
$pdo->beginTransaction();
try{
  $i=1; $stmt = $pdo->prepare('UPDATE paradas SET orden=? WHERE idparada=?');
  foreach($orden as $id){ $stmt->execute([$i,intval($id)]); $i++; }
  $pdo->commit(); echo json_encode(['success'=>true]);
}catch(Exception $e){ $pdo->rollBack(); echo json_encode(['success'=>false,'error'=>$e->getMessage()]); }
?>