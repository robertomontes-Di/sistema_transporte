<?php
// dashboard/detalle_ruta.php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';

// -------------------------------------------------------
// ENDPOINT INTERNO PARA OBTENER ÚLTIMA UBICACIÓN DEL BUS
// -------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'ultima_ubicacion') {
    header('Content-Type: application/json');

    $idruta = (int)($_GET['idruta'] ?? 0);
    if ($idruta <= 0) {
        echo json_encode([]);
        exit;
    }

    $sql = "SELECT lat, lng 
            FROM ubicaciones 
            WHERE idruta = :idruta 
            ORDER BY fecha DESC 
            LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':idruta' => $idruta]);

    echo json_encode($stmt->fetch(PDO::FETCH_ASSOC) ?: []);
    exit;
}
// -------------------------------------------------------


// Aceptar ?idruta= o ?id=
$idruta = isset($_GET['idruta']) ? (int)$_GET['idruta'] : (int)($_GET['id'] ?? 0);
if ($idruta <= 0) {
    die('Ruta inválida');
}

// ==========================
// 1. Datos generales de ruta
// ==========================
$sqlRuta = "
    SELECT 
        r.idruta,
        r.nombre,
        r.destino,
        r.flag_arrival,
        b.placa,
        b.capacidad_asientos AS capacidad_bus,
        er.nombre AS encargado_nombre
    FROM ruta r
    LEFT JOIN bus b ON r.idbus = b.idbus
    LEFT JOIN encargado_ruta er ON r.idencargado_ruta = er.idencargado_ruta
    WHERE r.idruta = :idruta
";
$stmtRuta = $pdo->prepare($sqlRuta);
$stmtRuta->execute([':idruta' => $idruta]);
$ruta = $stmtRuta->fetch(PDO::FETCH_ASSOC);

if (!$ruta) {
    die('Ruta no encontrada');
}

// ==========================
// 2. Paradas de la ruta
// ==========================
$sqlParadas = "
    SELECT 
        idparada,
        punto_abordaje,
        orden,
        latitud,
        longitud,
        estimado_personas
    FROM paradas
    WHERE idruta = :idruta
    ORDER BY orden ASC
";
$stmtPar = $pdo->prepare($sqlParadas);
$stmtPar->execute([':idruta' => $idruta]);
$paradas = $stmtPar->fetchAll(PDO::FETCH_ASSOC);

// ==========================
// 3. Reportes
// ==========================
$sqlRep = "
    SELECT 
        rep.idreporte,
        rep.fecha_reporte,
        rep.total_personas,
        rep.comentario,
        rep.idparada,
        p.punto_abordaje,
        a.nombre AS accion
    FROM reporte rep
    LEFT JOIN paradas p  ON rep.idparada = p.idparada
    LEFT JOIN acciones a ON rep.idaccion = a.idaccion
    WHERE rep.idruta = :idruta
    ORDER BY rep.fecha_reporte ASC
";
$stmtRep = $pdo->prepare($sqlRep);
$stmtRep->execute([':idruta' => $idruta]);
$reportes = $stmtRep->fetchAll(PDO::FETCH_ASSOC);

// ==========================
// 4. Métricas
// ==========================
$totalEstimado   = array_sum(array_column($paradas, 'estimado_personas'));
$totalReportado  = array_sum(array_column($reportes, 'total_personas'));

$avance = ($totalEstimado > 0)
    ? round(($totalReportado / $totalEstimado) * 100, 1)
    : 0.0;

$capacidadBus = (int)($ruta['capacidad_bus'] ?? 0);
$pctUsoBus    = ($capacidadBus > 0)
    ? round(($totalReportado / $capacidadBus) * 100, 1)
    : null;

// ==========================
// 5. Datos para ECharts
// ==========================

// Timeline por evento
$timelineData = [];
foreach ($reportes as $r) {
    $timelineData[] = [
        'fecha' => $r['fecha_reporte'],
        'total' => (int)$r['total_personas'],
    ];
}

// Personas por parada
$personasPorParada = [];
foreach ($paradas as $p) {
    $personasPorParada[$p['idparada']] = [
        'nombre'   => $p['punto_abordaje'],
        'orden'    => (int)$p['orden'],
        'total'    => 0,
        'estimado' => (int)($p['estimado_personas'] ?? 0),
    ];
}
foreach ($reportes as $r) {
    if (!isset($personasPorParada[$r['idparada']])) {
        continue;
    }
    $personasPorParada[$r['idparada']]['total'] += (int)$r['total_personas'];
}
usort($personasPorParada, fn($a, $b) => $a['orden'] <=> $b['orden']);

// Paradas con coordenadas para el mapa
$paradasMapa = array_values(array_filter($paradas, fn($p) =>
    !empty($p['latitud']) && !empty($p['longitud'])
));

$pageTitle   = 'Detalle de Ruta';
$currentPage = 'dashboard_rutas';

require __DIR__ . '/../templates/header.php';
?>

