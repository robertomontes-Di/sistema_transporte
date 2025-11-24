<?php
// dashboard_global.php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/db.php';

$action = $_GET['action'] ?? null;

/**
 * Helper para clasificar estado de una ruta según tipo_accion del último reporte
 * - tipo_accion = 'critico'         → 'critico'
 * - tipo_accion = 'normal' o NULL   → 'sin_problema'
 * - cualquier otro                  → 'inconveniente'
 */
function mapStatusFromTipo(?string $tipoAccion): string {
    $tipo = strtolower($tipoAccion ?? '');
    if ($tipo === 'critico') {
        return 'critico';
    }
    if ($tipo === 'normal' || $tipo === '') {
        return 'sin_problema';
    }
    return 'inconveniente';
}

/**
 * Lee filtros desde GET:
 *   estados=sin_problema,inconveniente
 *   departamentos=San Salvador,Santa Ana
 */
function getFiltersFromRequest(): array {
    $estados = [];
    if (!empty($_GET['estados'])) {
        $estados = array_filter(array_map('trim', explode(',', $_GET['estados'])));
    }

    $departamentos = [];
    if (!empty($_GET['departamentos'])) {
        $departamentos = array_filter(array_map('trim', explode(',', $_GET['departamentos'])));
    }

    return [
        'estados'       => $estados,
        'departamentos' => $departamentos,
    ];
}

/* ============================================================
 *          MODO API JSON (?action=...)
 * ============================================================ */
