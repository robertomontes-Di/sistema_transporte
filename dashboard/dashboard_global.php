<?php
// dashboard_global.php
// Requiere que ../includes/db.php defina $pdo como PDO
require_once __DIR__ . '/../includes/db.php';
header('Content-Type: application/json; charset=utf-8');

// --- UTIL: obtener último reporte por ruta (subconsulta usada en varias consultas) ---
// Si viene 'action' procesamos JSON; si no, mostramos HTML (content-type cambiado abajo).
$action = isset($_GET['action']) ? $_GET['action'] : null;

if ($action === 'stats') {
    // KPIs: total_personas (sum of latest report per route), estimated persons (sum of paradas.estimado_personas),
    // rutas sin problema / con inconveniente / con falla (based on latest report.idaccion and acciones.tipo_accion)
    try {
        // 1) total_reported: sum of total_personas from the latest report per route
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

        // 2) total_estimated: sum of estimado_personas from paradas (all paradas)
        // If you want only upcoming paradas or per route, adjust accordingly.
        $sql_total_estimated = "SELECT COALESCE(SUM(estimado_personas),0) FROM paradas";
        $total_estimated = (int)$pdo->query($sql_total_estimated)->fetchColumn();

        // 3) total_routes_active: routes where flag_arrival = 0 (0 = en camino; 1 = llegó)
        $sql_routes_active = "SELECT COUNT(*) FROM ruta WHERE flag_arrival = 0";
        $routes_active = (int)$pdo->query($sql_routes_active)->fetchColumn();

        // 4) routes status counts (sin problema / con inconveniente / con falla)
        // Approach: classify using latest report per route and joining acciones.
        // Assumptions:
        // - If latest report.idaccion IS NULL => "sin_problema"
        // - Else, if acciones.tipo_accion = 'falla' OR acciones.nombre ilike '%falla%' => "falla"
        // - Else => "inconveniente"
        // Modify the string checks to match your data.
        $sql_status_counts = "
           SELECT
    SUM(CASE WHEN a.tipo_accion = 'normal' THEN 1 ELSE 0 END) AS sin_problema,
    SUM(CASE WHEN a.tipo_accion = 'inconveniente' THEN 1 ELSE 0 END) AS inconveniente,
    SUM(CASE WHEN a.tipo_accion = 'critico' THEN 1 ELSE 0 END) AS critico
FROM reporte r
INNER JOIN acciones a ON a.idaccion = r.idaccion;
        ";
        $stmt = $pdo->query($sql_status_counts);
        $status = $stmt->fetch(PDO::FETCH_ASSOC);

        // 5) total_routes_overall (total rutas)
        $sql_total_routes = "SELECT COUNT(*) FROM ruta";
        $total_routes = (int)$pdo->query($sql_total_routes)->fetchColumn();

        // Prepare response
        $resp = [
            'total_reported' => $total_reported,
            'total_estimated' => $total_estimated,
            'routes_active' => $routes_active,
            'routes_total' => $total_routes,
            'sin_problema' => (int)$status['sin_problema'],
            'inconveniente' => (int)$status['inconveniente'],
            'critico' => (int)$status['critico'],
        ];
        echo json_encode($resp);
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Error en stats: ' . $e->getMessage()]);
        exit;
    }
}

