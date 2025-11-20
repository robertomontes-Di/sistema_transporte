<?php
// dashboard/detalle_ruta.php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';

// Aceptar ?idruta= o ?id= (por compatibilidad)
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
// 3. Reportes de la ruta
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
// 4. Métricas básicas
// ==========================
$totalEstimado  = 0;
foreach ($paradas as $p) {
    $totalEstimado += (int)($p['estimado_personas'] ?? 0);
}

$totalReportado = 0;
foreach ($reportes as $r) {
    $totalReportado += (int)($r['total_personas'] ?? 0);
}

$avance = ($totalEstimado > 0)
    ? round(($totalReportado / $totalEstimado) * 100, 1)
    : 0.0;

$capacidadBus = (int)($ruta['capacidad_bus'] ?? 0);
$pctUsoBus = ($capacidadBus > 0)
    ? round(($totalReportado / $capacidadBus) * 100, 1)
    : null;

// ==========================
// 5. Datos para ECharts
// ==========================

// Timeline: fecha vs total_personas
$timelineData = [];
foreach ($reportes as $r) {
    $timelineData[] = [
        'fecha' => $r['fecha_reporte'],
        'total' => (int)$r['total_personas'],
    ];
}

// Personas por parada (sumatorio)
$personasPorParada = [];
foreach ($paradas as $p) {
    $personasPorParada[$p['idparada']] = [
        'nombre'  => $p['punto_abordaje'],
        'orden'   => (int)$p['orden'],
        'total'   => 0,
        'estimado'=> (int)($p['estimado_personas'] ?? 0),
    ];
}
foreach ($reportes as $r) {
    $idp = $r['idparada'];
    if (isset($personasPorParada[$idp])) {
        $personasPorParada[$idp]['total'] += (int)$r['total_personas'];
    }
}
// Ordenar por orden de parada
usort($personasPorParada, function($a, $b) {
    return $a['orden'] <=> $b['orden'];
});

// Para Google Maps: paradas con coordenadas
$paradasMapa = array_values(array_filter($paradas, function($p) {
    return !empty($p['latitud']) && !empty($p['longitud']);
}));

$pageTitle   = 'Detalle de Ruta - ' . $ruta['nombre'];
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
        <a href="<?= BASE_URL ?>/dashboard/dashboard_rutas.php" class="btn btn-sm btn-secondary mt-2">
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
            <h3 class="card-title">Personas por parada (suma)</h3>
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
            <div id="mapRuta" style="width:100%;height:580px;border-radius:8px;"></div>
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
                      <td><?= htmlspecialchars($r['punto_abordaje'] ?? 'Parada #' . $r['idparada']) ?></td>
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

<!-- Scripts específicos de esta página -->
<script src="https://maps.googleapis.com/maps/api/js?key=AIzaSyC2Zm7v7BAdKaA-KAvna0q4y0lQgwvE1V4"></script>
<script>
// Datos PHP -> JS
const timelineData = <?= json_encode($timelineData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const paradasData  = <?= json_encode($personasPorParada, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const paradasMapa  = <?= json_encode($paradasMapa, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

// ---------- ECharts: Timeline ----------
function buildTimelineChart(){
  const el = document.getElementById('chartTimeline');
  if (!el) return;
  const chart = echarts.init(el);

  const categorias = timelineData.map(p => p.fecha);
  const valores    = timelineData.map(p => p.total);

  const option = {
    tooltip: { trigger: 'axis' },
    xAxis: { type: 'category', data: categorias },
    yAxis: { type: 'value', min: 0 },
    series: [{
      type: 'line',
      data: valores,
      smooth: true,
      areaStyle: {}
    }]
  };
  chart.setOption(option);
  window.addEventListener('resize', () => chart.resize());
}

// ---------- ECharts: Personas por parada ----------
function buildParadasChart(){
  const el = document.getElementById('chartParadas');
  if (!el) return;
  const chart = echarts.init(el);

  const labels = paradasData.map(p => p.orden + '. ' + (p.nombre || 'Parada'));
  const valores = paradasData.map(p => p.total);
  const estimados = paradasData.map(p => p.estimado);

  const option = {
    tooltip: {
      trigger: 'axis',
      formatter: params => {
        let s = params[0].axisValue + '<br>';
        params.forEach(p => {
          s += p.seriesName + ': ' + p.value + '<br>';
        });
        return s;
      }
    },
    legend: { data: ['Reportado', 'Estimado'] },
    grid: { left: 120, right: 20, top: 40, bottom: 30 },
    xAxis: { type: 'value', min: 0 },
    yAxis: { type: 'category', data: labels },
    series: [
      {
        name: 'Reportado',
        type: 'bar',
        data: valores,
        label: { show: true, position: 'right' }
      },
      {
        name: 'Estimado',
        type: 'bar',
        data: estimados,
        label: { show: false, position: 'right' }
      }
    ]
  };

  chart.setOption(option);
  window.addEventListener('resize', () => chart.resize());
}

// ---------- Google Maps ----------
function initMap(){
  const mapEl = document.getElementById('mapRuta');
  if (!mapEl) return;

  let center = {lat: 13.6929, lng: -89.2182}; // Fallback: San Salvador
  if (paradasMapa.length > 0) {
    center = {
      lat: parseFloat(paradasMapa[0].latitud),
      lng: parseFloat(paradasMapa[0].longitud)
    };
  }

  const map = new google.maps.Map(mapEl, {
    center,
    zoom: 9
  });

  const pathCoords = [];
  paradasMapa.forEach(p => {
    const pos = {
      lat: parseFloat(p.latitud),
      lng: parseFloat(p.longitud)
    };
    pathCoords.push(pos);

    new google.maps.Marker({
      position: pos,
      map,
      label: p.orden ? String(p.orden) : undefined,
      title: p.punto_abordaje || 'Parada'
    });
  });

  if (pathCoords.length > 1) {
    const routeLine = new google.maps.Polyline({
      path: pathCoords,
      geodesic: true,
      strokeColor: '#4285F4',
      strokeOpacity: 0.8,
      strokeWeight: 3
    });
    routeLine.setMap(map);

    const bounds = new google.maps.LatLngBounds();
    pathCoords.forEach(c => bounds.extend(c));
    map.fitBounds(bounds);
  }
}

document.addEventListener('DOMContentLoaded', () => {
  buildTimelineChart();
  buildParadasChart();
  initMap();
});
</script>

<?php
require __DIR__ . '/../templates/footer.php';
