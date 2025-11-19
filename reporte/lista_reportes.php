<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);


require_once "../includes/db.php";

if (!isset($_GET['idruta']) || empty($_GET['idruta'])) {
    die("Ruta no válida.");
}

$idruta = intval($_GET['idruta']);

/* Obtener nombre de la ruta */
$sql_ruta = "SELECT nombre FROM ruta WHERE idruta = ?";
$stmt = $pdo->prepare($sql_ruta);
$stmt->execute([$idruta]);
$ruta = $stmt->fetchColumn();

if (!$ruta) {
    die("Ruta no encontrada.");
}

/* Obtener los reportes */
$sql = "
    SELECT r.idreporte,
           r.fecha_reporte AS fecha,
           r.total_personas,
           r.comentario,
           a.nombre AS accion,
           p.punto_abordaje AS parada
    FROM reporte r
    LEFT JOIN acciones a ON a.idaccion = r.idaccion
    LEFT JOIN paradas p ON p.idparada = r.idparada
    WHERE r.idruta = ?
    ORDER BY r.fecha_reporte DESC, r.idreporte DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute([$idruta]);
$reportes = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Reportes - <?php echo htmlspecialchars($ruta); ?></title>
    <style>
        body { font-family: Arial; padding: 20px; }
        h2 { margin-bottom: 20px; }
        table { border-collapse: collapse; width: 100%; margin-top:20px; }
        table, th, td { border: 1px solid #ccc; }
        th, td { padding: 10px; text-align: left; }
        th { background-color: #f4f4f4; }
        .btn {
            padding: 8px 15px;
            background: #4CAF50;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            margin-right: 10px;
        }
        .btn-secondary {
            background: #2196F3;
        }
    </style>
</head>
<body>

<h2>Reportes para la Ruta: <strong><?php echo htmlspecialchars($ruta); ?></strong></h2>

<div>
    <a href="index.php?idruta=<?php echo $idruta; ?>" class="btn">
        Crear Nuevo Reporte
    </a>

    <a href="../reporte/index.php" class="btn btn-secondary">
        Volver al Inicio
    </a>
</div>

<?php if (empty($reportes)): ?>
    <p>No hay reportes registrados para esta ruta.</p>
<?php else: ?>
<table>
    <thead>
        <tr>
            <th>ID</th>
            <th>Fecha</th>
            <th>Parada</th>
            <th>Acción</th>
            <th>Personas</th>
            <th>Comentario</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($reportes as $r): ?>
        <tr>
            <td><?php echo $r['idreporte']; ?></td>
            <td><?php echo $r['fecha']; ?></td>
            <td><?php echo htmlspecialchars($r['parada'] ?? ''); ?></td>
            <td><?php echo htmlspecialchars($r['accion'] ?? ''); ?></td>
            <td><?php echo $r['total_personas']; ?></td>
            <td><?php echo htmlspecialchars($r['comentario']); ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

</body>
</html>
