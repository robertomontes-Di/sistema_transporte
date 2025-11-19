<?php
// rutas_dashboard.php
// Requiere: ../includes/db.php que defina $pdo (PDO)
// Ejemplo: $pdo = new PDO($dsn, $user, $pass, $opts);

require_once __DIR__ . '/../includes/db.php';
if (!isset($pdo) || !($pdo instanceof PDO)) {
    // Si quieres lanzar error en desarrollo:
    // die("La variable \$pdo no está definida. Asegúrate de que includes/db.php crea \$pdo (PDO).");
}

// Determinar action
$action = isset($_GET['action']) ? $_GET['action'] : null;
header('Content-Type: application/json; charset=utf-8');

try {
    if ($action === 'list_routes') {
        // Lista de rutas con estado y último reporte resumido
        // Filtros opcionales: placa, idruta, estado
        $params = [];
        $where = [];

        if (!empty($_GET['placa'])) {
            $where[] = 'b.placa ILIKE :placa';
            $params[':placa'] = '%' . $_GET['placa'] . '%';
        }
        if (!empty($_GET['idruta'])) {
            $where[] = 'r.idruta = :idruta';
            $params[':idruta'] = (int)$_GET['idruta'];
        }
        if (!empty($_GET['estado'])) {
            // estado puede ser sin_problema, inconveniente, falla
            // We'll filter by joining last report and acciones
            // We'll handle in SQL using CASE similar to below
            $estado = $_GET['estado'];
        } else {
            $estado = null;
        }

        // SQL: get route basic info + last report aggregated fields
        $sql = "
            SELECT r.idruta, r.nombre AS ruta_nombre, r.destino, r.flag_arrival,
                   b.idbus, b.placa, b.capacidad AS bus_capacidad,
                   er.nombre AS encargado_nombre,
                   lr.total_personas, lr.total_becarios, lr.total_menores12, lr.fecha_reporte,
                   lr.idaccion, a.nombre AS accion_nombre, a.tipo_accion
            FROM ruta r
            LEFT JOIN bus b ON r.idbus = b.idbus
            LEFT JOIN encargado_ruta er ON r.idencargado_ruta = er.idencargado_ruta
            LEFT JOIN (
                SELECT rp1.*
                FROM reporte rp1
                INNER JOIN (
                    SELECT idruta, MAX(fecha_reporte) AS mx
                    FROM reporte
                    GROUP BY idruta
                ) x ON rp1.idruta = x.idruta AND rp1.fecha_reporte = x.mx
            ) lr ON lr.idruta = r.idruta
            LEFT JOIN acciones a ON lr.idaccion = a.idaccion
        ";

        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $stmt = $pdo->prepare($sql);
        foreach ($params as $k => $v) $stmt->bindValue($k, $v);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Map status and some computed metrics
        $out = [];
        foreach ($rows as $r) {
            $status = 'sin_problema';
            $accion_nombre = strtolower($r['accion_nombre'] ?? '');
            $tipo = strtolower($r['tipo_accion'] ?? '');
            if (!empty($r['idaccion'])) {
                if ($tipo === 'falla' || strpos($accion_nombre, 'falla') !== false || strpos($accion_nombre, 'accident') !== false || strpos($accion_nombre, 'accidente') !== false) {
                    $status = 'falla';
                } else {
                    $status = 'inconveniente';
                }
            }
            // % uso bus (si capacidad disponible)
            $cap = isset($r['bus_capacidad']) && $r['bus_capacidad'] > 0 ? (int)$r['bus_capacidad'] : null;
            $ocup = isset($r['total_personas']) ? (int)$r['total_personas'] : 0;
            $pct_uso = $cap ? round(($ocup / $cap) * 100, 1) : null;

            $out[] = [
                'idruta' => (int)$r['idruta'],
                'ruta_nombre' => $r['ruta_nombre'],
                'destino' => $r['destino'],
                'flag_arrival' => (int)$r['flag_arrival'],
                'placa' => $r['placa'],
                'bus_capacidad' => $cap,
                'ocupado_ultimo' => $ocup,
                'pct_uso' => $pct_uso,
                'encargado' => $r['encargado_nombre'],
                'fecha_reporte' => $r['fecha_reporte'],
                'status' => $status,
                'accion_nombre' => $r['accion_nombre']
            ];
        }

        // Optionally filter by estado param
        if ($estado) {
            $out = array_values(array_filter($out, function($x) use ($estado) {
                return $x['status'] === $estado;
            }));
        }

        echo json_encode($out);
        exit;
    }

    if ($action === 'route_detail') {
        // Detalle completo de una ruta: idruta requerido
        if (empty($_GET['idruta'])) {
            throw new Exception("idruta requerido");
        }
        $idruta = (int)$_GET['idruta'];

        // 1) Basic info + last report
        $sql = "
            SELECT r.idruta, r.nombre AS ruta_nombre, r.destino, r.flag_arrival,
                   b.idbus, b.placa, b.capacidad AS bus_capacidad, b.tipo AS bus_tipo,
                   er.nombre AS encargado_nombre, er.telefono AS encargado_telefono,
                   lr.*, a.nombre AS accion_nombre, a.tipo_accion
            FROM ruta r
            LEFT JOIN bus b ON r.idbus = b.idbus
            LEFT JOIN encargado_ruta er ON r.idencargado_ruta = er.idencargado_ruta
            LEFT JOIN (
                SELECT rp1.*
                FROM reporte rp1
                WHERE rp1.idruta = :idruta
                ORDER BY rp1.fecha_reporte DESC
                LIMIT 1
            ) lr ON lr.idruta = r.idruta
            LEFT JOIN acciones a ON lr.idaccion = a.idaccion
            WHERE r.idruta = :idruta2
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':idruta', $idruta, PDO::PARAM_INT);
        $stmt->bindValue(':idruta2', $idruta, PDO::PARAM_INT);
        $stmt->execute();
        $basic = $stmt->fetch(PDO::FETCH_ASSOC);

        // 2) Paradas de la ruta (ordered) + agregado de personas por parada (último reporte que refiera a la parada)
        $sqlPar = "
            SELECT p.idparada, p.punto_abordaje, p.orden, p.latitud, p.longitud, p.estimado_personas,
                   -- último reporte que usó esta parada (si existe)
                   lr.total_personas AS personas_ultimo_reporte,
                   lr.fecha_reporte AS fecha_reporte_parada
            FROM paradas p
            LEFT JOIN (
                SELECT r1.idparada, r1.total_personas, r1.fecha_reporte
                FROM reporte r1
                INNER JOIN (
                    SELECT idparada, MAX(fecha_reporte) AS mx
                    FROM reporte
                    WHERE idparada IS NOT NULL
                    GROUP BY idparada
                ) x ON r1.idparada = x.idparada AND r1.fecha_reporte = x.mx
            ) lr ON lr.idparada = p.idparada
            WHERE p.idruta = :idr
            ORDER BY p.orden ASC
        ";
        $stmt = $pdo->prepare($sqlPar);
        $stmt->bindValue(':idr', $idruta, PDO::PARAM_INT);
        $stmt->execute();
        $paradas = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 3) Incidencias (reportes con idaccion) recientes para la ruta
        $sqlInc = "
            SELECT rep.idreporte, rep.fecha_reporte, rep.idaccion, a.nombre AS accion_nombre, rep.comentario, ag.nombre AS agente_nombre, er.nombre AS encargado_nombre, rep.total_personas
            FROM reporte rep
            LEFT JOIN acciones a ON rep.idaccion = a.idaccion
            LEFT JOIN agente ag ON rep.idagente = ag.idagente
            LEFT JOIN encargado_ruta er ON rep.idencargado_ruta = er.idencargado_ruta
            WHERE rep.idruta = :idruta AND rep.idaccion IS NOT NULL
            ORDER BY rep.fecha_reporte DESC
            LIMIT 100
        ";
        $stmt = $pdo->prepare($sqlInc);
        $stmt->bindValue(':idruta', $idruta, PDO::PARAM_INT);
        $stmt->execute();
        $incidencias = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 4) Timeline (todos los reportes de la ruta ordenados)
        $sqlTime = "
            SELECT rep.idreporte, rep.fecha_reporte, rep.idparada, p.punto_abordaje, rep.total_personas, rep.comentario, a.nombre AS accion_nombre
            FROM reporte rep
            LEFT JOIN paradas p ON rep.idparada = p.idparada
            LEFT JOIN acciones a ON rep.idaccion = a.idaccion
            WHERE rep.idruta = :idruta
            ORDER BY rep.fecha_reporte ASC
        ";
        $stmt = $pdo->prepare($sqlTime);
        $stmt->bindValue(':idruta', $idruta, PDO::PARAM_INT);
        $stmt->execute();
        $timeline = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 5) Stats: personas totales (último por ruta), estimado total de paradas, % cumplimiento, uso bus
        $total_reported = isset($basic['total_personas']) ? (int)$basic['total_personas'] : 0;
        $sqlEstim = "SELECT COALESCE(SUM(estimado_personas),0) AS estimado_total FROM paradas WHERE idruta = :idr";
        $stmt = $pdo->prepare($sqlEstim);
        $stmt->bindValue(':idr', $idruta, PDO::PARAM_INT);
        $stmt->execute();
        $estim = (int)$stmt->fetchColumn();

        $pct_cumplimiento = $estim > 0 ? round(($total_reported / $estim) * 100, 1) : null;
        $bus_cap = isset($basic['bus_capacidad']) && $basic['bus_capacidad']>0 ? (int)$basic['bus_capacidad'] : null;
        $pct_uso_bus = $bus_cap ? round(($total_reported / $bus_cap) * 100, 1) : null;

        $resp = [
            'basic' => $basic,
            'paradas' => $paradas,
            'incidencias' => $incidencias,
            'timeline' => $timeline,
            'stats' => [
                'total_reported' => $total_reported,
                'estimado_total' => $estim,
                'pct_cumplimiento' => $pct_cumplimiento,
                'bus_capacidad' => $bus_cap,
                'pct_uso_bus' => $pct_uso_bus
            ]
        ];
        echo json_encode($resp);
        exit;
    }

    if ($action === 'route_map') {
        // puntos para mapa de una ruta (paradas + último punto de reporte)
        if (empty($_GET['idruta'])) throw new Exception("idruta requerido");
        $idruta = (int)$_GET['idruta'];

        $sql = "
            SELECT p.idparada, p.punto_abordaje, p.orden, p.latitud, p.longitud, p.estimado_personas
            FROM paradas p
            WHERE p.idruta = :idr
            ORDER BY p.orden ASC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':idr', $idruta, PDO::PARAM_INT);
        $stmt->execute();
        $paradas = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // ultimo punto de reporte (si tiene lat/lng stored in paradas via idparada). If you store GPS separately, adapt
        $sqlLast = "
            SELECT rep.*, p.latitud, p.longitud
            FROM reporte rep
            LEFT JOIN paradas p ON rep.idparada = p.idparada
            WHERE rep.idruta = :idruta
            ORDER BY rep.fecha_reporte DESC
            LIMIT 1
        ";
        $stmt = $pdo->prepare($sqlLast);
        $stmt->bindValue(':idruta', $idruta, PDO::PARAM_INT);
        $stmt->execute();
        $last = $stmt->fetch(PDO::FETCH_ASSOC);

        echo json_encode(['paradas' => $paradas, 'last_report' => $last]);
        exit;
    }

    if ($action === 'route_incidents') {
        // Incidencias (reportes con idaccion) global o por ruta
        $params = [];
        $sql = "
            SELECT rep.idreporte, rep.idruta, r.nombre AS ruta_nombre, rep.fecha_reporte, rep.idaccion, a.nombre AS accion_nombre, rep.comentario, ag.nombre AS agente_nombre, er.nombre AS encargado_nombre
            FROM reporte rep
            LEFT JOIN acciones a ON rep.idaccion = a.idaccion
            LEFT JOIN ruta r ON rep.idruta = r.idruta
            LEFT JOIN agente ag ON rep.idagente = ag.idagente
            LEFT JOIN encargado_ruta er ON rep.idencargado_ruta = er.idencargado_ruta
            WHERE rep.idaccion IS NOT NULL
        ";
        if (!empty($_GET['idruta'])) {
            $sql .= " AND rep.idruta = :idruta";
            $params[':idruta'] = (int)$_GET['idruta'];
        }
        $sql .= " ORDER BY rep.fecha_reporte DESC LIMIT 200";
        $stmt = $pdo->prepare($sql);
        if (isset($params[':idruta'])) $stmt->bindValue(':idruta', $params[':idruta'], PDO::PARAM_INT);
        $stmt->execute();
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }

    // Default: if no action, return HTML page (change header)
    header('Content-Type: text/html; charset=utf-8');
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Dashboard de Rutas</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <!-- Bootstrap 5 -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <!-- DataTables -->
  <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
  <style>
    body { padding: 18px; background:#f4f6f8; }
    .card-route { border-radius:12px; box-shadow:0 1px 6px rgba(0,0,0,0.06); }
    #map { height: 420px; width:100%; border-radius:8px; }
    .small-muted { font-size:0.85rem; color:#6c757d; }
    .status-dot { display:inline-block; width:10px; height:10px; border-radius:50%; margin-right:6px; }
  </style>
</head>
<body>
<div class="container-fluid">
  <div class="row mb-3 align-items-center">
    <div class="col">
      <h4>Dashboard de Rutas</h4>
      <p class="small-muted">Lista y detalle de rutas, mapa, paradas y incidencias</p>
    </div>
    <div class="col-auto">
      <button id="btnRefresh" class="btn btn-outline-primary">Refrescar</button>
    </div>
  </div>

  <!-- FILTROS -->
  <div class="row mb-3">
    <div class="col-md-3">
      <input id="filterPlaca" class="form-control" placeholder="Filtrar por placa">
    </div>
    <div class="col-md-3">
      <select id="filterEstado" class="form-select">
        <option value="">Todos los estados</option>
        <option value="sin_problema">Sin problema</option>
        <option value="inconveniente">Con inconveniente</option>
        <option value="falla">Con falla</option>
      </select>
    </div>
    <div class="col-md-2">
      <button id="btnApply" class="btn btn-primary">Aplicar</button>
    </div>
  </div>

  <div class="row">
    <!-- Left: lista de rutas -->
    <div class="col-lg-5 mb-3">
      <div class="card card-route p-3">
        <h6>Lista de Rutas</h6>
        <table id="tblRutas" class="display" style="width:100%">
          <thead>
            <tr><th>#</th><th>Ruta</th><th>Placa</th><th>Encargado</th><th>Personas</th><th>%Uso</th><th>Estado</th><th>Acciones</th></tr>
          </thead>
          <tbody></tbody>
        </table>
      </div>
    </div>

    <!-- Right: detalle + mapa -->
    <div class="col-lg-7 mb-3">
      <div class="card card-route p-3 mb-3" id="detalleRutaCard" style="display:none;">
        <div class="d-flex justify-content-between">
          <div>
            <h5 id="det_ruta_nombre">Ruta</h5>
            <div class="small-muted" id="det_destino">Destino</div>
          </div>
          <div class="text-end">
            <div class="small-muted">Encargado</div>
            <div id="det_encargado" style="font-weight:600;"></div>
          </div>
        </div>

        <hr>
        <div class="row">
          <div class="col-md-6">
            <div><strong>Último reporte:</strong> <span id="det_fecha_reporte"></span></div>
            <div><strong>Personas (último):</strong> <span id="det_total_personas">0</span></div>
            <div><strong>Estimado total paradas:</strong> <span id="det_estimado">0</span></div>
            <div><strong>% Cumplimiento:</strong> <span id="det_pct_cumpl">0</span></div>
            <div><strong>Capacidad bus:</strong> <span id="det_bus_cap">-</span></div>
            <div><strong>% Uso bus:</strong> <span id="det_pct_uso">-</span></div>
          </div>
          <div class="col-md-6">
            <div id="map"></div>
          </div>
        </div>
      </div>

      <div class="card card-route p-3">
        <h6>Timeline de Reportes</h6>
        <div id="timeline" style="max-height:220px; overflow:auto;"></div>
      </div>

      <div class="card card-route p-3 mt-3">
        <h6>Incidencias</h6>
        <table id="tblIncidents" class="display" style="width:100%">
          <thead><tr><th>Fecha</th><th>Acción</th><th>Comentario</th><th>Usuario</th></tr></thead>
          <tbody></tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- Dependencias JS -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>


<script src="https://maps.googleapis.com/maps/api/js?key=AIzaSyC2Zm7v7BAdKaA-KAvna0q4y0lQgwvE1V4"></script>

<script>
const apiBase = location.pathname + '?action=';

function colorForStatus(s){
  if(s === 'sin_problema') return '#2b7cff';
  if(s === 'inconveniente') return '#f5a623';
  if(s === 'falla') return '#e53935';
  return '#6c757d';
}

async function fetchRoutes(placa='', estado=''){
  let url = apiBase + 'list_routes';
  if(placa) url += '&placa=' + encodeURIComponent(placa);
  if(estado) url += '&estado=' + encodeURIComponent(estado);
  const r = await fetch(url);
  if(!r.ok) throw new Error('Error fetching routes');
  return r.json();
}

async function fetchRouteDetail(idruta){
  const r = await fetch(apiBase + 'route_detail&idruta=' + parseInt(idruta));
  if(!r.ok) throw new Error('Error fetching route detail');
  return r.json();
}

let dtRutas = null;
let dtInc = null;
let map = null;
let markers = [];

async function renderRoutes(){
  try {
    const placa = document.getElementById('filterPlaca').value.trim();
    const estado = document.getElementById('filterEstado').value;
    const data = await fetchRoutes(placa, estado);
    const tbody = $('#tblRutas tbody').empty();
    data.forEach((r, idx) => {
      const tr = $('<tr>');
      tr.append($('<td>').text(idx+1));
      tr.append($('<td>').html(`<strong>${r.ruta_nombre}</strong><div class="small-muted">${r.destino||''}</div>`));
      tr.append($('<td>').text(r.placa || ''));
      tr.append($('<td>').text(r.encargado || ''));
      tr.append($('<td>').text(r.ocupado_ultimo || 0));
      tr.append($('<td>').text((r.pct_uso !== null ? r.pct_uso + '%' : '-')));
      const st = $('<td>');
      st.html(`<span class="status-dot" style="background:${colorForStatus(r.status)}"></span>${r.status}`);
      tr.append(st);
      const btns = $('<td>');
      const btn = $(`<button class="btn btn-sm btn-primary">Ver</button>`);
      btn.on('click', ()=> openDetail(r.idruta));
      btns.append(btn);
      tr.append(btns);
      tbody.append(tr);
    });
    if(dtRutas) dtRutas.destroy();
    dtRutas = $('#tblRutas').DataTable({ paging: true, pageLength: 8, info:false, order: [] });
  } catch (err) {
    console.error(err);
  }
}

async function openDetail(idruta){
  try {
    const det = await fetchRouteDetail(idruta);
    $('#detalleRutaCard').show();
    $('#det_ruta_nombre').text(det.basic.ruta_nombre || 'Ruta #' + idruta);
    $('#det_destino').text(det.basic.destino || '');
    $('#det_encargado').text((det.basic.encargado_nombre || '') + (det.basic.encargado_telefono ? ' • ' + det.basic.encargado_telefono : ''));
    $('#det_fecha_reporte').text(det.basic.fecha_reporte || '-');
    $('#det_total_personas').text(det.stats.total_reported || 0);
    $('#det_estimado').text(det.stats.estimado_total || 0);
    $('#det_pct_cumpl').text(det.stats.pct_cumplimiento !== null ? det.stats.pct_cumplimiento + '%' : '-');
    $('#det_bus_cap').text(det.stats.bus_capacidad || '-');
    $('#det_pct_uso').text(det.stats.pct_uso_bus !== null ? det.stats.pct_uso_bus + '%' : '-');

    // Construir timeline
    const tl = $('#timeline').empty();
    det.timeline.forEach(it => {
      const node = $(`
        <div class="mb-2">
          <div><strong>${it.punto_abordaje || ('Parada ' + (it.idparada||''))}</strong> <small class="small-muted">[${it.fecha_reporte}]</small></div>
          <div>Personas: ${it.total_personas || 0} ${it.accion_nombre ? ' • Acción: '+it.accion_nombre : ''}</div>
          <div class="small-muted">${it.comentario || ''}</div>
        </div>
      `);
      tl.append(node);
    });

    // Incidencias table
    const tbodyInc = $('#tblIncidents tbody').empty();
    det.incidencias.forEach(i => {
      const tr = $('<tr>');
      tr.append($('<td>').text(i.fecha_reporte));
      tr.append($('<td>').text(i.accion_nombre || ''));
      tr.append($('<td>').text(i.comentario || ''));
      tr.append($('<td>').text(i.agente_nombre || i.encargado_nombre || ''));
      tbodyInc.append(tr);
    });
    if(dtInc) dtInc.destroy();
    dtInc = $('#tblIncidents').DataTable({ paging:true, pageLength:5, info:false, order:[[0,'desc']] });

    // Mapa: show paradas + last point
    renderMap(det.paradas, det.basic);
  } catch (err) {
    console.error(err);
  }
}

function clearMarkers(){
  markers.forEach(m => m.setMap(null));
  markers = [];
}

function renderMap(paradas, basic){
  try {
    clearMarkers();
    // create or recenter map
    if(!map) {
      map = new google.maps.Map(document.getElementById('map'), {
        center: { lat: 13.6929, lng: -89.2182 },
        zoom: 12
      });
    }
    const bounds = new google.maps.LatLngBounds();
    paradas.forEach(p => {
      if(!p.latitud || !p.longitud) return;
      const pos = { lat: parseFloat(p.latitud), lng: parseFloat(p.longitud) };
      const marker = new google.maps.Marker({
        position: pos,
        map: map,
        title: p.punto_abordaje || ('Parada ' + p.idparada)
      });
      const info = `<div><strong>${p.punto_abordaje || 'Parada'}</strong><br/>Estimado: ${p.estimado_personas || 0}<br/>Personas último rep: ${p.personas_ultimo_reporte || 0}</div>`;
      const iw = new google.maps.InfoWindow({ content: info });
      marker.addListener('click', ()=> iw.open({ anchor: marker, map: map }));
      markers.push(marker);
      bounds.extend(pos);
    });

    // if basic has last report lat/lng add a special marker
    if(basic && basic.idparada && basic.latitud && basic.longitud){
      // Not used because we fetched last report separately; if present:
    }

    if(!bounds.isEmpty()) map.fitBounds(bounds);
  } catch (err) {
    console.error(err);
  }
}

document.getElementById('btnApply').addEventListener('click', renderRoutes);
document.getElementById('btnRefresh').addEventListener('click', renderRoutes);

$(document).ready(function(){
  renderRoutes();
});
</script>
</body>
</html>
