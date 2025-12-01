<?php
// dashboard_global.php
// Requiere que ../includes/db.php defina $pdo como PDO
require_once __DIR__ . '/../includes/db.php';
header('Content-Type: application/json; charset=utf-8');

// Si viene 'action' procesamos JSON; si no, mostramos HTML (content-type cambiado abajo).
$action = isset($_GET['action']) ? $_GET['action'] : null;

if ($action === 'stats') {
    try {
        // 1) total_reported: suma de total_personas del ÚLTIMO reporte por ruta
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
        $sqlEstimated = "
            SELECT COALESCE(SUM(p.estimado_personas),0) AS total_estimated
            FROM paradas p
            INNER JOIN ruta r ON r.idruta = p.idruta
            WHERE r.activa = 1
        ";
        $total_estimated = (int)$pdo->query($sqlEstimated)->fetchColumn();

        // 3) routes_active: rutas con activa = 1
        $sql_routes_active = "SELECT COUNT(*) FROM ruta WHERE activa = 1";
        $routes_active = (int)$pdo->query($sql_routes_active)->fetchColumn();

        // 4) routes_total: total de rutas
        $sql_routes_total = "SELECT COUNT(*) FROM ruta";
        $routes_total = (int)$pdo->query($sql_routes_total)->fetchColumn();

        // 5) Estados de rutas según ÚLTIMO reporte
        $sql_status_counts = "
            SELECT
                SUM(CASE WHEN lr.idaccion IS NULL THEN 1 ELSE 0 END) AS sin_problema,
                SUM(CASE WHEN lr.idaccion IS NOT NULL
                         AND LOWER(a.tipo_accion) = 'critico'
                         THEN 1 ELSE 0 END) AS falla,
                SUM(CASE WHEN lr.idaccion IS NOT NULL
                         AND LOWER(a.tipo_accion) = 'inconveniente'
                         THEN 1 ELSE 0 END) AS inconveniente
            FROM ruta r
            LEFT JOIN (
                SELECT r1.idruta, r1.idaccion
                FROM reporte r1
                INNER JOIN (
                    SELECT idruta, MAX(fecha_reporte) AS max_fecha
                    FROM reporte
                    GROUP BY idruta
                ) mx ON r1.idruta = mx.idruta AND r1.fecha_reporte = mx.max_fecha
            ) lr ON lr.idruta = r.idruta
            LEFT JOIN acciones a ON lr.idaccion = a.idaccion
        ";
        $stmt = $pdo->query($sql_status_counts);
        $status = $stmt->fetch(PDO::FETCH_ASSOC) ?: [
            'sin_problema'  => 0,
            'inconveniente' => 0,
            'falla'         => 0,
        ];

        $resp = [
            'total_reported'  => $total_reported,
            'total_estimated' => $total_estimated,
            'routes_active'   => $routes_active,
            'routes_total'    => $routes_total,
            'sin_problema'    => (int)$status['sin_problema'],
            'inconveniente'   => (int)$status['inconveniente'],
            'falla'           => (int)$status['falla'],
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
            LEFT JOIN paradas p ON p.idruta = r.idruta AND p.orden = 1
            LEFT JOIN bus b ON b.idbus = r.idbus
            LEFT JOIN acciones a ON a.idaccion = rep.idaccion
        ";
        $stmt = $pdo->query($sql);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $features = [];
        foreach ($rows as $row) {
            if (!$row['latitud'] || !$row['longitud']) {
                continue;
            }
            $features[] = [
                'idruta'         => (int)$row['idruta'],
                'ruta_nombre'    => $row['ruta_nombre'],
                'placa'          => $row['placa'],
                'conductor'      => $row['conductor'],
                'total_personas' => (int)$row['total_personas'],
                'total_becarios' => (int)$row['total_becarios'],
                'total_menores12'=> (int)$row['total_menores12'],
                'fecha_reporte'  => $row['fecha_reporte'],
                'lat'            => (float)$row['latitud'],
                'lng'            => (float)$row['longitud'],
                'idaccion'       => $row['idaccion'] ? (int)$row['idaccion'] : null,
                'accion_nombre'  => $row['accion_nombre'],
                'tipo_accion'    => $row['tipo_accion'],
            ];
        }

        echo json_encode($features);
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Error en map: ' . $e->getMessage()]);
        exit;
    }
}

if ($action === 'recent') {
    // Últimos N reportes (para DataTable)
    try {
        $sql = "
            SELECT 
                rep.idreporte,
                rep.idruta,
                r.nombre AS ruta_nombre,
                rep.total_personas,
                rep.total_becarios,
                rep.total_menores12,
                rep.comentario,
                rep.fecha_reporte,
                a.nombre AS accion_nombre,
                a.tipo_accion
            FROM reporte rep
            INNER JOIN ruta r      ON r.idruta = rep.idruta
            LEFT JOIN acciones a   ON a.idaccion = rep.idaccion
            ORDER BY rep.fecha_reporte DESC
            LIMIT 50
        ";
        $stmt = $pdo->query($sql);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($rows);
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Error en recent: ' . $e->getMessage()]);
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
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
  <link rel="stylesheet"
        href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
  <style>
    body {
      background: #f4f6f9;
    }
    .kpi-card {
      border-radius: 12px;
      padding: 16px;
      color: #fff;
      margin-bottom: 16px;
      box-shadow: 0 2px 6px rgba(0,0,0,0.1);
    }
    .kpi-title {
      font-size: 14px;
      opacity: 0.9;
    }
    .kpi-value {
      font-size: 28px;
      font-weight: 700;
    }
    .kpi-icon {
      font-size: 32px;
      opacity: 0.4;
    }
    #map {
      width: 100%;
      height: 400px;
      border-radius: 12px;
      border: 1px solid #ddd;
    }
  </style>
</head>
<body>
<div class="container-fluid mt-3">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <div>
      <h2>Centro de Monitoreo - Dashboard Global</h2>
      <p class="text-muted mb-0">Resumen en tiempo real de rutas, personas y eventos</p>
    </div>
    <div class="text-right">
      <small>Última actualización: <span id="last-update"></span></small>
    </div>
  </div>

  <div class="row">
    <!-- Total personas (último reporte) -->
    <div class="col-md-2">
      <div class="kpi-card" style="background:#008080;">
        <div class="d-flex justify-content-between">
          <div>
            <div class="kpi-title">Total personas (último reporte)</div>
            <div id="kpi_total_reported" class="kpi-value">0</div>
          </div>
          <div class="kpi-icon">👥</div>
        </div>
      </div>
    </div>
    <!-- Total estimado (paradas) -->
    <div class="col-md-2">
      <div class="kpi-card" style="background:#0099CC;">
        <div class="d-flex justify-content-between">
          <div>
            <div class="kpi-title">Total estimado (paradas)</div>
            <div id="kpi_total_estimated" class="kpi-value">0</div>
          </div>
          <div class="kpi-icon">👥</div>
        </div>
      </div>
    </div>
    <!-- Rutas activas -->
    <div class="col-md-2">
      <div class="kpi-card" style="background:#3F51B5;">
        <div class="d-flex justify-content-between">
          <div>
            <div class="kpi-title">Rutas activas</div>
            <div id="kpi_routes_active" class="kpi-value">0 / 0</div>
          </div>
          <div class="kpi-icon">🗺️</div>
        </div>
      </div>
    </div>
    <!-- Sin problema -->
    <div class="col-md-2">
      <div class="kpi-card" style="background:#4CAF50;">
        <div class="d-flex justify-content-between">
          <div>
            <div class="kpi-title">Sin problema</div>
            <div id="kpi_sin_problema" class="kpi-value">0</div>
          </div>
          <div class="kpi-icon">✅</div>
        </div>
      </div>
    </div>
    <!-- Inconveniente + Falla -->
    <div class="col-md-2">
      <div class="kpi-card" style="background:#FFC107;">
        <div class="d-flex justify-content-between">
          <div>
            <div class="kpi-title">Inconveniente + Crítico</div>
            <div id="kpi_inc_falla" class="kpi-value">0</div>
          </div>
          <div class="kpi-icon">⚠️</div>
        </div>
      </div>
    </div>
    <!-- Falla solo (crítico) -->
    <div class="col-md-2">
      <div class="kpi-card" style="background:#F44336;">
        <div class="d-flex justify-content-between">
          <div>
            <div class="kpi-title">Crítico</div>
            <div id="kpi_critico" class="kpi-value">0</div>
          </div>
          <div class="kpi-icon">🚨</div>
        </div>
      </div>
    </div>
  </div>

  <!-- Gráfico personas -->
  <div class="row mt-3">
    <div class="col-md-6">
      <div class="card">
        <div class="card-header">
          Personas reportadas vs estimadas
        </div>
        <div class="card-body">
          <canvas id="chartPersons"></canvas>
        </div>
      </div>
    </div>

    <!-- Mapa -->
    <div class="col-md-6">
      <div class="card">
        <div class="card-header">Mapa de rutas</div>
        <div class="card-body">
          <div id="map"></div>
        </div>
      </div>
    </div>
  </div>

  <!-- Tabla de últimos reportes -->
  <div class="row mt-3">
    <div class="col-12">
      <div class="card">
        <div class="card-header">Últimos reportes</div>
        <div class="card-body">
          <table id="recentReports" class="display" style="width:100%">
            <thead>
            <tr>
              <th>Fecha</th>
              <th>Ruta</th>
              <th>Acción</th>
              <th>Tipo</th>
              <th>Total personas</th>
              <th>Becarios</th>
              <th>Menores 12</th>
              <th>Comentario</th>
            </tr>
            </thead>
            <tbody></tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

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
  if(!res.ok) throw new Error('Error al obtener stats');
  return await res.json();
}

async function fetchRecent(){
  const res = await fetch(apiBase + 'recent');
  if(!res.ok) throw new Error('Error al obtener recientes');
  return await res.json();
}

async function fetchMap(){
  const res = await fetch(apiBase + 'map');
  if(!res.ok) throw new Error('Error al obtener mapa');
  return await res.json();
}

/* RENDER KPIs y gráfico */
let chartPersons = null;

async function renderKpisAndChart(){
  try {
    const stats = await fetchStats();
    document.getElementById('kpi_total_reported').innerText = fmtNumber(stats.total_reported || 0);
    document.getElementById('kpi_total_estimated').innerText = fmtNumber(stats.total_estimated || 0);
    document.getElementById('kpi_routes_active').innerText =
      fmtNumber(stats.routes_active || 0) + ' / ' + fmtNumber(stats.routes_total || 0);
    document.getElementById('kpi_sin_problema').innerText = fmtNumber(stats.sin_problema || 0);
    const inc_total = (stats.inconveniente || 0) + (stats.falla || 0);
    document.getElementById('kpi_inc_falla').innerText = fmtNumber(inc_total);
    document.getElementById('kpi_critico').innerText = fmtNumber(stats.falla || 0);

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
          legend: { position: 'top' },
          tooltip: { enabled: true }
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
    const rows = await fetchRecent();
    if (dataTable) {
      dataTable.clear();
      dataTable.rows.add(rows);
      dataTable.draw();
    } else {
      dataTable = $('#recentReports').DataTable({
        data: rows,
        columns: [
          { data: 'fecha_reporte' },
          { data: 'ruta_nombre' },
          { data: 'accion_nombre' },
          { data: 'tipo_accion' },
          { data: 'total_personas' },
          { data: 'total_becarios' },
          { data: 'total_menores12' },
          { data: 'comentario' }
        ]
      });
    }
  } catch (err) {
    console.error(err);
  }
}

/* RENDER MAPA */
let map;
let markers = [];

function getMarkerColor(tipo_accion){
  if (!tipo_accion) return 'blue';
  const t = tipo_accion.toLowerCase();
  if (t === 'critico') return 'red';
  if (t === 'inconveniente') return 'orange';
  return 'green';
}

function createMarkerIcon(color){
  const colors = {
    red: 'http://maps.google.com/mapfiles/ms/icons/red-dot.png',
    orange: 'http://maps.google.com/mapfiles/ms/icons/orange-dot.png',
    green: 'http://maps.google.com/mapfiles/ms/icons/green-dot.png',
    blue: 'http://maps.google.com/mapfiles/ms/icons/blue-dot.png'
  };
  return colors[color] || colors.blue;
}

async function renderMap(){
  try {
    const data = await fetchMap();
    const center = { lat: 13.6985560, lng: -89.2154501 };

    if (!map) {
      map = new google.maps.Map(document.getElementById('map'), {
        center,
        zoom: 8
      });
    }

    // limpiar markers actuales
    markers.forEach(m => m.setMap(null));
    markers = [];

    data.forEach(item => {
      const color = getMarkerColor(item.tipo_accion);
      const marker = new google.maps.Marker({
        position: { lat: item.lat, lng: item.lng },
        map,
        icon: createMarkerIcon(color),
        title: item.ruta_nombre
      });

      const info = new google.maps.InfoWindow({
        content: `
          <div style="font-size:13px;">
            <strong>${item.ruta_nombre}</strong><br>
            Placa: ${item.placa || '-'}<br>
            Conductor: ${item.conductor || '-'}<br>
            Personas: ${item.total_personas || 0}<br>
            Becarios: ${item.total_becarios || 0}<br>
            Menores 12: ${item.total_menores12 || 0}<br>
            Acción: ${item.accion_nombre || 'Sin reporte'}<br>
            Tipo: ${item.tipo_accion || '-'}<br>
            Fecha: ${item.fecha_reporte || '-'}
          </div>
        `
      });

      marker.addListener('click', () => {
        info.open(map, marker);
      });

      markers.push(marker);
    });

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
