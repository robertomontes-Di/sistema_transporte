<?php
require_once __DIR__ . '/../../includes/db.php';
$idruta = intval($_GET['idruta'] ?? 0);
if(!$idruta){ echo json_encode(['success'=>false]); exit; }
$r = $pdo->prepare('SELECT * FROM ruta WHERE idruta=?'); $r->execute([$idruta]); $ruta = $r->fetch();
$ps = $pdo->prepare('SELECT * FROM paradas WHERE idruta=? ORDER BY orden ASC, idparada ASC'); $ps->execute([$idruta]); $paradas = $ps->fetchAll();
echo json_encode(['success'=>true,'ruta'=>$ruta,'paradas'=>$paradas]);
?>