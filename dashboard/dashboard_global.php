<?php
// dashboard_global.php

require_once __DIR__ . '/../includes/db.php';

// Detectar si es una llamada API (JSON) o carga normal de página
$action = $_GET['action'] ?? null;
// -----------------------------------------------------------------
// ENDPOINTS AJAX
// -----------------------------------------------------------------
if ($action === 'stats') {
    try {
        // --------------------------------------------------------
        // 1) PERSONAS EN RUTA (último reporte de cada ruta ACTIVA
        //    que todavía NO ha llegado al estadio)
        // --------------------------------------------------------
        $sqlPersonasRuta = "
          SELECT COALESCE(SUM(r.total_personas),0) AS personas_en_ruta
    FROM reporte r
    INNER JOIN ruta ru ON ru.idruta = r.idruta
    WHERE ru.activa = 1
      AND (ru.flag_arrival IS NULL OR ru.flag_arrival = 0)
        ";
        $personas_en_ruta = (int)$pdo->query($sqlPersonasRuta)->fetchColumn();

        // --------------------------------------------------------
        // 1b) PERSONAS EN ESTADIO (último reporte de cada ruta que
        //     YA marcó flag_arrival = 1)
        // --------------------------------------------------------
        $sqlPersonasEstadio = "
              SELECT COALESCE(SUM(r.total_personas),0) AS personas_en_estadio
    FROM reporte r
    INNER JOIN ruta ru ON ru.idruta = r.idruta
    WHERE ru.flag_arrival = 1
        ";
        $personas_en_estadio = (int)$pdo->query($sqlPersonasEstadio)->fetchColumn();

        // --------------------------------------------------------
        // 2) TOTAL ESTIMADO (paradas) SOLO DE RUTAS ACTIVAS
        //    QUE TODAVÍA NO HAN LLEGADO AL ESTADIO
        // --------------------------------------------------------
        $sqlEstimated = "
            SELECT COALESCE(SUM(p.estimado_personas),0) AS total_estimated
            FROM paradas p
            INNER JOIN ruta r ON r.idruta = p.idruta
            WHERE r.activa = 1
              AND (r.flag_arrival IS NULL OR r.flag_arrival = 0)
        ";
        $total_estimated = (int)$pdo->query($sqlEstimated)->fetchColumn();

        // 3) routes_active: rutas con activa = 1
        $sql_routes_active = "SELECT COUNT(*) FROM ruta WHERE activa = 1";
        $routes_active = (int)$pdo->query($sql_routes_active)->fetchColumn();

        // 4) routes_total: total de rutas
        $sql_routes_total = "SELECT COUNT(*) FROM ruta";
        $routes_total = (int)$pdo->query($sql_routes_total)->fetchColumn();

        // --------------------------------------------------------
        // 5) ESTADO DE RUTAS (sin problema / inconveniente / crítico)
        //    según ÚLTIMO reporte por ruta (todas las rutas)
        // --------------------------------------------------------
        $sqlStatus = "
            SELECT
                SUM(
                    CASE WHEN lr.idaccion IS NULL THEN 1 ELSE 0 END
                ) AS sin_problema,
                SUM(
                    CASE
                        WHEN lr.idaccion IS NOT NULL
                             AND LOWER(a.tipo_accion) = 'critico'
                        THEN 1 ELSE 0
                    END
                ) AS falla,
                SUM(
                    CASE
                        WHEN lr.idaccion IS NOT NULL
                             AND LOWER(a.tipo_accion) = 'inconveniente'
                        THEN 1 ELSE 0
                    END
                ) AS inconveniente
            FROM ruta r
            LEFT JOIN (
                SELECT r1.*
                FROM reporte r1
                INNER JOIN (
                    SELECT idruta, MAX(fecha_reporte) AS max_fecha
                    FROM reporte
                    GROUP BY idruta
                ) mx ON r1.idruta = mx.idruta
                     AND r1.fecha_reporte = mx.max_fecha
            ) lr ON lr.idruta = r.idruta
            LEFT JOIN acciones a ON a.idaccion = lr.idaccion
        ";
        $status = $pdo->query($sqlStatus)->fetch(PDO::FETCH_ASSOC) ?: [
            'sin_problema'  => 0,
            'falla'         => 0,
            'inconveniente' => 0,
        ];

        // --------------------------------------------------------
        // RESPUESTA JSON PARA EL FRONT
        // --------------------------------------------------------
        echo json_encode([
            'personas_en_ruta'    => $personas_en_ruta,
            'personas_en_estadio' => $personas_en_estadio,
            'total_estimated'     => $total_estimated,
            'routes_active'       => $routes_active,
            'routes_total'        => $routes_total,
            'routes_en_estadio'   => $routes_en_estadio,
            'routes_en_transito'  => $routes_en_transito,
            'sin_problema'        => (int)$status['sin_problema'],
            'inconveniente'       => (int)$status['inconveniente'],
            'falla'               => (int)$status['falla'],
        ]);
        exit;

    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Error en stats: ' . $e->getMessage()]);
        exit;
    }
}

       // MAP
    // -----------------------------------------------------------------

