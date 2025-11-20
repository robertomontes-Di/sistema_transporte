<?php
// dashboard/dashboard_rutas.php

require_once __DIR__ . '/../includes/db.php';

// Detectar si es llamada API (JSON) o carga normal HTML
$action = $_GET['action'] ?? null;

if ($action) {
    header('Content-Type: application/json; charset=utf-8');

    if ($action === 'routes') {
        try {
            // Filtros opcionales (por ahora sencillos)
            $params = [];
            $where  = [];

            if (!empty($_GET['idruta'])) {
                $where[] = 'r.idruta = :idruta';
                $params[':idruta'] = (int)$_GET['idruta'];
            }

            if (!empty($_GET['estado'])) {
                // estado: sin_problema, inconveniente, falla
                // Lo filtramos luego en PHP sobre el array ya clasificado
                $estadoFilter = $_GET['estado'];
            } else {
                $estadoFilter = null;
            }

            // Subconsulta: último reporte por ruta
            // y subconsulta: estimado total por ruta (suma de paradas)
            $sql = "
                SELECT 
                    r.idruta,
                    r.nombre AS ruta_nombre,
                    r.destino,
                    r.flag_arrival,
                    b.placa,
                    b.capacidad_asientos AS bus_capacidad,
                    er.nombre AS encargado_nombre,
                    lr.total_personas,
                    lr.fecha_reporte,
                    lr.idaccion,
                    a.nombre AS accion_nombre,
                    a.tipo_accion,
                    est.estimado_total
                FROM ruta r
                LEFT JOIN bus b 
                    ON r.idbus = b.idbus
                LEFT JOIN encargado_ruta er
                    ON r.idencargado_ruta = er.idencargado_ruta
                LEFT JOIN (
                    SELECT rp1.*
                    FROM reporte rp1
                    INNER JOIN (
                        SELECT idruta, MAX(fecha_reporte) AS mx
                        FROM reporte
                        GROUP BY idruta
                    ) x ON rp1.idruta = x.idruta AND rp1.fecha_reporte = x.mx
                ) lr ON lr.idruta = r.idruta
                LEFT JOIN acciones a 
                    ON lr.idaccion = a.idaccion
                LEFT JOIN (
                    SELECT idruta, SUM(estimado_personas) AS estimado_total
                    FROM paradas
                    GROUP BY idruta
                ) est ON est.idruta = r.idruta
            ";

            if (!empty($where)) {
                $sql .= ' WHERE ' . implode(' AND ', $where);
            }

            $stmt = $pdo->prepare($sql);
            foreach ($params as $k => $v) {
                $stmt->bindValue($k, $v);
            }
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $out = [];
            foreach ($rows as $r) {
                // Clasificar estado de la ruta según el último reporte
                $status = 'sin_problema';
                $accion_nombre = strtolower($r['accion_nombre'] ?? '');
                $tipo          = strtolower($r['tipo_accion'] ?? '');

                if (!empty($r['idaccion'])) {
                    if (
                        $tipo === 'falla' ||
                        strpos($accion_nombre, 'falla') !== false ||
                        strpos($accion_nombre, 'accidente') !== false ||
                        strpos($accion_nombre, 'accident') !== false
                    ) {
                        $status = 'falla';
                    } else {
                        $status = 'inconveniente';
                    }
                }

                $total_personas = isset($r['total_personas']) ? (int)$r['total_personas'] : 0;
                $estimado_total = isset($r['estimado_total']) ? (int)$r['estimado_total'] : 0;
                $bus_capacidad  = isset($r['bus_capacidad']) ? (int)$r['bus_capacidad'] : 0;

                // % cumplimiento sobre estimado de paradas
                $pct_cumpl = null;
                if ($estimado_total > 0) {
                    $pct_cumpl = round(($total_personas / $estimado_total) * 100, 1);
                }

                // % uso sobre capacidad del bus
                $pct_uso = null;
                if ($bus_capacidad > 0) {
                    $pct_uso = round(($total_personas / $bus_capacidad) * 100, 1);
                }

                $row = [
                    'idruta'          => (int)$r['idruta'],
                    'ruta_nombre'     => $r['ruta_nombre'],
                    'destino'         => $r['destino'],
                    'flag_arrival'    => (int)$r['flag_arrival'],
                    'placa'           => $r['placa'],
                    'encargado'       => $r['encargado_nombre'],
                    'total_personas'  => $total_personas,
                    'estimado_total'  => $estimado_total,
                    'bus_capacidad'   => $bus_capacidad,
                    'pct_cumpl'       => $pct_cumpl,
                    'pct_uso'         => $pct_uso,
                    'fecha_reporte'   => $r['fecha_reporte'],
                    'status'          => $status,
                    'accion_nombre'   => $r['accion_nombre'],
                ];

                // Filtro por estado (si se solicitó)
                if ($estadoFilter && $estadoFilter !== $status) {
                    continue;
                }

                $out[] = $row;
            }

            echo json_encode($out);
            exit;

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Error en routes: ' . $e->getMessage()]);
            exit;
        }
    }

    // Si action no coincide
    http_response_code(400);
    echo json_encode(['error' => 'action inválida']);
    exit;
}

