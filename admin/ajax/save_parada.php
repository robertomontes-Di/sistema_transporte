<?php
require_once __DIR__ . '/../../includes/db.php';
$idparada = intval($_POST['idparada'] ?? 0);
$idruta = intval($_POST['idruta'] ?? 0);
$punto = trim($_POST['punto'] ?? '');
$ha = $_POST['horaAbordaje'] ?: null;
$hs = $_POST['horaSalida'] ?: null;
$lat = $_POST['lat'] ?? null;
$lng = $_POST['lng'] ?? null;
$depto = trim($_POST['depto'] ?? '');
$mun = trim($_POST['mun'] ?? '');
$est = intval($_POST['estimado'] ?? 0);

if(!$idruta || !$punto || $lat===null || $lng===null){ echo json_encode(['success'=>false,'error'=>'Faltan campos']); exit; }

if($idparada>0){
  $stmt = $pdo->prepare('UPDATE paradas SET punto_abordaje=?, hora_abordaje=?, hora_salida=?, latitud=?, longitud=?, departamento=?, municipio=?, estimado_personas=? WHERE idparada=?');
  $stmt->execute([$punto,$ha,$hs,$lat,$lng,$depto,$mun,$est,$idparada]);
} else {
  $next = $pdo->prepare('SELECT COALESCE(MAX(orden),0)+1 FROM paradas WHERE idruta=?'); $next->execute([$idruta]); $ord = $next->fetchColumn();
  $stmt = $pdo->prepare('INSERT INTO paradas (idruta,punto_abordaje,hora_abordaje,hora_salida,latitud,longitud,departamento,municipio,estimado_personas,orden) VALUES (?,?,?,?,?,?,?,?,?,?)');
  $stmt->execute([$idruta,$punto,$ha,$hs,$lat,$lng,$depto,$mun,$est,$ord]);
}

$ps = $pdo->prepare('SELECT * FROM paradas WHERE idruta=? ORDER BY orden ASC, idparada ASC'); $ps->execute([$idruta]); $paradas = $ps->fetchAll();
echo json_encode(['success'=>true,'paradas'=>$paradas]);
?>