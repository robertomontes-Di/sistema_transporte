<?php
ini_set('display_errors', 0);
error_reporting(0);
// detalle_ruta.php - Detalle de una ruta específica
require_once __DIR__ . '/../includes/db.php';

try {
$idruta = intval($_GET['id'] ?? 0);
if ($idruta <= 0) {
    die("Ruta inválida");
}

// ==========================
// 1. Obtener datos generales
// ==========================

$sqlRuta = "
SELECT r.idruta, r.nombre, r.destino, r.flag_arrival,
       b.placa AS bus_codigo,
       er.nombre AS encargado
FROM ruta r
LEFT JOIN bus b ON b.idbus = r.idbus
LEFT JOIN encargado_ruta er ON er.idencargado_ruta = r.idencargado_ruta
WHERE r.idruta = :idruta";

$stmtRuta = $pdo->prepare($sqlRuta);
$stmtRuta->execute(['idruta' => $idruta]);
$ruta = $stmtRuta->fetch(PDO::FETCH_ASSOC);

if (!$ruta) {
    die("Ruta no encontrada");
}


// ==========================
// 2. Obtener paradas ordenadas
// ==========================
$sqlParadas = "
SELECT idparada, punto_abordaje, latitud, longitud, hora_abordaje,
       hora_salida, estimado_personas, atendido, orden
FROM paradas
WHERE idruta = :idruta
ORDER BY orden ASC";

$stmt = $pdo->prepare($sqlParadas);
$stmt->execute(['idruta' => $idruta]);
$paradas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ==========================
// 3. Reportes
// ==========================
$sqlReportes = "
SELECT rp.*, ac.nombre AS accion
FROM reporte rp
LEFT JOIN acciones ac ON ac.idaccion = rp.idaccion
WHERE rp.idruta = :idruta
ORDER BY rp.fecha_reporte DESC";

$stmtR = $pdo->prepare($sqlReportes);
$stmtR->execute(['idruta' => $idruta]);
$reportes = $stmtR->fetchAll(PDO::FETCH_ASSOC);
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
// ==========================
// 4. Cálculos
// ==========================
$totalEstimado = array_sum(array_column($paradas, 'estimado_personas'));
$totalReportado = 0;
foreach ($reportes as $r) {
    $totalReportado += intval($r['total_personas']);
}
$avance = ($totalEstimado > 0) ? round(($totalReportado / $totalEstimado) * 100) : 0;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Detalle Ruta <?= $ruta['nombre'] ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://maps.googleapis.com/maps/api/js?key=AIzaSyC2Zm7v7BAdKaA-KAvna0q4y0lQgwvE1V4&libraries=geometry"></script>
</head>
<body class="bg-light">
<div class="container py-4">
    <h3>Detalle de Ruta: <?= htmlspecialchars($ruta['nombre']) ?></h3>
    <p class="text-muted">Destino: <?= htmlspecialchars($ruta['destino']) ?></p>

    <!-- ======================== -->
    <!--   MÉTRICAS DE LA RUTA   -->
    <!-- ======================== -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card text-center shadow-sm">
                <div class="card-body">
                    <h5 class="card-title">Personas estimadas</h5>
                    <h3><?= $totalEstimado ?></h3>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card text-center shadow-sm">
                <div class="card-body">
                    <h5 class="card-title">Personas reportadas</h5>
                    <h3><?= $totalReportado ?></h3>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card text-center shadow-sm">
                <div class="card-body">
                    <h5 class="card-title">Avance</h5>
                    <h3><?= $avance ?>%</h3>
                    <div class="progress">
                        <div class="progress-bar bg-success" style="width: <?= $avance ?>%"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ================ -->
    <!--     MAPA         -->
    <!-- ================ -->
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <h5 class="card-title">Mapa de recorrido</h5>
            <div id="map" style="height: 400px"></div>
        </div>
    </div>

    <!-- ====================== -->
    <!--  TIMELINE DE PARADAS  -->
    <!-- ====================== -->
    <div class="card mb-4 shadow-sm">
        <div class="card-body">
            <h5 class="card-title">Paradas</h5>

            <table class="table table-striped">
                <thead>
                <tr>
                    <th>#</th>
                    <th>Punto</th>
                    <th>Hora Aboardaje</th>
                    <th>Hora Salida</th>
                    <th>Estimado</th>
                    <th>Atendido</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($paradas as $p): ?>
                    <tr>
                        <td><?= $p['orden'] ?></td>
                        <td><?= htmlspecialchars($p['punto_abordaje']) ?></td>
                        <td><?= $p['hora_abordaje'] ?></td>
                        <td><?= $p['hora_salida'] ?></td>
                        <td><?= $p['estimado_personas'] ?></td>
                        <td>
                            <?php if ($p['atendido'] == 1): ?>
                                <span class="badge bg-success">Sí</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">No</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ================== -->
    <!--   REPORTES        -->
    <!-- ================== -->
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <h5 class="card-title">Reportes</h5>

            <table class="table table-bordered">
                <thead>
                <tr>
                    <th>Fecha</th>
                    <th>Parada</th>
                    <th>Acción</th>
                    <th>Personas</th>
                    <th>Descripción</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($reportes as $r): ?>
                    <tr>
                        <td><?= $r['fecha_reporte'] ?></td>
                        <td><?= $r['idparada'] ?></td>
                        <td><?= htmlspecialchars($r['accion']) ?></td>
                        <td><?= $r['total_personas'] ?></td>
                        <td><?= htmlspecialchars($r['descripcion']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ====================== -->
<!--   GOOGLE MAPS SCRIPT   -->
<!-- ====================== -->
<script>
let paradas = <?= json_encode($paradas) ?>;

function initMap() {
    if (paradas.length < 2) return;

    const map = new google.maps.Map(document.getElementById('map'), {
        center: {lat: parseFloat(paradas[0].latitud), lng: parseFloat(paradas[0].longitud)},
        zoom: 13
    });

    // Servicio para obtener ruta
    const directionsService = new google.maps.DirectionsService();

    // Renderizador de ruta
    const directionsRenderer = new google.maps.DirectionsRenderer({
        map: map,
        suppressMarkers: true   // Oculta marcadores por defecto
    });

    // ================================
    // 1. Crear puntos: origen, destino, waypoint
    // ================================
    const origen = {
        lat: parseFloat(paradas[0].latitud),
        lng: parseFloat(paradas[0].longitud)
    };

    const destino = {
        lat: parseFloat(paradas[paradas.length - 1].latitud),
        lng: parseFloat(paradas[paradas.length - 1].longitud)
    };

    // Waypoints = todas las paradas intermedias
    let waypoints = [];
    for (let i = 1; i < paradas.length - 1; i++) {
        waypoints.push({
            location: {
                lat: parseFloat(paradas[i].latitud),
                lng: parseFloat(paradas[i].longitud)
            },
            stopover: true
        });
    }

    // ================================
    // 2. Solicitar ruta al API
    // ================================
    directionsService.route(
        {
            origin: origen,
            destination: destino,
            waypoints: waypoints,
            travelMode: google.maps.TravelMode.DRIVING
        },
        function (result, status) {
            if (status === google.maps.DirectionsStatus.OK) {
                directionsRenderer.setDirections(result);
            } else {
                console.error("Error Directions:", status);
            }
        }
    );

    // ================================
    // 3. Marcadores manuales
    // ================================
    paradas.forEach(p => {
        new google.maps.Marker({
            position: {lat: parseFloat(p.latitud), lng: parseFloat(p.longitud)},
            map,
            label: p.orden.toString()
        });
    });
}

window.onload = initMap;
</script>


</body>
</html>
