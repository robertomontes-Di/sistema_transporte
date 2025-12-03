<?php
// dashboard/dashboard_rutas.php
require_once __DIR__ . '/../includes/db.php';

$action = $_GET['action'] ?? null;

/*
  Endpoints:
    ?action=agentes
    ?action=rutas_search
    ?action=routes
*/

// -----------------------------
// ENDPOINT: agentes
// -----------------------------
if ($action === 'agentes') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $rows = $pdo->query("SELECT idagente, nombre FROM agente ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($rows);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// -----------------------------
// ENDPOINT: rutas_search (Select2)
// -----------------------------
if ($action === 'rutas_search') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $q = trim($_GET['q'] ?? '');
        $params = [];
        $sql = "
            SELECT r.idruta, r.nombre, r.destino, er.nombre AS encargado
            FROM ruta r
            LEFT JOIN encargado_ruta er ON er.idencargado_ruta = r.idencargado_ruta
        ";
        if ($q !== '') {
            $sql .= " WHERE r.nombre LIKE :q OR r.destino LIKE :q OR er.nombre LIKE :q ";
            $params[':q'] = "%$q%";
        }
        $sql .= " ORDER BY r.nombre LIMIT 50";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $results = array_map(function ($r) {
            $label = $r['nombre'];
            if (!empty($r['destino'])) $label .= " — ".$r['destino'];
            if (!empty($r['encargado'])) $label .= " (".$r['encargado'].")";
            return ["id" => $r["idruta"], "text" => $label];
        }, $rows);

        echo json_encode(["results" => $results]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(["error" => $e->getMessage()]);
    }
    exit;
}

// -----------------------------
// ENDPOINT: routes (datos del dashboard)
// -----------------------------
if ($action === 'routes') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $params = [];
        $where  = [];

        if (!empty($_GET['idruta'])) {
            $where[] = "r.idruta = :idruta";
            $params[':idruta'] = $_GET['idruta'];
        }
        if (!empty($_GET['agente'])) {
            $where[] = "r.idagente = :agente";
            $params[':agente'] = $_GET['agente'];
        }

        $estadoFilter = $_GET['estado'] ?? null;

        $sql = "
            SELECT
                r.idruta,
                r.nombre AS ruta_nombre,
                r.destino,
                r.flag_arrival,
                b.placa,
                b.capacidad_asientos AS bus_capacidad,
                er.nombre AS encargado_nombre,

                lr.total_personas AS total_personas_ultimo,
                lr.fecha_reporte,
                lr.idaccion,
                a.tipo_accion,

                est.estimado_total,
                stats.total_personas_acum
            FROM ruta r
            LEFT JOIN bus b ON b.idbus = r.idbus
            LEFT JOIN encargado_ruta er ON er.idencargado_ruta = r.idencargado_ruta

            LEFT JOIN (
                SELECT rp1.*
                FROM reporte rp1
                INNER JOIN (
                    SELECT idruta, MAX(fecha_reporte) mx
                    FROM reporte GROUP BY idruta
                ) z ON rp1.idruta=z.idruta AND rp1.fecha_reporte=z.mx
            ) lr ON lr.idruta=r.idruta

            LEFT JOIN acciones a ON a.idaccion = lr.idaccion

            LEFT JOIN (
                SELECT idruta, SUM(estimado_personas) estimado_total
                FROM paradas GROUP BY idruta
            ) est ON est.idruta=r.idruta

            LEFT JOIN (
                SELECT idruta,
                    SUM(CASE WHEN critico=0 THEN total_personas ELSE 0 END) total_personas_acum
                FROM reporte GROUP BY idruta
            ) stats ON stats.idruta=r.idruta
        ";

        if ($where) $sql .= " WHERE ".implode(" AND ", $where);
        $sql .= " ORDER BY r.idruta DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $out = [];
        foreach ($rows as $r) {
            if (empty($r['fecha_reporte'])) $status = 'sin_reporte';
            else {
                $tipo = strtolower($r['tipo_accion'] ?? '');
                if ($tipo === 'critico') $status = 'critico';
                elseif ($tipo === 'inconveniente') $status = 'inconveniente';
                else $status = 'sin_problema';
            }

            if ($estadoFilter && $estadoFilter !== $status) continue;

            $personas_ultimo = (int)($r['total_personas_ultimo'] ?? 0);
            $personas_acum   = (int)($r['total_personas_acum'] ?? $personas_ultimo);
            $estimado        = (int)($r['estimado_total'] ?? 0);
            $capacidad       = (int)($r['bus_capacidad'] ?? 0);

            $pct_cumpl = $estimado > 0 ? round($personas_acum / $estimado * 100, 1) : null;
            $pct_uso   = $capacidad > 0 ? round($personas_acum / $capacidad * 100, 1) : null;

            $out[] = [
                "idruta" => (int)$r["idruta"],
                "ruta_nombre" => $r["ruta_nombre"],
                "destino" => $r["destino"],
                "flag_arrival" => (int)$r["flag_arrival"],
                "placa" => $r["placa"],
                "encargado" => $r["encargado_nombre"],
                "total_personas" => $personas_ultimo,
                "estimado_total" => $estimado,
                "bus_capacidad" => $capacidad,
                "pct_cumpl" => $pct_cumpl,
                "pct_uso" => $pct_uso,
                "fecha_reporte" => $r["fecha_reporte"],
                "status" => $status
            ];
        }

        echo json_encode($out);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(["error" => $e->getMessage()]);
    }
    exit;
}