if ($action) {
    header('Content-Type: application/json; charset=utf-8');

    $filters = getFiltersFromRequest();
    $filterEstados = $filters['estados'];
    $filterDeptos  = $filters['departamentos'];

    /* ---------------------------------------------------------
       A) ENDPOINT: STATS
       --------------------------------------------------------- */
    if ($action === 'stats') {
        try {
            // 1) Último reporte por ruta, con tipo_accion y datos básicos de ruta
            $sqlLastReports = "
                SELECT 
                    r.idruta,
                    r.departamento,
                    r.flag_arrival,
                    lr.total_personas,
                    a.tipo_accion
                FROM ruta r
                LEFT JOIN (
                    SELECT r1.idruta, r1.total_personas, r1.idaccion
                    FROM reporte r1
                    INNER JOIN (
                        SELECT idruta, MAX(fecha_reporte) AS max_fecha
                        FROM reporte
                        GROUP BY idruta
                    ) mx ON r1.idruta = mx.idruta AND r1.fecha_reporte = mx.max_fecha
                ) lr ON lr.idruta = r.idruta
                LEFT JOIN acciones a ON lr.idaccion = a.idaccion
            ";
            $lastReports = $pdo->query($sqlLastReports)->fetchAll(PDO::FETCH_ASSOC);

            // 2) Estimado por ruta (suma de estimado_personas en paradas)
            $sqlEstimadoRuta = "
                SELECT idruta, COALESCE(SUM(estimado_personas),0) AS estimado
                FROM paradas
                GROUP BY idruta
            ";
            $estimadoRows = $pdo->query($sqlEstimadoRuta)->fetchAll(PDO::FETCH_ASSOC);
            $estimadoPorRuta = [];
            foreach ($estimadoRows as $er) {
                $estimadoPorRuta[(int)$er['idruta']] = (int)$er['estimado'];
            }

            // Inicializar acumuladores
            $totalReported      = 0;
            $totalEstimated     = 0;
            $routesActive       = 0;
            $routesTotal        = 0;
            $personasEnRuta     = 0;
            $sinProblemaCount   = 0;
            $inconvenienteCount = 0;
            $criticoCount       = 0;

            foreach ($lastReports as $row) {
                $idruta       = (int)$row['idruta'];
                $departamento = $row['departamento'] ?? null;
                $flagArrival  = (int)($row['flag_arrival'] ?? 0);
                $totalRuta    = (int)($row['total_personas'] ?? 0);
                $tipoAccion   = $row['tipo_accion'] ?? null;

                $status = mapStatusFromTipo($tipoAccion);

                // Aplicar filtros por estado
                if (!empty($filterEstados) && !in_array($status, $filterEstados, true)) {
                    continue;
                }

                // Aplicar filtros por departamento
                if (!empty($filterDeptos) && !in_array((string)$departamento, $filterDeptos, true)) {
                    continue;
                }

                $routesTotal++;

                // Rutas activas = flag_arrival = 0
                if ($flagArrival == 0) {
                    $routesActive++;
                    // Personas en ruta actualmente: sumatoria del total_personas del último reporte de rutas activas
                    $personasEnRuta += $totalRuta;
                }

                // Total personas reportadas (último reporte por ruta)
                $totalReported += $totalRuta;

                // Total estimado (sumatorio de estimado_personas) SOLO de las rutas que pasan filtro
                if (isset($estimadoPorRuta[$idruta])) {
                    $totalEstimated += $estimadoPorRuta[$idruta];
                }

                // Contar estados
                if ($status === 'sin_problema') {
                    $sinProblemaCount++;
                } elseif ($status === 'critico') {
                    $criticoCount++;
                } else { // inconveniente
                    $inconvenienteCount++;
                }
            }

            // Gente que falta por llegar = estimado - personas_en_ruta (no menos de 0)
            $faltanPorLlegar = $totalEstimated - $personasEnRuta;
            if ($faltanPorLlegar < 0) {
                $faltanPorLlegar = 0;
            }

            echo json_encode([
                'total_reported'      => $totalReported,
                'total_estimated'     => $totalEstimated,
                'routes_active'       => $routesActive,
                'routes_total'        => $routesTotal,
                'sin_problema'        => $sinProblemaCount,
                'inconveniente'       => $inconvenienteCount,
                'critico'             => $criticoCount,
                'personas_en_ruta'    => $personasEnRuta,
                'faltan_por_llegar'   => $faltanPorLlegar,
            ]);
            exit;
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Error en stats: ' . $e->getMessage()]);
            exit;
        }
    }

    /* ---------------------------------------------------------
       B) ENDPOINT: MAP (último punto conocido por ruta)
       --------------------------------------------------------- */
    if ($action === 'map') {
        try {
            $sql = "
                SELECT 
                    r.idruta,
                    r.nombre AS ruta_nombre,
                    r.departamento,
                    b.placa,
                    b.conductor,
                    rep.total_personas,
                    rep.total_becarios,
                    rep.total_menores12,
                    rep.fecha_reporte,
                    p.latitud,
                    p.longitud,
                    rep.idaccion,
                    a.nombre AS accion_nombre,
                    a.tipo_accion
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
                LEFT JOIN paradas p ON rep.idparada = p.idparada
                LEFT JOIN acciones a ON rep.idaccion = a.idaccion
                LEFT JOIN bus b ON r.idbus = b.idbus
                WHERE p.latitud IS NOT NULL AND p.longitud IS NOT NULL
            ";
            $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

            $out = [];
            foreach ($rows as $row) {
                $status = mapStatusFromTipo($row['tipo_accion'] ?? null);
                $departamento = $row['departamento'] ?? null;

                // Filtro estado
                if (!empty($filterEstados) && !in_array($status, $filterEstados, true)) {
                    continue;
                }

                // Filtro departamento
                if (!empty($filterDeptos) && !in_array((string)$departamento, $filterDeptos, true)) {
                    continue;
                }

                $out[] = [
                    'idruta'         => $row['idruta'],
                    'ruta_nombre'    => $row['ruta_nombre'],
                    'departamento'   => $departamento,
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

    /* ---------------------------------------------------------
       C) ENDPOINT: RECENT REPORTS (tabla)
       --------------------------------------------------------- */
    if ($action === 'recent_reports') {
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
        try {
            $sql = "
                SELECT 
                    rep.idreporte, 
                    rep.idruta, 
                    r.nombre AS ruta_nombre,
                    r.departamento,
                    b.placa, 
                    er.nombre AS encargado_nombre,
                    ag.nombre AS agente_nombre, 
                    rep.total_personas,
                    rep.total_becarios, 
                    rep.total_menores12,
                    rep.comentario, 
                    rep.fecha_reporte,
                    p.punto_abordaje, 
                    p.orden, 
                    a.nombre AS accion_nombre,
                    a.tipo_accion
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

            $filtered = [];
            foreach ($rows as $row) {
                $status = mapStatusFromTipo($row['tipo_accion'] ?? null);
                $departamento = $row['departamento'] ?? null;

                if (!empty($filterEstados) && !in_array($status, $filterEstados, true)) {
                    continue;
                }
                if (!empty($filterDeptos) && !in_array((string)$departamento, $filterDeptos, true)) {
                    continue;
                }
                $filtered[] = $row;
            }

            echo json_encode($filtered);
            exit;
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Error en recent_reports: ' . $e->getMessage()]);
            exit;
        }
    }

    /* ---------------------------------------------------------
       D) ENDPOINT: LISTA DEPARTAMENTOS (para filtro múltiple)
       --------------------------------------------------------- */
    if ($action === 'departamentos') {
        try {
            $sql = "SELECT DISTINCT departamento FROM ruta WHERE departamento IS NOT NULL AND departamento <> '' ORDER BY departamento";
            $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN);

            echo json_encode([
                'success'       => true,
                'departamentos' => $rows ?: [],
            ]);
            exit;
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Error al cargar departamentos']);
            exit;
        }
    }

    // Si action no coincide
    http_response_code(400);
    echo json_encode(['error' => 'action inválida']);
    exit;
}

/* ============================================================
 *          MODO PÁGINA HTML (sin ?action=...)
 * ============================================================ */

$pageTitle   = 'Dashboard Global';
$currentPage = 'dashboard_global';

require __DIR__ . '/../templates/header.php';
?>

<!-- Content Header -->
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

    <!-- Filtros -->
    <div class="row mt-2">
      <!-- Filtro por estado -->
      <div class="col-md-6">
        <div class="card card-outline card-secondary">
          <div class="card-header py-2">
            <h3 class="card-title" style="font-size:0.95rem;">Filtros por estado</h3>
          </div>
          <div class="card-body py-2">
            <div class="form-check form-check-inline">
              <input class="form-check-input filter-estado" type="checkbox" id="estado_ok" value="sin_problema" checked>
              <label class="form-check-label" for="estado_ok">Sin problema</label>
            </div>
            <div class="form-check form-check-inline">
              <input class="form-check-input filter-estado" type="checkbox" id="estado_inc" value="inconveniente" checked>
              <label class="form-check-label" for="estado_inc">Inconveniente</label>
            </div>
            <div class="form-check form-check-inline">
              <input class="form-check-input filter-estado" type="checkbox" id="estado_crit" value="critico" checked>
              <label class="form-check-label" for="estado_crit">Crítico</label>
            </div>
          </div>
        </div>
      </div>

      <!-- Filtro por departamento (múltiple) -->
      <div class="col-md-6">
        <div class="card card-outline card-secondary">
          <div class="card-header py-2 d-flex justify-content-between align-items-center">
            <h3 class="card-title" style="font-size:0.95rem;">Filtro por departamento</h3>
            <button type="button" id="btnClearDeptos" class="btn btn-xs btn-outline-secondary">Limpiar</button>
          </div>
          <div class="card-body py-2">
            <select id="filterDeptos" class="form-control" multiple size="3">
              <!-- Se llena con AJAX -->
            </select>
            <small class="text-muted">Puedes seleccionar uno o varios departamentos.</small>
          </div>
        </div>
      </div>
    </div>

  </div>
</section>

<!-- Main content -->
<section class="content">
  <div class="container-fluid">

    <!-- KPIs -->
    <div class="row kpi-row">

      <div class="col-xl-2 col-md-4 col-sm-6 mb-3">
        <div class="kpi-tile kpi-teal">
          <div class="kpi-body">
            <div class="kpi-label">Total personas (último reporte)</div>
            <div class="kpi-value" id="kpi_total_reported">0</div>
          </div>
          <div class="kpi-icon">
            <i class="fas fa-users"></i>
          </div>
        </div>
      </div>

      <div class="col-xl-2 col-md-4 col-sm-6 mb-3">
        <div class="kpi-tile kpi-cyan">
          <div class="kpi-body">
            <div class="kpi-label">Total estimado (paradas)</div>
            <div class="kpi-value" id="kpi_total_estimated">0</div>
          </div>
          <div class="kpi-icon">
            <i class="fas fa-user-friends"></i>
          </div>
        </div>
      </div>

      <div class="col-xl-2 col-md-4 col-sm-6 mb-3">
        <div class="kpi-tile kpi-blue">
          <div class="kpi-body">
            <div class="kpi-label">Rutas activas</div>
            <div class="kpi-value" id="kpi_routes_active">0</div>
          </div>
          <div class="kpi-icon">
            <i class="fas fa-route"></i>
          </div>
        </div>
      </div>

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

      <!-- Nueva métrica: Personas en ruta -->
      <div class="col-xl-2 col-md-4 col-sm-6 mb-3">
        <div class="kpi-tile kpi-purple">
          <div class="kpi-body">
            <div class="kpi-label">Personas en ruta</div>
            <div class="kpi-value" id="kpi_personas_ruta">0</div>
          </div>
          <div class="kpi-icon">
            <i class="fas fa-bus-alt"></i>
          </div>
        </div>
      </div>

      <!-- Nueva métrica: Falta por llegar -->
      <div class="col-xl-2 col-md-4 col-sm-6 mb-3">
        <div class="kpi-tile kpi-dark">
          <div class="kpi-body">
            <div class="kpi-label">Faltan por llegar</div>
            <div class="kpi-value" id="kpi_faltan_llegar">0</div>
          </div>
          <div class="kpi-icon">
            <i class="fas fa-user-clock"></i>
          </div>
        </div>
      </div>

    </div>

    <!-- Gráficas + Mapa -->
    <div class="row mt-3">

      <!-- Columna izquierda: gráficos -->
      <div class="col-lg-5">

        <div class="card mb-3">
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

      </div>

      <!-- Columna derecha: mapa -->
      <div class="col-lg-7">
        <div class="card h-100">
          <div class="card-header">
            <h3 class="card-title">Mapa de rutas (último punto conocido)</h3>
          </div>
          <div class="card-body">
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
                    <th>Depto</th>
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

<!-- Scripts específicos de esta página -->
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://maps.googleapis.com/maps/api/js?key=AIzaSyC2Zm7v7BAdKaA-KAvna0q4y0lQgwvE1V4"></script>

<script>
const basePath = location.pathname;

// Construye la URL de la API con filtros actuales
function buildApiUrl(action, extraParams = {}) {
  const estados = [];
  document.querySelectorAll('.filter-estado:checked').forEach(chk => {
    estados.push(chk.value);
  });

  const deptSelect = document.getElementById('filterDeptos');
  let deptos = [];
  if (deptSelect) {
    deptos = Array.from(deptSelect.selectedOptions).map(o => o.value);
  }

  const params = new URLSearchParams();
  params.set('action', action);
  if (estados.length) {
    params.set('estados', estados.join(','));
  }
  if (deptos.length) {
    params.set('departamentos', deptos.join(','));
  }

  for (const [k, v] of Object.entries(extraParams)) {
    params.set(k, v);
  }

  return basePath + '?' + params.toString();
}

function fmtNumber(n){
  return (n || 0).toLocaleString();
}

async function fetchStats(){
  const res = await fetch(buildApiUrl('stats'));
  if(!res.ok) throw new Error('Error fetching stats');
  return res.json();
}
async function fetchMapData(){
  const res = await fetch(buildApiUrl('map'));
  if(!res.ok) throw new Error('Error fetching map');
  return res.json();
}
async function fetchRecentReports(limit=50){
  const res = await fetch(buildApiUrl('recent_reports', { limit }));
  if(!res.ok) throw new Error('Error fetching reports');
  return res.json();
}
async function fetchDepartamentos(){
  const res = await fetch(buildApiUrl('departamentos'));
  if(!res.ok) throw new Error('Error fetching departamentos');
  return res.json();
}

let chartPersons = null;
let chartStatus  = null;

async function renderKpisAndChart(){
  try {
    const stats = await fetchStats();

    // KPIs
    $('#kpi_total_reported').text(fmtNumber(stats.total_reported));
    $('#kpi_total_estimated').text(fmtNumber(stats.total_estimated));
    $('#kpi_routes_active').text(fmtNumber(stats.routes_active));
    $('#kpi_sin_problema').text(fmtNumber(stats.sin_problema));
    $('#kpi_inconveniente').text(fmtNumber(stats.inconveniente));
    $('#kpi_critico').text(fmtNumber(stats.critico));
    $('#kpi_personas_ruta').text(fmtNumber(stats.personas_en_ruta));
    $('#kpi_faltan_llegar').text(fmtNumber(stats.faltan_por_llegar));
    $('#last-update').text(new Date().toLocaleString());

    // Chart: Reportadas vs Estimadas
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

    // Chart: Estado de las rutas
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
          { value: stats.sin_problema || 0,  name: 'Sin problema' },
          { value: stats.inconveniente || 0, name: 'Inconveniente' },
          { value: stats.critico || 0,       name: 'Crítico' }
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
      tr.append($('<td>').text(r.departamento || ''));
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
      order: [[8, 'desc']]
    });
  } catch (err) {
    console.error(err);
  }
}

let mapInstance = null;
let markers = [];
function colorForStatus(status){
  if(status === 'sin_problema')  return '#046205'; // verde
  if(status === 'inconveniente') return '#ffa500'; // naranja
  if(status === 'critico')       return '#ff004c'; // rojo
  return '#6c757d';
}

async function renderMap(){
  try {
    const pts = await fetchMapData();
    const mapEl = document.getElementById('map');
    if (!mapEl) return;

    if (!pts || pts.length === 0) {
      mapInstance = new google.maps.Map(mapEl, {
        center: { lat: 13.6929, lng: -89.2182 },
        zoom: 8
      });
      return;
    }

    const first = pts[0];
    mapInstance = new google.maps.Map(mapEl, {
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
          <strong>Depto:</strong> ${pt.departamento || '-'}<br/>
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

/* Carga de departamentos para el filtro múltiple */
async function loadDepartamentosFilter() {
  try {
    const res = await fetchDepartamentos();
    if (!res.success) return;
    const sel = document.getElementById('filterDeptos');
    if (!sel) return;
    sel.innerHTML = '';
    (res.departamentos || []).forEach(d => {
      const opt = document.createElement('option');
      opt.value = d;
      opt.textContent = d;
      sel.appendChild(opt);
    });
  } catch (e) {
    console.error(e);
  }
}

let refreshTimer = null;

async function runDashboardOnce(){
  await renderKpisAndChart();
  await renderRecentReports();
  await renderMap();
}

async function initDashboard(){
  await loadDepartamentosFilter();
  await runDashboardOnce();

  if (refreshTimer) clearInterval(refreshTimer);
  refreshTimer = setInterval(runDashboardOnce, 60000);

  // Eventos de filtro
  document.querySelectorAll('.filter-estado').forEach(chk => {
    chk.addEventListener('change', () => {
      runDashboardOnce();
    });
  });
  const selDeptos = document.getElementById('filterDeptos');
  if (selDeptos) {
    selDeptos.addEventListener('change', () => {
      runDashboardOnce();
    });
  }
  const btnClear = document.getElementById('btnClearDeptos');
  if (btnClear && selDeptos) {
    btnClear.addEventListener('click', () => {
      Array.from(selDeptos.options).forEach(o => o.selected = false);
      runDashboardOnce();
    });
  }
}

document.addEventListener('DOMContentLoaded', initDashboard);
</script>

<?php
require __DIR__ . '/../templates/footer.php';