if ($action === 'map') {
       try {

        // ======== RECIBIR FILTROS ========
        $departamento = $_GET['departamento'] ?? null;
      
        $tipo_accion  = $_GET['tipo_accion'] ?? null;
        $idruta       = $_GET['idruta'] ?? null;

        $where = " WHERE p.latitud IS NOT NULL AND p.longitud IS NOT NULL ";

        if ($departamento) $where .= " AND r.idruta in( select distinct pp.idruta from paradas pp where pp.departamento = :departamento )";
      
        if ($tipo_accion)  $where .= " AND a.tipo_accion = :tipo_accion ";
        if ($idruta)       $where .= " AND r.idruta = :idruta ";

        // ======== QUERY ORIGINAL + WHERE DINÁMICO ========
        $sql = "
            SELECT
                r.idruta,
                r.nombre AS ruta_nombre,
                b.placa,
                b.conductor,
                rep.total_personas,
                rep.total_becarios,
                rep.total_menores12,
                rep.fecha_reporte,
                rep.idaccion,
                a.nombre AS accion_nombre,
                a.tipo_accion,
                p.idparada,
                p.latitud,
                p.longitud,
                p.orden,
                p.departamento
            FROM ruta r
            LEFT JOIN (
                SELECT r1.*
                FROM reporte r1
                INNER JOIN (
                    SELECT idruta, MAX(fecha_reporte) AS max_fecha
                    FROM reporte
                    GROUP BY idruta
                ) mx ON r1.idruta = mx.idruta AND r1.fecha_reporte = mx.max_fecha
            ) rep ON rep.idruta = r.idruta
            LEFT JOIN acciones a ON a.idaccion = rep.idaccion
            LEFT JOIN bus b ON b.idbus = r.idbus
            LEFT JOIN paradas p ON p.idruta = r.idruta
            $where
            ORDER BY r.idruta, p.orden
        ";

        $stmt = $pdo->prepare($sql);

        if ($departamento) $stmt->bindValue(':departamento', $departamento);      
        if ($tipo_accion)  $stmt->bindValue(':tipo_accion', $tipo_accion);
        if ($idruta)       $stmt->bindValue(':idruta', $idruta);

        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Agrupamos por ruta
        $routes = [];
        foreach ($rows as $row) {
            $idruta = (int)$row['idruta'];

            if (!isset($routes[$idruta])) {
                // Status según tipo_accion del último reporte
                $status = 'sin_problema';
                if (!empty($row['idaccion'])) {
                    $tipo = strtolower($row['tipo_accion'] ?? '');
                    if ($tipo === 'critico') {
                        $status = 'critico';
                    } elseif ($tipo === 'inconveniente') {
                        $status = 'inconveniente';
                    } else {
                        $status = 'sin_problema';
                    }
                }

                $routes[$idruta] = [
                    'idruta'          => $idruta,
                    'ruta_nombre'     => $row['ruta_nombre'],
                    'placa'           => $row['placa'],
                    'conductor'       => $row['conductor'],
                    'total_personas'  => (int)$row['total_personas'],
                    'total_becarios'  => (int)$row['total_becarios'],
                    'total_menores12' => (int)$row['total_menores12'],
                    'fecha_reporte'   => $row['fecha_reporte'],
                    'status'          => $status,
                    'accion_nombre'   => $row['accion_nombre'],
                    'path'            => [],  // aquí irán todas las paradas
                ];
            }

            // Agregar punto de la parada
            if ($row['latitud'] !== null && $row['longitud'] !== null) {
                $routes[$idruta]['path'][] = [
                    'lat'   => (float)$row['latitud'],
                    'lng'   => (float)$row['longitud'],
                    'orden' => (int)$row['orden'],
                ];
            }
        }

        echo json_encode(array_values($routes));
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Error en map: ' . $e->getMessage()]);
        exit;
    }
}


