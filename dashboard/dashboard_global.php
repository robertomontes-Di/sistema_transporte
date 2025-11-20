<?php
// dashboard_global.php

require_once __DIR__ . '/../includes/db.php';

// Detectar si es una llamada API (JSON) o carga normal de página
$action = $_GET['action'] ?? null;

if ($action) {
    // MODO API JSON
    header('Content-Type: application/json; charset=utf-8');

    // -----------------------------------------------------------------
    // STATS
    // -----------------------------------------------------------------
    if ($action === 'stats') {
        try {
            // 1) total_reported: suma de total_personas del último reporte por ruta
            $sql_total_reported = "
                SELECT COALESCE(SUM(rp.total_personas),0) AS total_reported
                FROM (
                    SELECT r1.idruta, r1.total_personas
                    FROM reporte r1
                    INNER JOIN (
                        SELECT idruta, MAX(fecha_reporte) AS max_fecha
                        FROM reporte
                        GROUP BY idruta
                    ) mx ON r1.idruta = mx.idruta AND r1.fecha_reporte = mx.max_fecha
                ) rp
            ";
            $total_reported = (int)$pdo->query($sql_total_reported)->fetchColumn();

            // 2) total_estimated: suma de estimado_personas en todas las paradas
            $sql_total_estimated = "SELECT COALESCE(SUM(estimado_personas),0) FROM paradas";
            $total_estimated = (int)$pdo->query($sql_total_estimated)->fetchColumn();

            // 3) rutas activas: flag_arrival = 0
            $sql_routes_active = "SELECT COUNT(*) FROM ruta WHERE flag_arrival = 0";
            $routes_active = (int)$pdo->query($sql_routes_active)->fetchColumn();

            // 4) conteo de rutas por estado, usando el ÚLTIMO reporte por ruta
            //    y la convención de tu compañero:
            //    tipo_accion = normal       → sin_problema
            //    tipo_accion = inconveniente → inconveniente
            //    tipo_accion = critico      → critico
            $sql_status_counts = "
                SELECT
                    SUM(
                        CASE 
                          WHEN lr.idaccion IS NULL 
                               OR a.tipo_accion = 'normal'
                               OR a.tipo_accion IS NULL
                          THEN 1 ELSE 0 
                        END
                    ) AS sin_problema,
                    SUM(
                        CASE 
                          WHEN a.tipo_accion = 'inconveniente' 
                          THEN 1 ELSE 0 
                        END
                    ) AS inconveniente,
                    SUM(
                        CASE 
                          WHEN a.tipo_accion = 'critico' 
                          THEN 1 ELSE 0 
                        END
                    ) AS critico
                FROM (
                    SELECT r1.idruta, r1.idaccion
                    FROM reporte r1
                    INNER JOIN (
                        SELECT idruta, MAX(fecha_reporte) AS max_fecha
                        FROM reporte
                        GROUP BY idruta
                    ) mx ON r1.idruta = mx.idruta AND r1.fecha_reporte = mx.max_fecha
                ) lr
                LEFT JOIN acciones a ON lr.idaccion = a.idaccion
            ";
            $status = $pdo->query($sql_status_counts)->fetch(PDO::FETCH_ASSOC) ?: [
                'sin_problema'  => 0,
                'inconveniente' => 0,
                'critico'       => 0,
            ];

            // 5) total de rutas
            $sql_total_routes = "SELECT COUNT(*) FROM ruta";
            $total_routes = (int)$pdo->query($sql_total_routes)->fetchColumn();

            echo json_encode([
                'total_reported'  => $total_reported,
                'total_estimated' => $total_estimated,
                'routes_active'   => $routes_active,
                'routes_total'    => $total_routes,
                'sin_problema'    => (int)($status['sin_problema'] ?? 0),
                'inconveniente'   => (int)($status['inconveniente'] ?? 0),
                'critico'         => (int)($status['critico'] ?? 0),
            ]);
            exit;
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Error en stats: ' . $e->getMessage()]);
            exit;
        }
    }

    // -----------------------------------------------------------------
    // MAP
    // -----------------------------------------------------------------
    if ($action === 'map') {
        try {
            $sql = "
                SELECT r.idruta, r.nombre AS ruta_nombre, b.placa, b.conductor,
                       rep.total_personas, rep.total_becarios, rep.total_menores12,
                       rep.fecha_reporte, p.latitud, p.longitud,
                       rep.idaccion, a.nombre AS accion_nombre, a.tipo_accion
                FROM ruta r
                LEFT JOIN (
                    -- último reporte por ruta
                    SELECT r1.*
                    FROM reporte r1
                    INNER JOIN (
                        SELECT idruta, MAX(fecha_reporte) AS max_fecha
                        FROM reporte
                        GROUP BY idruta
                    ) mx ON r1.idruta = mx.idruta AND r1.fecha_reporte = mx.max_fecha
                ) rep ON rep.idruta = r.idruta
                LEFT JOIN paradas p ON rep.idparada = p.idparada
                LEFT JOIN acciones a ON rep.idaccion = a.idaccion
                LEFT JOIN bus b ON r.idbus = b.idbus
                WHERE p.latitud IS NOT NULL AND p.longitud IS NOT NULL
            ";
            $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

            $out = [];
            foreach ($rows as $row) {
                // Clasificación de estado compatible con backend de tu compañero
                // tipo_accion = critico → 'critico'
                // tipo_accion = normal o idaccion NULL → 'sin_problema'
                // otro (inconveniente, etc.) → 'inconveniente'
                $status = 'sin_problema';
                if (!empty($row['idaccion'])) {
                    $tipo = strtolower($row['tipo_accion'] ?? '');
                    if ($tipo === 'critico') {
                        $status = 'critico';
                    } elseif ($tipo === 'normal' || $tipo === '') {
                        $status = 'sin_problema';
                    } else {
                        $status = 'inconveniente';
                    }
                }

                $out[] = [
                    'idruta'         => $row['idruta'],
                    'ruta_nombre'    => $row['ruta_nombre'],
                    'placa'          => $row['placa'],
                    'conductor'      => $row['conductor'],
                    'total_personas' => (int)$row['total_personas'],
                    'total_becarios' => (int)$row['total_becarios'],
                    'fecha_reporte'  => $row['fecha_reporte'],
                    'lat'            => $row['latitud'],
                    'lng'            => $row['longitud'],
                    'status'         => $status,
                    'accion_nombre'  => $row['accion_nombre'],
                ];
            }
            echo json_encode($out);
            exit;
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Error en map: ' . $e->getMessage()]);
            exit;
        }
    }

    // -----------------------------------------------------------------
    // RECENT REPORTS
    // -----------------------------------------------------------------
    if ($action === 'recent_reports') {
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
        try {
            $sql = "
                SELECT rep.idreporte, rep.idruta, r.nombre AS ruta_nombre,
                       b.placa, er.nombre AS encargado_nombre,
                       ag.nombre AS agente_nombre, rep.total_personas,
                       rep.total_becarios, rep.total_menores12,
                       rep.comentario, rep.fecha_reporte,
                       p.punto_abordaje, p.orden, a.nombre AS accion_nombre
                FROM reporte rep
                LEFT JOIN ruta r ON rep.idruta = r.idruta
                LEFT JOIN bus b ON r.idbus = b.idbus
                LEFT JOIN encargado_ruta er ON r.idencargado_ruta = er.idencargado_ruta
                LEFT JOIN agente ag ON rep.idagente = ag.idagente
                LEFT JOIN paradas p ON rep.idparada = p.idparada
                LEFT JOIN acciones a ON rep.idaccion = a.idaccion
                ORDER BY rep.fecha_reporte DESC
                LIMIT :lim
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
            $stmt->execute();
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
            exit;
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Error en recent_reports: ' . $e->getMessage()]);
            exit;
        }
    }

    // Si action no coincide, 400
    http_response_code(400);
    echo json_encode(['error' => 'action inválida']);
    exit;
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
    <div class="row">
      <div class="col-md-2">
        <div class="small-box bg-white">
          <div class="inner">
            <p class="text-muted mb-1">Total personas (último reporte)</p>
            <h3 id="kpi_total_reported">0</h3>
          </div>
          <div class="icon">
            <i class="fas fa-users"></i>
          </div>
        </div>
      </div>
      <div class="col-md-2">
        <div class="small-box bg-white">
          <div class="inner">
            <p class="text-muted mb-1">Total estimado (paradas)</p>
            <h3 id="kpi_total_estimated">0</h3>
          </div>
          <div class="icon">
            <i class="fas fa-user-friends"></i>
          </div>
        </div>
      </div>
      <div class="col-md-2">
        <div class="small-box bg-white">
          <div class="inner">
            <p class="text-muted mb-1">Rutas activas</p>
            <h3 id="kpi_routes_active">0</h3>
          </div>
          <div class="icon">
            <i class="fas fa-route"></i>
          </div>
        </div>
      </div>
      <div class="col-md-2">
        <div class="small-box bg-white">
          <div class="inner">
            <p class="text-muted mb-1">Sin problema</p>
            <h3 class="text-success" id="kpi_sin_problema">0</h3>
          </div>
          <div class="icon">
            <i class="fas fa-check-circle"></i>
          </div>
        </div>
      </div>
      <div class="col-md-2">
        <div class="small-box bg-white">
          <div class="inner">
            <p class="text-muted mb-1">Inconveniente</p>
            <h3 class="text-warning" id="kpi_inconveniente">0</h3>
          </div>
          <div class="icon">
            <i class="fas fa-exclamation-circle"></i>
          </div>
        </div>
      </div>
      <div class="col-md-2">
        <div class="small-box bg-white">
          <div class="inner">
            <p class="text-muted mb-1">Crítico</p>
            <h3 class="text-danger" id="kpi_critico">0</h3>
          </div>
          <div class="icon">
            <i class="fas fa-exclamation-triangle"></i>
          </div>
        </div>
      </div>
    </div>

    <!-- Gráficas + Tabla + Mapa -->
    <div class="row">
      <div class="col-lg-5">

        <div class="card">
          <div class="card-header">
            <h3 class="card-title">Total personas: Reportadas vs Estimadas</h3>
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

        <div class="card">
          <div class="card-header">
            <h3 class="card-title">Últimos reportes</h3>
          </div>
          <div class="card-body p-1">
            <div class="table-responsive">
              <table id="tblReports" class="table table-striped table-md mb-0">
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

      <div class="col-lg-7">
        <div class="card">
          <div class="card-header">
            <h3 class="card-title">Mapa de rutas (último punto conocido)</h3>
          </div>
          <div class="card-body">
            <div id="map" style="width:100%; height:420px; border-radius:8px;"></div>
          </div>
        </div>
      </div>
    </div>

  </div><!-- /.container-fluid -->
</section>
<!-- /.content -->

<!-- Scripts específicos de esta página -->
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://maps.googleapis.com/maps/api/js?key=AIzaSyC2Zm7v7BAdKaA-KAvna0q4y0lQgwvE1V4"></script>

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
  const res = await fetch(apiBase + 'map');
  if(!res.ok) throw new Error('Error fetching map');
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

    // Actualizar KPIs
    $('#kpi_total_reported').text(fmtNumber(stats.total_reported));
    $('#kpi_total_estimated').text(fmtNumber(stats.total_estimated));
    $('#kpi_routes_active').text(fmtNumber(stats.routes_active));
    $('#kpi_sin_problema').text(fmtNumber(stats.sin_problema));
    $('#kpi_inconveniente').text(fmtNumber(stats.inconveniente));
    $('#kpi_critico').text(fmtNumber(stats.critico));
    $('#last-update').text(new Date().toLocaleString());

    // ---------- ECharts: Personas reportadas vs estimadas ----------
    if (!chartPersons) {
      chartPersons = echarts.init(document.getElementById('chartPersons'));
    }

    const personsOption = {
      tooltip: { trigger: 'axis' },
      xAxis: {
        type: 'category',
        data: ['Reportadas', 'Estimadas']
      },
      yAxis: {
        type: 'value',
        min: 0
      },
      series: [{
        type: 'bar',
        data: [
          stats.total_reported || 0,
          stats.total_estimated || 0
        ],
        label: {
          show: true,
          position: 'top'
        }
      }]
    };

    chartPersons.setOption(personsOption);

    // ---------- ECharts: Estado de las rutas (donut) ----------
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
          { value: stats.sin_problema || 0,    name: 'Sin problema' },
          { value: stats.inconveniente || 0,   name: 'Inconveniente' },
          { value: stats.critico || 0,         name: 'Crítico' }
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
function colorForStatus(status){
  if(status === 'sin_problema') return '#39ff14';   // verde fosfo
  if(status === 'inconveniente') return '#ffa500'; // naranja
  if(status === 'critico') return '#ff004c';       // rojo fuerte
  return '#6c757d';
}
async function renderMap(){
  try {
    const pts = await fetchMapData();
    if (!pts || pts.length === 0) {
      mapInstance = new google.maps.Map(document.getElementById('map'), {
        center: { lat: 13.6929, lng: -89.2182 },
        zoom: 8
      });
      return;
    }
    const first = pts[0];
    mapInstance = new google.maps.Map(document.getElementById('map'), {
      center: { lat: parseFloat(first.lat), lng: parseFloat(first.lng) },
      zoom: 8
    });

    markers.forEach(m => m.setMap(null));
    markers = [];

    pts.forEach(pt => {
      const color = colorForStatus(pt.status);
      const circle = {
        path: google.maps.SymbolPath.CIRCLE,
        scale: Math.max(8, Math.min(30, (pt.total_personas || 1) / 3)),
        fillColor: color,
        fillOpacity: 0.75,
        strokeColor: '#fff',
        strokeWeight: 1
      };
      const marker = new google.maps.Marker({
        position: { lat: parseFloat(pt.lat), lng: parseFloat(pt.lng) },
        map: mapInstance,
        icon: circle,
        title: `Ruta ${pt.ruta_nombre} - ${pt.total_personas || 0}`
      });
      const info = `
        <div>
          <strong>Ruta:</strong> ${pt.ruta_nombre}<br/>
          <strong>Placa:</strong> ${pt.placa || '-'}<br/>
          <strong>Personas:</strong> ${pt.total_personas || 0}<br/>
          <strong>Estado:</strong> ${pt.status}<br/>
          <small>${pt.accion_nombre || ''}</small>
        </div>
      `;
      const infow = new google.maps.InfoWindow({ content: info });
      marker.addListener('click', () => infow.open({ anchor: marker, map: mapInstance }));
      markers.push(marker);
    });

    const bounds = new google.maps.LatLngBounds();
    markers.forEach(m => bounds.extend(m.position));
    mapInstance.fitBounds(bounds);

  } catch (err) {
    console.error(err);
  }
}

async function initDashboard(){
  await renderKpisAndChart();
  await renderRecentReports();
  await renderMap();

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