if ($action === 'map') {
    // Devuelve puntos para el mapa: para cada ruta su último reporte (si existe) y coordenadas (parada)
    try {
        $sql = "
            SELECT r.idruta, r.nombre AS ruta_nombre, b.placa, b.conductor,
                   rep.total_personas, rep.total_becarios, rep.total_menores12,
                   rep.fecha_reporte, p.latitud, p.longitud, rep.idaccion, a.nombre AS accion_nombre, a.tipo_accion
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
        $stmt = $pdo->query($sql);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // For each row, compute a "status" string: sin_problema / inconveniente / falla (same logic as stats)
        $out = [];
        foreach ($rows as $row) {
            $status = '';
            if (!empty($row['idaccion'])) {                
                $tipo = strtolower($row['tipo_accion'] ?? '');
                if ($tipo === 'critico' ) {
                    $status = 'critico';
                }elseif($tipo === 'normal' ) {
                    $status = 'sin_problema';
                }                
                else {
                    $status = 'inconveniente';
                }
            }
            $out[] = [
                'idruta' => $row['idruta'],
                'ruta_nombre' => $row['ruta_nombre'],
                'placa' => $row['placa'],
                'conductor' => $row['conductor'],
                'total_personas' => (int)$row['total_personas'],
                'total_becarios' => (int)$row['total_becarios'],
                'fecha_reporte' => $row['fecha_reporte'],
                'lat' => $row['latitud'],
                'lng' => $row['longitud'],
                'status' => $status,
                'accion_nombre' => $row['accion_nombre']
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

if ($action === 'recent_reports') {
    // Devuelve últimos N reportes para la tabla operativa (join con ruta, bus, encargado, agente, paradas, acciones)
    $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 50;
    try {
        $sql = "
            SELECT rep.idreporte, rep.idruta, r.nombre AS ruta_nombre, b.placa, er.nombre AS encargado_nombre,
                   ag.nombre AS agente_nombre, rep.total_personas, rep.total_becarios, rep.total_menores12,
                   rep.comentario, rep.fecha_reporte, p.punto_abordaje, p.orden, a.nombre AS accion_nombre
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
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($rows);
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Error en recent_reports: ' . $e->getMessage()]);
        exit;
    }
}

// Si no viene action, servimos la página HTML (content-type texto)
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Centro de Monitoreo - Dashboard Global</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <!-- Bootstrap 5 CDN -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <!-- DataTables CSS (opcional) -->
  <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
  <style>
    body { padding: 20px; background:#f8f9fa; }
    .kpi-card { border-radius: 14px; padding: 18px; background: #fff; box-shadow: 0 1px 4px rgba(0,0,0,0.08); }
    .kpi-value { font-size: 28px; font-weight:700; }
    #map { width:100%; height:420px; border-radius:8px; }
  </style>
</head>
<body>
<div class="container-fluid">
  <div class="row mb-3">
    <div class="col-8">
      <h3>Centro de Monitoreo - Dashboard Global</h3>
      <p class="text-muted">Resumen en tiempo real de rutas, personas y eventos</p>
    </div>
    <div class="col-4 text-end">
      <small class="text-muted">Última actualización: <span id="last-update">—</span></small>
    </div>
  </div>

  <!-- KPIs -->
  <div class="row g-3 mb-4">
    <div class="col-md-2">
      <div class="kpi-card">
        <div class="text-muted">Total personas (último reporte)</div>
        <div class="kpi-value" id="kpi_total_reported">0</div>
      </div>
    </div>
    <div class="col-md-2">
      <div class="kpi-card">
        <div class="text-muted">Total estimado (paradas)</div>
        <div class="kpi-value" id="kpi_total_estimated">0</div>
      </div>
    </div>
    <div class="col-md-2">
      <div class="kpi-card">
        <div class="text-muted">Rutas activas</div>
        <div class="kpi-value" id="kpi_routes_active">0</div>
      </div>
    </div>
    <div class="col-md-2">
      <div class="kpi-card">
        <div class="text-muted">Sin problema</div>
        <div class="kpi-value text-success" id="kpi_sin_problema">0</div>
      </div>
    </div>
    <div class="col-md-2">
      <div class="kpi-card">
        <div class="text-muted">Con inconveniente</div>
        <div class="kpi-value" id="kpi_inconveniente">0</div>
      </div>
    </div>
     <div class="col-md-2">
      <div class="kpi-card">
        <div class="text-muted">Crítico</div>
        <div class="kpi-value" id="kpi_critico">0</div>
      </div>
    </div>
  </div>

  <!-- Chart + Map -->
  <div class="row mb-4">
    <div class="col-lg-5">
      <div class="card p-3">
        <h6>Total personas: Reportadas vs Estimadas</h6>
        <canvas id="chartPersons" height="200"></canvas>
      </div>
      <div class="mt-3 card p-3">
        <h6>Últimos reportes (tabla)</h6>
        <table id="tblReports" class="display" style="width:100%">
          <thead>
            <tr><th>#</th><th>Ruta</th><th>Placa</th><th>Encargado</th><th>Agente</th><th>Personas</th><th>Acción</th><th>Fecha</th></tr>
          </thead>
          <tbody></tbody>
        </table>
      </div>
    </div>

    <div class="col-lg-7">
      <div class="card p-3">
        <h6>Mapa de rutas (último punto conocido)</h6>
        <div id="map"></div>
      </div>
    </div>
  </div>

</div>

<!-- Dependencias JS -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>


<script src="https://maps.googleapis.com/maps/api/js?key=AIzaSyC2Zm7v7BAdKaA-KAvna0q4y0lQgwvE1V4"></script>

<script>
const apiBase = location.pathname + '?action=';

function fmtNumber(n){
  return n.toLocaleString();
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

/* RENDER KPIs + chart */
let chartPersons = null;
async function renderKpisAndChart(){
  try {
    const stats = await fetchStats();
    document.getElementById('kpi_total_reported').innerText = fmtNumber(stats.total_reported || 0);
    document.getElementById('kpi_total_estimated').innerText = fmtNumber(stats.total_estimated || 0);
    document.getElementById('kpi_routes_active').innerText = fmtNumber(stats.routes_active || 0);
    document.getElementById('kpi_sin_problema').innerText = fmtNumber(stats.sin_problema || 0);
    const inc_total = (stats.inconveniente || 0) + (stats.falla || 0);
    document.getElementById('kpi_inconveniente').innerText = fmtNumber(stats.inconveniente || 0);
    document.getElementById('kpi_critico').innerText = fmtNumber(stats.critico || 0);

    document.getElementById('last-update').innerText = new Date().toLocaleString();

    // Chart: Reported vs Estimated
    const ctx = document.getElementById('chartPersons').getContext('2d');
    const labels = ['Personas'];
    const data = {
      labels,
      datasets: [
        { label: 'Reportadas', data: [stats.total_reported || 0] },
        { label: 'Estimadas', data: [stats.total_estimated || 0] }
      ]
    };
    if(chartPersons) chartPersons.destroy();
    chartPersons = new Chart(ctx, {
      type: 'bar',
      data,
      options: {
        responsive: true,
        plugins: {
          legend: { position: 'top' }
        },
        scales: {
          x: { stacked: false },
          y: { beginAtZero: true }
        }
      }
    });

  } catch (err) {
    console.error(err);
  }
}

/* RENDER DataTable of recent reports */
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

/* RENDER Map */
let mapInstance = null;
let markers = [];
function colorForStatus(status){
  if(status === 'sin_problema') return '#39ff14'; // azul
  if(status === 'inconveniente') return '#ffa500'; // amarillo
  if(status === 'critico') return '#ff004c'; // rojo
  return '#6c757d';
}
async function renderMap(){
  try {
    const pts = await fetchMapData();
    if (!pts || pts.length === 0) {
      // initialize default map
      mapInstance = new google.maps.Map(document.getElementById('map'), {
        center: { lat: 13.6929, lng: -89.2182 }, // centro aproximado El Salvador, ajústalo
        zoom: 8
      });
      return;
    }

    const first = pts[0];
    mapInstance = new google.maps.Map(document.getElementById('map'), {
      center: { lat: parseFloat(first.lat), lng: parseFloat(first.lng) },
      zoom: 8
    });

    // clear markers
    markers.forEach(m => m.setMap(null));
    markers = [];

    pts.forEach(pt => {
      const color = colorForStatus(pt.status);
      const circle = {
        path: google.maps.SymbolPath.CIRCLE,
        scale: Math.max(8, Math.min(30, (pt.total_personas || 1) / 3)), // tamaño relativo
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
      marker.addListener('click', ()=> infow.open({ anchor: marker, map: mapInstance }));
      markers.push(marker);
    });

    // fit bounds
    const bounds = new google.maps.LatLngBounds();
    markers.forEach(m => bounds.extend(m.position));
    mapInstance.fitBounds(bounds);

  } catch (err) {
    console.error(err);
  }
}

/* Inicialización */
async function initDashboard(){
  await renderKpisAndChart();
  await renderRecentReports();
  await renderMap();
  // Auto-refresh cada 60s (opcional)
  setInterval(async () => {
    await renderKpisAndChart();
    await renderRecentReports();
    await renderMap();
  }, 60000);
}

document.addEventListener('DOMContentLoaded', initDashboard);
</script>
</body>
</html>