// -----------------------------------------------------------------
// LISTADO DE REPORTES RECIENTES
// -----------------------------------------------------------------
if ($action === 'recent_reports') {
    try {
        $sql = "
            SELECT r.idreporte, rt.nombre AS ruta_nombre, b.placa, r.total_personas,
                   r.fecha_reporte, a.nombre AS accion_nombre, a.tipo_accion
            FROM reporte r
            INNER JOIN ruta rt ON rt.idruta = r.idruta
            INNER JOIN bus b  ON b.idbus  = rt.idbus
            LEFT JOIN acciones a ON a.idaccion = r.idaccion
            ORDER BY r.fecha_reporte DESC
            LIMIT 20
        ";
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['ok' => true, 'data' => $rows]);
        exit;

    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// ---------------------------------------------------------------------
// MODO PÁGINA HTML (sin ?action=...)
// ---------------------------------------------------------------------
$pageTitle   = 'Dashboard Global';
$currentPage = 'dashboard_global';

require __DIR__ . '/../templates/header.php';
?>

<!-- Content Header (título y breadcrumb) -->
<section class="content-header">
  <div class="container-fluid">
    <div class="row mb-2">
      <div class="col-sm-8">
        <h1>Centro de Monitoreo - Dashboard Global</h1>
        <p class="text-muted mb-0">Resumen en tiempo real de rutas, personas y eventos</p>
      </div>
      <div class="col-sm-4 text-right">
        <small class="text-muted">
          Última actualización: <span id="last-update">—</span>
        </small>
      </div>
    </div>
  </div>
</section>

<!-- Main content -->
<section class="content">
  <div class="container-fluid">

    <!-- KPIs -->
        <!-- KPIs estilo tiles de color -->
<!-- KPIs -->
<div class="row kpi-row">

  <!-- 1. Total personas en ruta -->
  <div class="col-xl-2 col-md-4 col-sm-6 mb-3">
    <div class="kpi-tile kpi-teal">
      <div class="kpi-body">
        <div class="kpi-label">Total personas en ruta</div>
        <div class="kpi-value" id="kpi_total_reported">0</div>
      </div>
      <div class="kpi-icon">
        <i class="fas fa-users"></i>
      </div>
    </div>
  </div>

  <!-- 2. Total estimado de personas esperadas -->
  <div class="col-xl-2 col-md-4 col-sm-6 mb-3">
    <div class="kpi-tile kpi-cyan">
      <div class="kpi-body">
        <div class="kpi-label">Total estimado de personas esperadas</div>
        <div class="kpi-value" id="kpi_total_estimated">0</div>
      </div>
      <div class="kpi-icon">
        <i class="fas fa-user-friends"></i>
      </div>
    </div>
  </div>

  <!-- 3. Total personas en estadio -->
  <div class="col-xl-2 col-md-4 col-sm-6 mb-3">
    <div class="kpi-tile kpi-teal">
      <div class="kpi-body">
        <div class="kpi-label">Total de personas en estadio</div>
        <div class="kpi-value" id="kpi_total_estadio">0</div>
      </div>
      <div class="kpi-icon">
        <i class="fas fa-futbol"></i>
      </div>
    </div>
  </div>

  <!-- 4. Rutas activas -->
  <div class="col-xl-2 col-md-4 col-sm-6 mb-3">
    <div class="kpi-tile kpi-blue">
      <div class="kpi-body">
        <div class="kpi-label">Rutas activas</div>
        <div class="kpi-value" id="kpi_routes_active">0 / 0</div>
      </div>
      <div class="kpi-icon">
        <i class="fas fa-route"></i>
      </div>
    </div>
  </div>

  <!-- 5. Sin problema -->
  <div class="col-xl-2 col-md-4 col-sm-6 mb-3">
    <div class="kpi-tile kpi-green">
      <div class="kpi-body">
        <div class="kpi-label">Sin problema</div>
        <div class="kpi-value" id="kpi_sin_problema">0</div>
      </div>
      <div class="kpi-icon">
        <i class="fas fa-check-circle"></i>
      </div>
    </div>
  </div>

  <!-- 6. Inconveniente -->
  <div class="col-xl-2 col-md-4 col-sm-6 mb-3">
    <div class="kpi-tile kpi-orange">
      <div class="kpi-body">
        <div class="kpi-label">Inconveniente</div>
        <div class="kpi-value" id="kpi_inconveniente">0</div>
      </div>
      <div class="kpi-icon">
        <i class="fas fa-exclamation-circle"></i>
      </div>
    </div>
  </div>

  <!-- 7. Crítico -->
  <div class="col-xl-2 col-md-4 col-sm-6 mb-3">
    <div class="kpi-tile kpi-red">
      <div class="kpi-body">
        <div class="kpi-label">Crítico</div>
        <div class="kpi-value" id="kpi_critico">0</div>
      </div>
      <div class="kpi-icon">
        <i class="fas fa-exclamation-triangle"></i>
      </div>
    </div>
  </div>

  <!-- 8. Rutas hacia punto de inicio -->
  <div class="col-xl-2 col-md-4 col-sm-6 mb-3">
    <div class="kpi-tile kpi-blue">
      <div class="kpi-body">
        <div class="kpi-label">Rutas hacia punto de inicio</div>
        <div class="kpi-value" id="kpi_routes_return_home">0 / 0</div>
      </div>
      <div class="kpi-icon">
        <i class="fas fa-route"></i>
      </div>
    </div>
  </div>

  <!-- 9. Rutas que ya llegaron a casa -->
  <div class="col-xl-2 col-md-4 col-sm-6 mb-3">
    <div class="kpi-tile kpi-blue">
      <div class="kpi-body">
        <div class="kpi-label">Rutas que ya llegaron a casa</div>
        <div class="kpi-value" id="kpi_routes_arrived_home">0 / 0</div>
      </div>
      <div class="kpi-icon">
        <i class="fas fa-home"></i>
      </div>
    </div>
  </div>
</div>

    <!-- Gráficas + Tabla + Mapa -->
    <!-- Bloque de gráficos + mapa -->
    <div class="row mt-3">

      <!-- Columna izquierda: gráficos -->
      <div class="col-lg-5">

        <div class="card mb-3">
          <div class="card-header">
            <h3 class="card-title">Total personas: En estadio vs Estimadas</h3>
          </div>
          <div class="card-body">
            <div id="chartPersons" style="width:100%;height:260px;"></div>
          </div>
        </div>

        <div class="card">
          <div class="card-header">
            <h3 class="card-title">Estado de las rutas</h3>
          </div>
          <div class="card-body">
            <div id="chartStatus" style="width:100%;height:260px;"></div>
          </div>
        </div>

      </div>

      <!-- Columna derecha: mapa -->
      <div class="col-lg-7">
        <div class="card h-100">
          <div class="card-header">
            <h3 class="card-title">Mapa de rutas (último punto conocido)</h3>
          </div>
          <div class="card-body">
            <div class="mb-2">
  <div class="row">

    <div class="col-md-3">
      <label>Departamento</label>
      <select id="f_departamento" class="form-control">
        <option value="">Todos</option>
      </select>
    </div>  

    <div class="col-md-3">
      <label>Estado (acción)</label>
      <select id="f_tipo_accion" class="form-control">
        <option value="">Todos</option>
        <option value="normal">Sin problema</option>
        <option value="inconveniente">Inconveniente</option>
        <option value="critico">Crítico</option>
         <option value="">Sin Reporte</option>
      </select>
    </div>

    <div class="col-md-3">
      <label>Ruta</label>
      <select id="f_idruta" class="form-control">
        <option value="">Todas</option>
      </select>
    </div>

  </div>
</div>

            <div id="map" style="width:100%; height:420px; border-radius:8px;"></div>
          </div>
        </div>
      </div>

    </div>

    <!-- Tabla de últimos reportes -->
    <div class="row mt-3">
      <div class="col-12">
        <div class="card">
          <div class="card-header">
            <h3 class="card-title">Últimos reportes</h3>
          </div>
          <div class="card-body p-1">
            <div class="table-responsive" style="width: 100%;">
              <table id="tblReports" class="table table-striped table-sm mb-0 w-100">
                <thead>
                  <tr>
                    <th>#</th>
                    <th>Ruta</th>
                    <th>Placa</th>
                    <th>Encargado</th>
                    <th>Agente</th>
                    <th>Personas</th>
                    <th>Acción</th>
                    <th>Fecha</th>
                  </tr>
                </thead>
                <tbody></tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </div>

  </div><!-- /.container-fluid -->
</section>
<!-- /.content -->

<!-- Scripts específicos de esta página -->
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<!-- Google Maps API -->
<script src="https://maps.googleapis.com/maps/api/js?key=AIzaSyC2Zm7v7BAdKaA-KAvna0q4y0lQgwvE1V4&libraries=geometry"></script>

<script>
const apiBase = location.pathname + '?action=';

function fmtNumber(n){
  return (n || 0).toLocaleString();
}

async function fetchStats(){
  const res = await fetch(apiBase + 'stats');
  if(!res.ok) throw new Error('Error fetching stats');
  return res.json();
}
async function fetchMapData(){
  const f = new URLSearchParams();

  f.append("departamento", document.getElementById("f_departamento").value);
  f.append("tipo_accion", document.getElementById("f_tipo_accion").value);
  f.append("idruta", document.getElementById("f_idruta").value);

  const res = await fetch(apiBase + 'map&' + f.toString());
  return res.json();
}

async function fetchRecentReports(limit=50){
  const res = await fetch(apiBase + 'recent_reports&limit=' + limit);
  if(!res.ok) throw new Error('Error fetching reports');
  return res.json();
}

let chartPersons = null;
let chartStatus  = null;

async function renderKpisAndChart(){
  try {
    const stats = await fetchStats();

    // ---------- KPIs ----------
    // Personas en estadio / estimadas
    $('#kpi_total_reported').text(
      fmtNumber(stats.personas_en_ruta || 0)
    );
    $('#kpi_total_estimated').text(
      fmtNumber(stats.total_estimated || 0)
    );
    $('#kpi_total_estadio').text(
      fmtNumber(stats.personas_en_estadio || 0)
    );

    // Rutas en transito / Rutas Estimado (en un solo tile)
    document.getElementById('kpi_routes_active').innerText =
      fmtNumber(stats.routes_active || 0) + ' / ' + fmtNumber(stats.routes_total || 0);


    // Estados de rutas
    $('#kpi_sin_problema').text(fmtNumber(stats.sin_problema || 0));
    $('#kpi_inconveniente').text(fmtNumber(stats.inconveniente || 0));
    $('#kpi_critico').text(fmtNumber(stats.falla || 0));

    $('#last-update').text(new Date().toLocaleString());

    // ---------- ECharts: Personas en estadio vs estimadas ----------
    if (!chartPersons) {
      chartPersons = echarts.init(document.getElementById('chartPersons'));
    }

    const personsOption = {
      tooltip: { trigger: 'axis' },
      xAxis: {
        type: 'category',
        data: ['En Ruta', 'Estimadas en ruta', 'En Estadio']
      },
      yAxis: {
        type: 'value',
        min: 0
      },
      series: [{
        type: 'bar',
        data: [
          stats.personas_en_ruta || 0,
          stats.total_estimated || 0,
          stats.personas_en_estadio || 0
        ],
        label: {
          show: true,
          position: 'top'
        }
      }]
    };

    chartPersons.setOption(personsOption);

    // ---------- ECharts: Estado de las rutas ----------
    if (!chartStatus) {
      chartStatus = echarts.init(document.getElementById('chartStatus'));
    }

    const statusOption = {
      tooltip: {
        trigger: 'item',
        formatter: '{b}: {c} ({d}%)'
      },
      legend: {
        orient: 'horizontal',
        bottom: 0
      },
      color: [
        '#046205', // Sin problema
        '#ffa500', // Inconveniente
        '#ff004c'  // Crítico
      ],
      series: [{
        name: 'Estado',
        type: 'pie',
        radius: ['40%', '70%'],
        avoidLabelOverlap: false,
        label: {
          show: true,
          formatter: '{b}: {c}'
        },
        labelLine: { show: true },
        data: [
          { value: stats.sin_problema   || 0, name: 'Sin problema' },
          { value: stats.inconveniente  || 0, name: 'Inconveniente' },
          { value: stats.falla          || 0, name: 'Crítico' }
        ]
      }]
    };

    chartStatus.setOption(statusOption);

  } catch (err) {
    console.error(err);
  }
}

// Redimensionar ECharts al cambiar tamaño ventana
window.addEventListener('resize', () => {
  if (chartPersons) chartPersons.resize();
  if (chartStatus)  chartStatus.resize();
});

let dataTable = null;
async function renderRecentReports(){
  try {
    const rows = await fetchRecentReports(50);
    const tbody = $('#tblReports tbody');
    tbody.empty();
    rows.forEach((r, idx) => {
      const tr = $('<tr>');
      tr.append($('<td>').text(idx+1));
      tr.append($('<td>').text(r.ruta_nombre || r.idruta));
      tr.append($('<td>').text(r.placa || ''));
      tr.append($('<td>').text(r.encargado_nombre || ''));
      tr.append($('<td>').text(r.agente_nombre || ''));
      tr.append($('<td>').text(r.total_personas || 0));
      tr.append($('<td>').text(r.accion_nombre || ''));
      tr.append($('<td>').text(r.fecha_reporte || ''));
      tbody.append(tr);
    });
    if (dataTable) dataTable.destroy();
    dataTable = $('#tblReports').DataTable({
      paging: true,
      searching: true,
      info: false,
      pageLength: 10,
      order: [[7, 'desc']]
    });
  } catch (err) {
    console.error(err);
  }
}

let mapInstance = null;
let markers = [];
let routeLines = [];

function colorForStatus(status){
  if (status === 'sin_problema')  return '#046205'; // verde oscuro
  if (status === 'inconveniente') return '#ffa500'; // naranja
  if (status === 'critico')       return '#ff004c'; // rojo fuerte
  return '#6c757d'; // gris por defecto
}

async function renderMap(){
  try {
    const routes = await fetchMapData();
    const mapEl = document.getElementById('map');

    if (!routes || routes.length === 0) {
      mapInstance = new google.maps.Map(mapEl, {
        center: { lat: 13.6929, lng: -89.2182 },
        zoom: 8
      });
      return;
    }

    // Centro inicial: primera parada de la primera ruta
    let centerLat = 13.6929;
    let centerLng = -89.2182;
    if (routes[0].path && routes[0].path.length > 0) {
      centerLat = parseFloat(routes[0].path[0].lat);
      centerLng = parseFloat(routes[0].path[0].lng);
    }

    mapInstance = new google.maps.Map(mapEl, {
      center: { lat: centerLat, lng: centerLng },
      zoom: 8
    });

    // Limpiar marcadores y polilíneas anteriores
    markers.forEach(m => m.setMap(null));
    markers = [];
    routeLines.forEach(l => l.setMap(null));
    routeLines = [];

    const bounds = new google.maps.LatLngBounds();
    const directionsService = new google.maps.DirectionsService();

    routes.forEach(rt => {
      const status = rt.status || 'sin_problema';
      const color  = colorForStatus(status);

      const pathCoords = (rt.path || []).map(p => ({
        lat: parseFloat(p.lat),
        lng: parseFloat(p.lng)
      }));

      // Dibujar ruta siguiendo calles con Directions
      if (pathCoords.length >= 2) {
        const origin      = pathCoords[0];
        const destination = pathCoords[pathCoords.length - 1];
        const waypoints   = pathCoords.slice(1, -1).map(p => ({
          location: p,
          stopover: false
        }));

        directionsService.route(
          {
            origin,
            destination,
            waypoints,
            travelMode: google.maps.TravelMode.DRIVING,
            optimizeWaypoints: false
          },
          (result, statusDir) => {
            if (statusDir === google.maps.DirectionsStatus.OK && result.routes.length > 0) {
              const routePath = result.routes[0].overview_path;

              const polyline = new google.maps.Polyline({
                path: routePath,
                geodesic: true,
                strokeColor: color,
                strokeOpacity: 0.9,
                strokeWeight: 3
              });
              polyline.setMap(mapInstance);
              routeLines.push(polyline);

              routePath.forEach(pt => bounds.extend(pt));
              if (!bounds.isEmpty()) {
                mapInstance.fitBounds(bounds);
              }
            }
          }
        );
      }

      // Marcador en la última parada registrada
      let markerPos = null;
      if (pathCoords.length > 0) {
        markerPos = pathCoords[pathCoords.length - 1];
      }
      if (!markerPos) return;

      const pinSvg = {
        path: "M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7z",
        fillColor: color,
        fillOpacity: 1,
        strokeColor: "#ffffff",
        strokeWeight: 2,
        scale: 1.2,
        anchor: new google.maps.Point(12, 22)
      };

      const marker = new google.maps.Marker({
        position: markerPos,
        map: mapInstance,
        icon: pinSvg,
        title: `Ruta ${rt.ruta_nombre} - ${rt.total_personas || 0} personas`
      });

      const info = `
        <div>
          <strong>Ruta:</strong> ${rt.ruta_nombre}<br/>
          <strong>Placa:</strong> ${rt.placa || '-'}<br/>
          <strong>Personas:</strong> ${rt.total_personas || 0}<br/>
          <strong>Estado:</strong> ${status}<br/>
          <small>${rt.accion_nombre || ''}</small>
        </div>
      `;
      const infow = new google.maps.InfoWindow({ content: info });

      marker.addListener('click', () => {
        infow.open({
          anchor: marker,
          map: mapInstance
        });
      });

      markers.push(marker);
      bounds.extend(markerPos);
    });

    if (!bounds.isEmpty()) {
      mapInstance.fitBounds(bounds);
    }

  } catch (err) {
    console.error('Error renderMap:', err);
  }
}
["f_departamento", "f_tipo_accion", "f_idruta"].forEach(id => {
    document.getElementById(id).addEventListener("change", async () => {
        await renderMap();
    });
});

async function cargarFiltros() {
    const res = await fetch(apiBase + 'filters');
    const data = await res.json();

    if (!data.success) return;

    // --- Departamento ---
    const selDep = document.getElementById("f_departamento");
    selDep.innerHTML = '<option value="">Todos</option>';
    data.departamentos.forEach(d => {
        selDep.innerHTML += `<option value="${d}">${d}</option>`;
    });

    // --- Ruta ---
    const selRuta = document.getElementById("f_idruta");
    selRuta.innerHTML = '<option value="">Todas</option>';
    data.rutas.forEach(r => {
        selRuta.innerHTML += `<option value="${r.idruta}">Ruta ${r.idruta} - ${r.nombre}</option>`;
    });
}

async function initDashboard(){
  await renderKpisAndChart();
  await renderRecentReports();
  await renderMap();
  await cargarFiltros();

  setInterval(async () => {
  await renderKpisAndChart();
  await renderRecentReports();
  await renderMap();
}, 60000);

}

document.addEventListener('DOMContentLoaded', initDashboard);
</script>

<?php
require __DIR__ . '/../templates/footer.php';