// -----------------------------
// HTML (página)
// -----------------------------
$pageTitle = "Dashboard de Rutas";
$currentPage = "dashboard_rutas";
require __DIR__ . '/../templates/header.php';
?>

<!-- Content Header -->
<section class="content-header">
  <div class="container-fluid">
    <div class="row mb-2 align-items-center">
      <div class="col-sm-6">
        <h1>Centro de Monitoreo — Dashboard de Rutas</h1>
        <p class="text-muted mb-0">Resumen en tiempo real — filtros dinámicos</p>
      </div>

      <div class="col-sm-6">
        <div class="form-inline justify-content-end">
          <label class="mr-2">Estado:</label>
          <select id="filtroEstado" class="form-control form-control-sm mr-2">
            <option value="">Todos</option>
            <option value="sin_reporte">Sin Reporte</option>
            <option value="sin_problema">Sin Problema</option>
            <option value="inconveniente">Inconveniente</option>
            <option value="critico">Crítico</option>
          </select>

          <label class="mr-2">Agente:</label>
          <select id="filtroAgente" class="form-control form-control-sm mr-2">
            <option value="">Todos</option>
          </select>

          <label class="mr-2">Ruta:</label>
          <select id="filtroRuta" class="form-control form-control-sm" style="width:260px;">
            <option value="">Todas las rutas</option>
          </select>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- Main content -->