// ---------------------------------------------------------------------
// MODO PÁGINA HTML
// ---------------------------------------------------------------------
$pageTitle   = 'Dashboard de Rutas';
$currentPage = 'dashboard_rutas';

require __DIR__ . '/../templates/header.php';
?>

<!-- Content Header -->
<section class="content-header">
  <div class="container-fluid">
    <div class="row mb-2">
      <div class="col-sm-6">
        <h1>Dashboard de Rutas</h1>
        <p class="text-muted mb-0">Resumen de cumplimiento y uso de capacidad por ruta.</p>
      </div>
      <div class="col-sm-6 text-right">
        <div class="form-inline justify-content-end">
          <label class="mr-2">Estado:</label>
          <select id="filtroEstado" class="form-control form-control-sm mr-2">
            <option value="">Todos</option>
            <option value="sin_problema">Sin problema</option>
            <option value="inconveniente">Inconveniente</option>
            <option value="falla">Falla</option>
          </select>
          <button id="btnRefresh" class="btn btn-sm btn-primary">
            <i class="fas fa-sync-alt mr-1"></i> Actualizar
          </button>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- Main content -->
<section class="content">
  <div class="container-fluid">

    <!-- Resumen de KPIs -->
    <div class="row">
      <div class="col-md-3">
        <div class="small-box bg-white">
          <div class="inner">
            <p class="text-muted mb-1">Total rutas</p>
            <h3 id="kpi_total_rutas">0</h3>
          </div>
          <div class="icon">
            <i class="fas fa-route"></i>
          </div>
        </div>
      </div>
      <div class="col-md-3">
        <div class="small-box bg-white">
          <div class="inner">
            <p class="text-muted mb-1">Rutas activas</p>
            <h3 id="kpi_rutas_activas">0</h3>
          </div>
          <div class="icon">
            <i class="fas fa-bus-alt"></i>
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
            <p class="text-muted mb-1">Falla</p>
            <h3 class="text-danger" id="kpi_falla">0</h3>
          </div>
          <div class="icon">
            <i class="fas fa-exclamation-triangle"></i>
          </div>
        </div>
      </div>
    </div>

    <!-- Gráficas ECharts -->
    <div class="row">
      <div class="col-lg-6">
        <div class="card">
          <div class="card-header">
            <h3 class="card-title">% Cumplimiento vs estimado por ruta</h3>
          </div>
          <div class="card-body">
            <div id="chartCumplimiento" style="width:100%;height:320px;"></div>
          </div>
        </div>
      </div>
      <div class="col-lg-6">
        <div class="card">
          <div class="card-header">
            <h3 class="card-title">% Uso de capacidad de bus</h3>
          </div>
          <div class="card-body">
            <div id="chartUsoBus" style="width:100%;height:320px;"></div>
          </div>
        </div>
      </div>
    </div>

    <!-- Cards de rutas -->
    <div class="row" id="cardsContainer">
      <!-- Aquí se inyectan las tarjetas dinámicamente -->
    </div>

  </div><!-- /.container-fluid -->