<!-- Content Header -->
<section class="content-header">
  <div class="container-fluid">
    <div class="row mb-2">
      <div class="col-sm-8">
        <h1>Detalle de Ruta: <?= htmlspecialchars($ruta['nombre']) ?></h1>
        <p class="text-muted mb-0">
          Destino: <strong><?= htmlspecialchars($ruta['destino'] ?? '-') ?></strong><br>
          Bus: <strong><?= htmlspecialchars($ruta['placa'] ?? '-') ?></strong> |
          Encargado: <strong><?= htmlspecialchars($ruta['encargado_nombre'] ?? '-') ?></strong>
        </p>
      </div>
      <div class="col-sm-4 text-right">
        <a href="<?= BASE_URL ?>/dashboard/dashboard_rutas.php" class="btn btn-sm btn-secondary mt-3">
          <i class="fas fa-arrow-left mr-1"></i> Volver al Dashboard de Rutas
        </a>
      </div>
    </div>
  </div>
</section>

<!-- Main content -->
<section class="content">
  <div class="container-fluid">

    <!-- KPIs -->
    <div class="row mb-3">
      <div class="col-md-3">
        <div class="small-box bg-white">
          <div class="inner">
            <p class="text-muted mb-1">Personas estimadas (paradas)</p>
            <h3><?= number_format($totalEstimado) ?></h3>
          </div>
          <div class="icon">
            <i class="fas fa-user-friends"></i>
          </div>
        </div>
      </div>
      <div class="col-md-3">
        <div class="small-box bg-white">
          <div class="inner">
            <p class="text-muted mb-1">Personas reportadas (suma)</p>
            <h3><?= number_format($totalReportado) ?></h3>
          </div>
          <div class="icon">
            <i class="fas fa-users"></i>
          </div>
        </div>
      </div>
      <div class="col-md-3">
        <div class="small-box bg-white">
          <div class="inner">
            <p class="text-muted mb-1">Avance vs estimado</p>
            <h3><?= $avance ?>%</h3>
          </div>
          <div class="icon">
            <i class="fas fa-chart-line"></i>
          </div>
        </div>
      </div>
      <div class="col-md-3">
        <div class="small-box bg-white">
          <div class="inner">
            <p class="text-muted mb-1">Uso capacidad bus</p>
            <h3><?= $pctUsoBus !== null ? $pctUsoBus . '%' : '-' ?></h3>
          </div>
          <div class="icon">
            <i class="fas fa-bus-alt"></i>
          </div>
        </div>
      </div>
    </div>

    <!-- Gráficas + Mapa -->
    <div class="row">
      <div class="col-lg-6">
        <!-- Timeline -->
        <div class="card">
          <div class="card-header">
            <h3 class="card-title">Timeline de reportes (personas por evento)</h3>
          </div>
          <div class="card-body">
            <div id="chartTimeline" style="width:100%;height:280px;"></div>
          </div>
        </div>

        <!-- Personas por parada -->
        <div class="card">
          <div class="card-header">
            <h3 class="card-title">Personas por parada (suma vs estimado)</h3>
          </div>
          <div class="card-body">
            <div id="chartParadas" style="width:100%;height:280px;"></div>
          </div>
        </div>
      </div>

      <!-- Mapa -->
      <div class="col-lg-6">
        <div class="card">
          <div class="card-header">
            <h3 class="card-title">Mapa de paradas de la ruta</h3>
          </div>
          <div class="card-body">
            <div id="map" style="width:100%;height:580px;border-radius:8px;"></div>
          </div>
        </div>
      </div>
    </div>

    <!-- Tabla de reportes -->
    <div class="row mt-3">
      <div class="col-12">
        <div class="card">
          <div class="card-header">
            <h3 class="card-title">Reportes de la ruta</h3>
          </div>
          <div class="card-body table-responsive p-0">
            <?php if (empty($reportes)): ?>
              <div class="p-3">
                <em>No hay reportes registrados para esta ruta.</em>
              </div>
            <?php else: ?>
              <table class="table table-striped table-hover table-sm mb-0">
                <thead class="thead-light">
                  <tr>
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
                      <td><?= htmlspecialchars($r['fecha_reporte']) ?></td>
                      <td><?= htmlspecialchars($r['punto_abordaje'] ?? ('Parada #' . $r['idparada'])) ?></td>
                      <td><?= htmlspecialchars($r['accion'] ?? '') ?></td>
                      <td><?= (int)$r['total_personas'] ?></td>
                      <td><?= htmlspecialchars($r['comentario'] ?? '') ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

  </div><!-- /.container-fluid -->
</section>

<!-- Google Maps (solo una vez en esta página) -->
<script src="https://maps.googleapis.com/maps/api/js?key=AIzaSyC2Zm7v7BAdKaA-KAvna0q4y0lQgwvE1V4&libraries=geometry"></script>

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


<?php
require __DIR__ . '/../templates/footer.php';