<section class="content">
  <div class="container-fluid">

    <!-- KPIs -->
    <div class="row mb-3" id="kpiRow">
      <div class="col-md-2">
        <div class="small-box bg-white">
          <div class="inner">
            <p class="text-muted mb-1">Total rutas</p>
            <h3 id="kpi_total_rutas">0</h3>
          </div>
          <div class="icon"><i class="fas fa-route"></i></div>
        </div>
      </div>

      <div class="col-md-2">
        <div class="small-box bg-white">
          <div class="inner">
            <p class="text-muted mb-1">Rutas activas</p>
            <h3 id="kpi_rutas_activas">0</h3>
          </div>
          <div class="icon"><i class="fas fa-bus-alt"></i></div>
        </div>
      </div>

      <div class="col-md-2">
        <div class="small-box bg-white">
          <div class="inner">
            <p class="text-muted mb-1">Sin Reporte</p>
            <h3 class="text-info" id="kpi_sin_reporte">0</h3>
          </div>
          <div class="icon"><i class="fas fa-clock"></i></div>
        </div>
      </div>

      <div class="col-md-2">
        <div class="small-box bg-white">
          <div class="inner">
            <p class="text-muted mb-1">Sin problema</p>
            <h3 class="text-success" id="kpi_sin_problema">0</h3>
          </div>
          <div class="icon"><i class="fas fa-check-circle"></i></div>
        </div>
      </div>

      <div class="col-md-2">
        <div class="small-box bg-white">
          <div class="inner">
            <p class="text-muted mb-1">Inconveniente</p>
            <h3 class="text-warning" id="kpi_inconveniente">0</h3>
          </div>
          <div class="icon"><i class="fas fa-exclamation-circle"></i></div>
        </div>
      </div>

      <div class="col-md-2">
        <div class="small-box bg-white">
          <div class="inner">
            <p class="text-muted mb-1">Crítico</p>
            <h3 class="text-danger" id="kpi_critico">0</h3>
          </div>
          <div class="icon"><i class="fas fa-exclamation-triangle"></i></div>
        </div>
      </div>
    </div>

    <!-- Charts -->
    <div class="row">
      <div class="col-lg-6">
        <div class="card mb-3">
          <div class="card-header"><h3 class="card-title">Personas: Último vs Estimado</h3></div>
          <div class="card-body"><div id="chartPersons" style="height:280px"></div></div>
        </div>
      </div>

      <div class="col-lg-6">
        <div class="card mb-3">
          <div class="card-header"><h3 class="card-title">Estado de rutas</h3></div>
          <div class="card-body"><div id="chartStatus" style="height:280px"></div></div>
        </div>
      </div>
    </div>

    <!-- Cards (paginated) -->
    <div class="row" id="cardsContainer"></div>

    <div class="row mt-3">
      <div class="col-12" id="paginationArea"></div>
    </div>

  </div>
</section>

<!-- Libs -->
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/echarts@5/dist/echarts.min.js"></script>

<script>
/* -------------------------
   Utilidades y estado
   ------------------------- */
const apiBase = location.pathname + '?action=';
function fmtNumber(n){ return (n||0).toLocaleString(); }
function fmtPercent(p){ return p===null ? '-' : (p.toFixed ? p.toFixed(1)+'%' : p+'%'); }
function statusBadge(status){
  if(status==='sin_reporte') return '<span class="badge badge-info">Sin reporte</span>';
  if(status==='sin_problema') return '<span class="badge badge-success">Sin problema</span>';
  if(status==='inconveniente') return '<span class="badge badge-warning">Inconveniente</span>';
  if(status==='critico') return '<span class="badge badge-danger">Crítico</span>';
  return '<span class="badge badge-secondary">—</span>';
}

/* -------------------------
   Select2 rutas remoto
   ------------------------- */
function initSelect2Rutas(){
  $('#filtroRuta').select2({
    placeholder: "Buscar ruta…",
    allowClear: true,
    width: '260px',
    ajax: {
      url: apiBase + 'rutas_search',
      dataType: 'json',
      delay: 250,
      data: params => ({ q: params.term || '' }),
      processResults: data => data,
      cache: true
    },
    language: {
      noResults: () => "No se encontraron rutas",
      searching: () => "Buscando…"
    }
  });
}

/* -------------------------
   Cargar agentes
   ------------------------- */
async function cargarAgentes(){
  try {
    const res = await fetch(apiBase + 'agentes');
    const rows = await res.json();
    const sel = document.getElementById('filtroAgente');
    sel.innerHTML = '<option value="">Todos</option>';
    rows.forEach(r => {
      const o = document.createElement('option');
      o.value = r.idagente;
      o.textContent = r.nombre;
      sel.appendChild(o);
    });
  } catch (e) {
    console.error('Error cargando agentes', e);
  }
}

/* -------------------------
   Fetch routes (dashboard data)
   ------------------------- */
async function fetchRoutes(){
  const estado = document.getElementById('filtroEstado').value || '';
  const agente = document.getElementById('filtroAgente').value || '';
  const idruta = document.getElementById('filtroRuta').value || '';

  let url = apiBase + 'routes';
  if (estado) url += '&estado=' + encodeURIComponent(estado);
  if (agente) url += '&agente=' + encodeURIComponent(agente);
  if (idruta) url += '&idruta=' + encodeURIComponent(idruta);

  const res = await fetch(url);
  if (!res.ok) throw new Error('Error fetching routes');
  return res.json();
}

