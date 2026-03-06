<?php
// api/v1/acciones.php
require_once __DIR__ . '/_bootstrap.php';
require_auth();

$tipo = isset($_GET['tipo']) ? trim((string)$_GET['tipo']) : '';
$flagArrival = isset($_GET['flag_arrival']) ? (int)$_GET['flag_arrival'] : null;

$where = [];
$params = [];

if ($tipo !== '') { $where[] = "tipo_accion = :tipo"; $params[':tipo'] = $tipo; }
if ($flagArrival !== null && ($flagArrival === 0 || $flagArrival === 1)) {
  $where[] = "flag_arrival = :fa"; $params[':fa'] = $flagArrival;
}

$ordenCol = db_has_column($pdo, 'acciones', 'orden') ? 'orden' : 'nombre';

$sql = "SELECT idaccion, nombre, tipo_accion, flag_arrival" .
       (db_has_column($pdo, 'acciones', 'orden') ? ", orden" : "") .
       " FROM acciones " .
       (count($where) ? " WHERE " . implode(" AND ", $where) : "") .
       " ORDER BY $ordenCol ASC, nombre ASC";

$st = $pdo->prepare($sql);
$st->execute($params);
$list = $st->fetchAll(PDO::FETCH_ASSOC);

foreach ($list as &$a) {
  $n = mb_strtolower((string)$a['nombre'], 'UTF-8');
  $a['requiere_personas'] =
      (strpos($n, 'abordaje de personas') !== false) ||
      (strpos($n, 'salida del punto de inicio') !== false);
}
unset($a);

out(['success' => true, 'acciones' => $list]);