</section>

<!-- Scripts específicos -->
<script>
const apiBase = location.pathname + '?action=';

function fmtNumber(n){
  return (n || 0).toLocaleString();
}

function fmtPercent(p){
  if (p === null || p === undefined) return '-';
  return p.toFixed ? p.toFixed(1) + '%' : p + '%';
}

async function fetchRoutes(){
  const estado = document.getElementById('filtroEstado').value || '';
  let url = apiBase + 'routes';
  if (estado) {
    url += '&estado=' + encodeURIComponent(estado);
  }
  const res = await fetch(url);
  if (!res.ok) throw new Error('Error al cargar rutas');
  return res.json();
}

let chartCumplimiento = null;
let chartUsoBus       = null;

function buildCharts(routes){
  // Filtrar solo rutas con pct_cumpl / pct_uso no nulo
  const rutasConCumpl = routes.filter(r => r.pct_cumpl !== null);
  const rutasConUso   = routes.filter(r => r.pct_uso !== null);

  const labelsCumpl = rutasConCumpl.map(r => r.ruta_nombre);
  const dataCumpl   = rutasConCumpl.map(r => r.pct_cumpl);

  const labelsUso = rutasConUso.map(r => r.ruta_nombre);
  const dataUso   = rutasConUso.map(r => r.pct_uso);

  // --- Gráfica de cumplimiento ---
  if (!chartCumplimiento) {
    chartCumplimiento = echarts.init(document.getElementById('chartCumplimiento'));
  }
  chartCumplimiento.setOption({
    tooltip: {
      trigger: 'axis',
      formatter: params => {
        const p = params[0];
        return `${p.name}<br/>Cumplimiento: ${p.value}%`;
      }
    },
    grid: { left: 120, right: 20, top: 20, bottom: 30 },
    xAxis: {
      type: 'value',
      min: 0,
      max: 120
    },
    yAxis: {
      type: 'category',
      data: labelsCumpl
    },
    series: [{
      type: 'bar',
      data: dataCumpl,
      label: {
        show: true,
        position: 'right',
        formatter: '{c}%'
      }
    }]
  });

  // --- Gráfica de uso de bus ---
  if (!chartUsoBus) {
    chartUsoBus = echarts.init(document.getElementById('chartUsoBus'));
  }
  chartUsoBus.setOption({
    tooltip: {
      trigger: 'axis',
      formatter: params => {
        const p = params[0];
        return `${p.name}<br/>Uso bus: ${p.value}%`;
      }
    },
    grid: { left: 120, right: 20, top: 20, bottom: 30 },
    xAxis: {
      type: 'value',
      min: 0,
      max: 120
    },
    yAxis: {
      type: 'category',
      data: labelsUso
    },
    series: [{
      type: 'bar',
      data: dataUso,
      label: {
        show: true,
        position: 'right',
        formatter: '{c}%'
      }
    }]
  });
}

function buildKpis(routes){
  const total = routes.length;
  let activas = 0;
  let sinProb = 0;
  let incon   = 0;
  let falla   = 0;

  routes.forEach(r => {
    if (r.flag_arrival === 0) activas++;
    if (r.status === 'sin_problema') sinProb++;
    else if (r.status === 'inconveniente') incon++;
    else if (r.status === 'falla') falla++;
  });

  document.getElementById('kpi_total_rutas').textContent      = fmtNumber(total);
  document.getElementById('kpi_rutas_activas').textContent    = fmtNumber(activas);
  document.getElementById('kpi_sin_problema').textContent     = fmtNumber(sinProb);
  document.getElementById('kpi_inconveniente').textContent    = fmtNumber(incon);
  document.getElementById('kpi_falla').textContent            = fmtNumber(falla);
}