/* -------------------------
   Charts (ECharts)
   ------------------------- */
let chartPersons = null;
let chartStatus  = null;
function renderCharts(routes){
  const totalPersonas = routes.reduce((s,r)=> s + (r.total_personas||0), 0);
  const totalEstimado = routes.reduce((s,r)=> s + (r.estimado_total||0), 0);

  // Persons chart
  if(!chartPersons) chartPersons = echarts.init(document.getElementById('chartPersons'));
  const optPersons = {
    tooltip: { trigger: 'axis' },
    xAxis: { type: 'category', data: ['En reporte (sum)','Estimado (sum)'] },
    yAxis: { type: 'value' },
    series: [{
      type: 'bar',
      data: [ totalPersonas, totalEstimado ],
      label: { show: true, position: 'top' }
    }]
  };
  chartPersons.setOption(optPersons);

  // Status pie
  if(!chartStatus) chartStatus = echarts.init(document.getElementById('chartStatus'));
  const counts = { sin_problema:0, inconveniente:0, critico:0, sin_reporte:0 };
  routes.forEach(r => counts[r.status] = (counts[r.status]||0) + 1);
  const optStatus = {
    tooltip: { trigger: 'item' },
    legend: { bottom: 0 },
    series: [{
      type: 'pie',
      radius: ['40%','70%'],
      label: { formatter: '{b}: {c}' },
      data: [
        { value: counts.sin_problema, name: 'Sin problema' },
        { value: counts.inconveniente, name: 'Inconveniente' },
        { value: counts.critico, name: 'Crítico' },
        { value: counts.sin_reporte, name: 'Sin reporte' }
      ],color: ['#25d506ff', '#ffc107', '#fc031bff', '#1e15d4ff']
    }]
  };
  chartStatus.setOption(optStatus);
}

/* -------------------------
   Cards + Paginación
   ------------------------- */
let lastRoutes = [];
let page = 1;
const pageSize = 12;

function paginate(arr, pageNum, pageSize){
  const start = (pageNum-1)*pageSize;
  return arr.slice(start, start+pageSize);
}

function buildPagination(totalItems){
  const totalPages = Math.max(1, Math.ceil(totalItems / pageSize));
  const container = document.getElementById('paginationArea');
  if (totalPages <= 1) { container.innerHTML = ''; return; }

  let html = `<nav><ul class="pagination justify-content-center">`;
  html += `<li class="page-item ${page === 1 ? 'disabled' : ''}"><a class="page-link" href="#" onclick="goPage(${page-1});return false;">Anterior</a></li>`;

  const maxShown = 7;
  let start = Math.max(1, page - 3);
  let end = Math.min(totalPages, start + maxShown - 1);
  if (end - start < maxShown) start = Math.max(1, end - maxShown + 1);

  for (let i = start; i <= end; i++) {
    html += `<li class="page-item ${i === page ? 'active' : ''}"><a class="page-link" href="#" onclick="goPage(${i});return false;">${i}</a></li>`;
  }

  html += `<li class="page-item ${page === totalPages ? 'disabled' : ''}"><a class="page-link" href="#" onclick="goPage(${page+1});return false;">Siguiente</a></li>`;
  html += `</ul></nav>`;
  container.innerHTML = html;
}

window.goPage = function(p){
  const totalPages = Math.max(1, Math.ceil(lastRoutes.length / pageSize));
  if (p < 1 || p > totalPages) return;
  page = p;
  renderCards();
};

