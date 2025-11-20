<?php
require_once __DIR__ . '/../../includes/db.php';
$idparada = intval($_GET['idparada'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM paradas WHERE idparada=?'); $stmt->execute([$idparada]); $p = $stmt->fetch();
if($p) echo json_encode(['success'=>true,'parada'=>$p]); else echo json_encode(['success'=>false]);
?>