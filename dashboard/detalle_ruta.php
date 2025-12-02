<?php
// dashboard/detalle_ruta.php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';

/*
 -----------------------------------------------------------------
  ENDPOINT JSON: Última ubicación del bus (para marcador en el mapa)
 -----------------------------------------------------------------
  Se usa desde el botón "Enviar ubicación" del líder de ruta, que
  guarda coordenadas en la tabla `ubicaciones`.
*/
if (isset($_GET['action']) && $_GET['action'] === 'ultima_ubicacion') {
  header('Content-Type: application/json; charset=utf-8');

  $idruta = (int)($_GET['idruta'] ?? 0);
  if ($idruta <= 0) {
    echo json_encode([]);
    exit;
  }

  try {
    $sql = "
            SELECT lat, lng, fecha_registro
            FROM ubicaciones
            WHERE idruta = :idruta
            ORDER BY fecha_registro DESC
            LIMIT 1
        ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':idruta' => $idruta]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    echo json_encode($row ?: []);
  } catch (Throwable $e) {
    echo json_encode([]);
  }
  exit;
}

/* -------------------------------------------------------
   1. Validación de parámetro idruta
--------------------------------------------------------*/
$idruta = isset($_GET['idruta']) ? (int)$_GET['idruta'] : (int)($_GET['id'] ?? 0);
if ($idruta <= 0) {
  die('Ruta inválida');
}

/* -------------------------------------------------------
   2. Datos generales de la ruta
--------------------------------------------------------*/
$sqlRuta = "
    SELECT 
        r.idruta,
        r.nombre,
        r.destino,
        r.flag_arrival,
        b.placa,
        b.capacidad_asientos AS capacidad_bus,
        er.nombre AS encargado_nombre,
        er.telefono as encargado_telefono
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


/* -------------------------------------------------------
   3. Paradas de la ruta
--------------------------------------------------------*/
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

/* -------------------------------------------------------
   4. Reportes de la ruta
--------------------------------------------------------*/
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

/* -------------------------------------------------------
   5. Métricas globales
--------------------------------------------------------*/
$totalEstimado  = array_sum(array_column($paradas, 'estimado_personas'));
$totalReportado = array_sum(array_column($reportes, 'total_personas'));

$avance = ($totalEstimado > 0)
  ? round(($totalReportado / $totalEstimado) * 100, 1)
  : 0.0;

$capacidadBus = (int)($ruta['capacidad_bus'] ?? 0);
$pctUsoBus    = ($capacidadBus > 0)
  ? round(($totalReportado / $capacidadBus) * 100, 1)
  : null;

/* -------------------------------------------------------
   6. Datos para ECharts
   - Timeline por evento
   - Personas por parada (reportadas vs estimado)
--------------------------------------------------------*/

// Timeline: cada punto = un reporte
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
$paradasMapa = array_values(array_filter(
  $paradas,
  fn($p) =>
  !empty($p['latitud']) && !empty($p['longitud'])
));

/* -------------------------------------------------------
   7. Recorrido real del bus (tabla ubicaciones)
--------------------------------------------------------*/
$sqlUbic = "
    SELECT 
        lat,
        lng,
        fecha_registro
    FROM ubicaciones
    WHERE idruta = :idruta
    ORDER BY fecha_registro ASC
";
$stmtUbic = $pdo->prepare($sqlUbic);
$stmtUbic->execute([':idruta' => $idruta]);
$ubicacionesRuta = $stmtUbic->fetchAll(PDO::FETCH_ASSOC);

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
          <?php
          $tel = trim($ruta['encargado_telefono'] ?? '');
          $tel = preg_replace('/\D+/', '', $tel); // limpia espacios, guiones, etc.
          ?>

          <?php if (!empty($tel)): ?>
            <span><?= htmlspecialchars($tel) ?></span>
            <a href="https://wa.me/503<?= htmlspecialchars($tel) ?>"
              target="_blank"
              class="btn btn-success">
              Chatear en WhatsApp
            </a>
          <?php else: ?>
            <span class="text-muted">Sin número de teléfono</span>
          <?php endif; ?>

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
            <h3 class="card-title">Mapa de paradas y recorrido</h3>
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

<!-- Google Maps API -->
<script src="https://maps.googleapis.com/maps/api/js?key=AIzaSyC2Zm7v7BAdKaA-KAvna0q4y0lQgwvE1V4&libraries=geometry"></script>