function renderCards(){
  const container = document.getElementById('cardsContainer');
  container.innerHTML = '';
  if (!lastRoutes.length) {
    container.innerHTML = '<div class="col-12 text-center text-muted">No hay rutas que coincidan con el filtro.</div>';
    buildPagination(0);
    return;
  }

  const pageItems = paginate(lastRoutes, page, pageSize);
  pageItems.forEach(r => {
    container.insertAdjacentHTML('beforeend', `
      <div class="col-md-6 col-lg-4">
        <div class="card h-100">
          <div class="card-header d-flex justify-content-between align-items-center">
            <div style="font-weight:600">${r.ruta_nombre}</div>
            <div>${statusBadge(r.status)}</div>
          </div>
          <div class="card-body">
            <p class="mb-1"><strong>Destino:</strong> ${r.destino || '-'}</p>
            <p class="mb-1"><strong>Bus:</strong> ${r.placa || '-'}</p>
            <p class="mb-1"><strong>Encargado:</strong> ${r.encargado || '-'}</p>

            <div class="progress-group mt-3">
              <div class="d-flex justify-content-between">
                <small>% Cumplimiento</small><small><b>${fmtPercent(r.pct_cumpl)}</b></small>
              </div>
              <div class="progress progress-sm">
                <div class="progress-bar" role="progressbar" style="width:${Math.min(r.pct_cumpl||0,120)}%"></div>
              </div>
            </div>

            <div class="progress-group mt-2">
              <div class="d-flex justify-content-between">
                <small>% Uso bus</small><small><b>${fmtPercent(r.pct_uso)}</b></small>
              </div>
              <div class="progress progress-sm">
                <div class="progress-bar bg-success" role="progressbar" style="width:${Math.min(r.pct_uso||0,120)}%"></div>
              </div>
            </div>

            <p class="small text-muted mt-2 mb-0">
              Personas últimas: <b>${fmtNumber(r.total_personas)}</b><br>
              Estimado total: <b>${fmtNumber(r.estimado_total)}</b><br>
              Capacidad bus: <b>${fmtNumber(r.bus_capacidad)}</b><br>
              Último reporte: ${r.fecha_reporte || '-'}
            </p>
          </div>
          <div class="card-footer text-right">
            <a class="btn btn-sm btn-outline-primary" href="<?= BASE_URL ?>/dashboard/detalle_ruta.php?idruta=${r.idruta}">Ver detalle</a>
          </div>
        </div>
      </div>
    `);
  });

  buildPagination(lastRoutes.length);
}

/* -------------------------
   KPIs
   ------------------------- */
function renderKpis(routes){
  document.getElementById('kpi_total_rutas').textContent = fmtNumber(routes.length);
  let activas=0, sin_rep=0, sin_prob=0, inc=0, crit=0;
  routes.forEach(r => {
    if (r.flag_arrival === 0) activas++;
    if (r.status === 'sin_reporte') sin_rep++;
    else if (r.status === 'sin_problema') sin_prob++;
    else if (r.status === 'inconveniente') inc++;
    else if (r.status === 'critico') crit++;
  });
  document.getElementById('kpi_rutas_activas').textContent = fmtNumber(activas);
  document.getElementById('kpi_sin_reporte').textContent = fmtNumber(sin_rep);
  document.getElementById('kpi_sin_problema').textContent = fmtNumber(sin_prob);
  document.getElementById('kpi_inconveniente').textContent = fmtNumber(inc);
  document.getElementById('kpi_critico').textContent = fmtNumber(crit);
}

/* -------------------------
   Render general
   ------------------------- */
async function renderAll(){
  page = 1;
  try {
    const routes = await fetchRoutes();
    lastRoutes = Array.isArray(routes) ? routes : [];
    renderKpis(lastRoutes);
    renderCharts(lastRoutes);
    renderCards();
  } catch (e) {
    console.error('Error renderAll', e);
  }
}

/* -------------------------
   Init
   ------------------------- */
async function initDashboard(){
  initSelect2Rutas();
  await cargarAgentes();
  await renderAll();
  // listeners
  document.getElementById('filtroEstado').addEventListener('change', renderAll);
  document.getElementById('filtroAgente').addEventListener('change', renderAll);
  $('#filtroRuta').on('change', renderAll);

  // resize charts on window resize
  window.addEventListener('resize', () => {
    if (chartPersons) chartPersons.resize();
    if (chartStatus) chartStatus.resize();
  });
}

document.addEventListener('DOMContentLoaded', initDashboard);
</script>

<?php
require __DIR__ . '/../templates/footer.php';
?>
