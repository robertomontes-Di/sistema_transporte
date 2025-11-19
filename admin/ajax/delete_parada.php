<?php
require_once "../includes/db.php";
$idparada = intval($_POST['idparada'] ?? 0);
if(!$idparada){ echo json_encode(['success'=>false]); exit; }
$stmt = $pdo->prepare('DELETE FROM paradas WHERE idparada=?'); $stmt->execute([$idparada]); echo json_encode(['success'=>true]);
?>