<script>
  // Datos desde PHP
  const timelineData = <?= json_encode($timelineData) ?>;
  const paradasAggData = <?= json_encode($personasPorParada) ?>;
  const paradasMapa = <?= json_encode($paradasMapa) ?>;
  const ubicacionesRuta = <?= json_encode($ubicacionesRuta) ?>;
  const idruta = <?= (int)$idruta ?>;

  let map;
  let busMarker = null;

  /* ------------------------------------------------------
     1) ECharts: Timeline de reportes
  -------------------------------------------------------*/
  function buildTimelineChart() {
    const el = document.getElementById('chartTimeline');
    if (!el) return;
    const chart = echarts.init(el);

    const labels = timelineData.map(d => d.fecha);
    const values = timelineData.map(d => d.total);

    const option = {
      tooltip: {
        trigger: 'axis'
      },
      xAxis: {
        type: 'category',
        data: labels,
        axisLabel: {
          rotate: labels.length > 5 ? 45 : 0
        }
      },
      yAxis: {
        type: 'value',
        min: 0
      },
      series: [{
        name: 'Personas',
        type: 'line',
        smooth: true,
        data: values
      }]
    };

    chart.setOption(option);
    window.addEventListener('resize', () => chart.resize());
  }

  /* ------------------------------------------------------
     2) ECharts: Personas por parada (reportado vs estimado)
  -------------------------------------------------------*/
  function buildParadasChart() {
    const el = document.getElementById('chartParadas');
    if (!el) return;
    const chart = echarts.init(el);

    const labels = paradasAggData.map(p => p.nombre);
    const reportes = paradasAggData.map(p => p.total);
    const estimado = paradasAggData.map(p => p.estimado);

    const option = {
      tooltip: {
        trigger: 'axis'
      },
      legend: {
        data: ['Reportadas', 'Estimado']
      },
      xAxis: {
        type: 'category',
        data: labels,
        axisLabel: {
          rotate: labels.length > 5 ? 45 : 0
        }
      },
      yAxis: {
        type: 'value',
        min: 0
      },
      series: [{
          name: 'Reportadas',
          type: 'bar',
          data: reportes
        },
        {
          name: 'Estimado',
          type: 'bar',
          data: estimado
        }
      ]
    };

    chart.setOption(option);
    window.addEventListener('resize', () => chart.resize());
  }

  /* ------------------------------------------------------
     3) Marcador del bus
  -------------------------------------------------------*/
  function updateBusPosition(lat, lng) {
    const pos = {
      lat: parseFloat(lat),
      lng: parseFloat(lng)
    };

    if (!busMarker) {
      busMarker = new google.maps.Marker({
        map: map,
        position: pos,
        title: "Autobús"
      });
    } else {
      busMarker.setPosition(pos);
    }
  }

  /* ------------------------------------------------------
     4) Obtener última ubicación del bus
  -------------------------------------------------------*/
  function fetchUltimaUbicacion() {
    fetch('detalle_ruta.php?action=ultima_ubicacion&idruta=' + encodeURIComponent(idruta))
      .then(r => r.json())
      .then(data => {
        if (!data || !data.lat || !data.lng) return;
        updateBusPosition(data.lat, data.lng);
      })
      .catch(() => {});
  }

  /* ------------------------------------------------------
     5) Google Maps: ruta planificada + recorrido real
  -------------------------------------------------------*/
  /*function initMap() {
    const mapEl = document.getElementById('map');
    if (!mapEl) return;

    // Centro inicial
    let center = { lat: 13.6929, lng: -89.2182 };

    if (paradasMapa.length > 0) {
      center = {
        lat: parseFloat(paradasMapa[0].latitud),
        lng: parseFloat(paradasMapa[0].longitud)
      };
    } else if (ubicacionesRuta.length > 0) {
      center = {
        lat: parseFloat(ubicacionesRuta[0].lat),
        lng: parseFloat(ubicacionesRuta[0].lng)
      };
    }

    map = new google.maps.Map(mapEl, {
      center,
      zoom: 11
    });

    const bounds = new google.maps.LatLngBounds();

    const directionsService  = new google.maps.DirectionsService();
    const directionsRenderer = new google.maps.DirectionsRenderer({
      map: map,
      suppressMarkers: true
    });

    // Origen, destino y waypoints
    const origen = {
      lat: parseFloat(paradasMapa[0].latitud),
      lng: parseFloat(paradasMapa[0].longitud)
    };




    // 5.1 Ruta planificada (paradas → polilínea azul)
    const pathRuta = [];

    const destino = {
      lat: parseFloat(paradasMapa[paradasMapa.length - 1].latitud),
      lng: parseFloat(paradasMapa[paradasMapa.length - 1].longitud)
    };

      let waypoints = [];
    for (let i = 1; i < paradasMapa.length - 1; i++) {
      waypoints.push({
        location: {
          lat: parseFloat(paradasMapa[i].latitud),
          lng: parseFloat(paradasMapa[i].longitud)
        },
        stopover: true
      });
    }

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




    paradasMapa.forEach(p => {
      const pos = {
        lat: parseFloat(p.latitud),
        lng: parseFloat(p.longitud)
      };
      pathRuta.push(pos);
      bounds.extend(pos);

      new google.maps.Marker({
        position: {
          lat: parseFloat(p.latitud),
          lng: parseFloat(p.longitud)
        },
        map,
        label: p.orden ? String(p.orden) : undefined,
        title: p.punto_abordaje || 'Parada'
      });

    });

    if (pathRuta.length > 1) {
      const routeLine = new google.maps.Polyline({
        path: pathRuta,
        geodesic: true,
        strokeColor: '#4285F4',   // Azul
        strokeOpacity: 0.9,
        strokeWeight: 3
      });
      routeLine.setMap(map);
    }

    // 5.2 Recorrido real (ubicaciones → polilínea verde)
    const pathBus = [];

    ubicacionesRuta.forEach(u => {
      if (!u.lat || !u.lng) return;
      const pos = {
        lat: parseFloat(u.lat),
        lng: parseFloat(u.lng)
      };
      pathBus.push(pos);
      bounds.extend(pos);
    });

    if (pathBus.length > 1) {
      const busLine = new google.maps.Polyline({
        path: pathBus,
        geodesic: true,
        strokeColor: '#28a745',   // Verde
        strokeOpacity: 0.9,
        strokeWeight: 3
      });
      busLine.setMap(map);
    }

    // 5.3 Colocar el bus en la última ubicación conocida
    if (pathBus.length > 0) {
      const last = pathBus[pathBus.length - 1];
      updateBusPosition(last.lat, last.lng);
    }

    // Ajustar vista para que quepa todo
    if (!bounds.isEmpty()) {
      map.fitBounds(bounds);
    }

    // Primer fetch de ubicación del bus y luego cada 60 s
    fetchUltimaUbicacion();
    setInterval(fetchUltimaUbicacion, 60000);
  }*/
  function initMap() {
    const mapEl = document.getElementById('map');
    if (!mapEl) return;

    let center = {
      lat: 13.6929,
      lng: -89.2182
    };

    if (paradasMapa.length > 0) {
      center = {
        lat: parseFloat(paradasMapa[0].latitud),
        lng: parseFloat(paradasMapa[0].longitud)
      };
    }

    map = new google.maps.Map(mapEl, {
      center,
      zoom: 11
    });

    // SOLO PARA AJUSTAR LA VISTA
    const bounds = new google.maps.LatLngBounds();

    // 1️⃣ MARCAR LAS PARADAS
    const waypoints = [];

    paradasMapa.forEach(p => {
      const pos = {
        lat: parseFloat(p.latitud),
        lng: parseFloat(p.longitud)
      };

      // agregar pin
      new google.maps.Marker({
        position: pos,
        map,
        label: p.orden ? String(p.orden) : undefined,
        title: p.punto_abordaje || 'Parada'
      });

      // agregar waypoint para directions
      waypoints.push({
        location: pos,
        stopover: true
      });

      bounds.extend(pos);
    });

    // 2️⃣ DIBUJAR LA RUTA REAL (GOOGLE DIRECTIONS)
    if (waypoints.length >= 2) {
      const directionsService = new google.maps.DirectionsService();
      const directionsRenderer = new google.maps.DirectionsRenderer({
        map: map,
        suppressMarkers: true // no duplicar marcadores
      });

      directionsService.route({
          origin: waypoints[0].location,
          destination: waypoints[waypoints.length - 1].location,
          waypoints: waypoints.slice(1, -1),
          travelMode: google.maps.TravelMode.DRIVING
        },
        (response, status) => {
          if (status === google.maps.DirectionsStatus.OK) {
            directionsRenderer.setDirections(response);
          } else {
            console.error('Error en Directions:', status);
          }
        }
      );
    }

    // 3️⃣ DIBUJAR LA UBICACIÓN DEL BUS SI EXISTE
    if (ubicacionesRuta.length > 0) {
      const ultimaPos = ubicacionesRuta[ubicacionesRuta.length - 1];

      const posBus = {
        lat: parseFloat(ultimaPos.lat),
        lng: parseFloat(ultimaPos.lng)
      };

      new google.maps.Marker({
        position: posBus,
        map,
        icon: "https://maps.google.com/mapfiles/ms/icons/bus.png",
        title: "Ubicación actual del bus"
      });

      bounds.extend(posBus);
    }

    map.fitBounds(bounds);
  }


  /* ------------------------------------------------------
     6) Inicialización
  -------------------------------------------------------*/
  document.addEventListener('DOMContentLoaded', () => {
    buildTimelineChart();
    buildParadasChart();
    initMap();
  });
</script>

<?php
require __DIR__ . '/../templates/footer.php';
?>