function statusBadge(status){
  let cls = 'badge-secondary';
  let txt = 'Sin datos';
  if (status === 'sin_problema') {
    cls = 'badge-success';
    txt = 'Sin problema';
  } else if (status === 'inconveniente') {
    cls = 'badge-warning';
    txt = 'Inconveniente';
  } else if (status === 'falla') {
    cls = 'badge-danger';
    txt = 'Falla';
  }
  return `<span class="badge ${cls}">${txt}</span>`;
}

function buildCards(routes){
  const container = document.getElementById('cardsContainer');
  container.innerHTML = '';

  if (!routes.length) {
    container.innerHTML = '<div class="col-12"><em>No hay rutas que coincidan con el filtro.</em></div>';
    return;
  }

  routes.forEach(r => {
    const pctCumpl = r.pct_cumpl !== null ? r.pct_cumpl : 0;
    const pctUso   = r.pct_uso !== null ? r.pct_uso : 0;

    const card = document.createElement('div');
    card.className = 'col-md-6 col-lg-4';

    card.innerHTML = `
      <div class="card h-100">
        <div class="card-header">
          <h3 class="card-title" style="font-size:1rem;">
            ${r.ruta_nombre || 'Ruta ' + r.idruta}
          </h3>
          <div class="card-tools">
            ${statusBadge(r.status)}
          </div>
        </div>
        <div class="card-body">
          <p class="mb-1">
            <strong>Destino:</strong> ${r.destino || '-'}
          </p>
          <p class="mb-1">
            <strong>Bus:</strong> ${r.placa || '-'}
          </p>
          <p class="mb-2">
            <strong>Encargado:</strong> ${r.encargado || '-'}
          </p>

          <div class="progress-group">
            % Cumplimiento estimado
            <span class="float-right"><b>${fmtPercent(pctCumpl)}</b></span>
            <div class="progress progress-sm">
              <div class="progress-bar bg-info" style="width: ${Math.min(pctCumpl,120)}%"></div>
            </div>
          </div>

          <div class="progress-group mt-2">
            % Uso de capacidad de bus
            <span class="float-right"><b>${fmtPercent(pctUso)}</b></span>
            <div class="progress progress-sm">
              <div class="progress-bar bg-success" style="width: ${Math.min(pctUso,120)}%"></div>
            </div>
          </div>

          <p class="mt-2 mb-0 small text-muted">
            Personas últimas: <strong>${fmtNumber(r.total_personas || 0)}</strong> /
            Estimado total: <strong>${fmtNumber(r.estimado_total || 0)}</strong><br>
            Capacidad bus: <strong>${fmtNumber(r.bus_capacidad || 0)}</strong><br>
            Último reporte: <strong>${r.fecha_reporte || '-'}</strong>
          </p>
        </div>
        <div class="card-footer text-right">
          <a href="<?= BASE_URL ?>/dashboard/detalle_ruta.php?idruta=${r.idruta}" class="btn btn-sm btn-outline-primary">
            <i class="fas fa-eye mr-1"></i> Ver detalle
          </a>
        </div>
      </div>
    `;

    container.appendChild(card);
  });
}

async function renderRoutes(){
  try {
    const routes = await fetchRoutes();
    buildKpis(routes);
    buildCharts(routes);
    buildCards(routes);
  } catch (err) {
    console.error(err);
  }
}

// Redimensionar ECharts al cambiar tamaño ventana
window.addEventListener('resize', () => {
  if (chartCumplimiento) chartCumplimiento.resize();
  if (chartUsoBus) chartUsoBus.resize();
});

document.getElementById('btnRefresh').addEventListener('click', renderRoutes);
document.getElementById('filtroEstado').addEventListener('change', renderRoutes);

document.addEventListener('DOMContentLoaded', renderRoutes);
</script>

<?php
require __DIR__ . '/../templates/footer.php';
?>