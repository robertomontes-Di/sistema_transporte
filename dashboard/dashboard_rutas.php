<?php
ini_set('display_errors', 0);
error_reporting(0);
// rutas.php - Dashboard de Rutas (Listado general)
require_once __DIR__ . '/../includes/db.php';

try {

    // Consulta rutas con métricas
    $sql = "
    SELECT r.idruta,
           r.nombre,
           r.destino,
           r.flag_arrival,
           b.placa AS bus_codigo,
           er.nombre AS encargado,
           (
               SELECT COUNT(*) FROM reporte rp WHERE rp.idruta = r.idruta
           ) AS total_reportes,
           (
               SELECT COUNT(*) FROM reporte rp WHERE rp.idruta = r.idruta 
               AND rp.critico = 1
           ) AS eventos_criticos,
           (
               SELECT SUM(total_personas) FROM reporte rp WHERE rp.idruta = r.idruta
           ) AS total_personas_reportadas,
           (
               SELECT SUM(estimado_personas) FROM paradas p WHERE p.idruta = r.idruta
           ) AS total_personas_estimadas
    FROM ruta r
    LEFT JOIN bus b ON b.idbus = r.idbus
    LEFT JOIN encargado_ruta er ON er.idencargado_ruta = r.idencargado_ruta
    ORDER BY r.idruta DESC;";

    $stmt = $pdo->query($sql);
    $rutas = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {

    // Mostrar error en pantalla SIEMPRE aunque display errors esté apagado
    echo "<pre style='background:#300;color:#fff;padding:15px;border-radius:6px'>";
    echo "❌ ERROR SQL:\n\n";
    echo $e->getMessage() . "\n\n";
    echo "➡ Código de error: " . $e->getCode() . "\n\n";
    echo "📌 Consulta que falló:\n$sql";
    echo "</pre>";

    exit;
}
?>

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Dashboard de Rutas</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-4">
    <h2 class="mb-4">Dashboard de Rutas</h2>

    <div class="row g-3">
        <?php foreach ($rutas as $r): ?>
            <?php
                // Estado visual
                $estado = "En camino";
                $color = "primary";
                if ($r['eventos_criticos'] > 0) {
                    $estado = "Falla";
                    $color = "danger";
                } else if ($r['flag_arrival'] == 1) {
                    $estado = "Finalizada";
                    $color = "success";
                }

                // Porcentaje avance
                $p_estimado = (int)$r['total_personas_estimadas'];
                $p_real = (int)$r['total_personas_reportadas'];
                $porcentaje = ($p_estimado > 0) ? round(($p_real / $p_estimado) * 100) : 0;
            ?>

            <div class="col-md-4">
                <div class="card shadow-sm">
                    <div class="card-body">
                        <h5 class="card-title">Ruta #<?= $r['idruta'] ?> - <?= htmlspecialchars($r['nombre']) ?></h5>
                        <p class="text-muted mb-1">Destino: <?= htmlspecialchars($r['destino']) ?></p>
                        <p class="mb-1">Bus: <strong><?= htmlspecialchars($r['bus_codigo']) ?></strong></p>
                        <p class="mb-2">Encargado: <?= htmlspecialchars($r['encargado']) ?></p>
                        <span class="badge bg-<?= $color ?>"><?= $estado ?></span>

                        <hr>

                        <p class="mb-1">Reportes: <?= $r['total_reportes'] ?></p>
                        <p class="mb-1 text-danger">Incidentes críticos: <?= $r['eventos_criticos'] ?></p>
                        <p class="mb-1">Personas estimadas: <?= $p_estimado ?></p>
                        <p class="mb-2">Personas reportadas: <?= $p_real ?></p>

                        <div class="progress mb-2">
                            <div class="progress-bar bg-success" role="progressbar"
                                 style="width: <?= $porcentaje ?>%"></div>
                        </div>
                        <small class="text-muted">Avance: <?= $porcentaje ?>%</small>

                        <div class="d-grid mt-3">
                            <a href="detalle_ruta.php?id=<?= $r['idruta'] ?>" class="btn btn-outline-primary">Ver Detalle</a>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
</body>
</html>
