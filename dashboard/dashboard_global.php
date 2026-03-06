<?php
// dashboard_global.php
require_once __DIR__ . '/../includes/db.php';

header('X-Content-Type-Options: nosniff');

// Detectar si es una llamada API (JSON) o carga normal de página
$action = $_GET['action'] ?? null;

/**
 * Obtener idevento actual desde vw_eventos
 */
function getEventoActualId(PDO $pdo): int {
  try {
    $sql = "
      SELECT idevento
      FROM eventos
      WHERE activo = 1
      ORDER BY fecha_inicio DESC, idevento DESC
      LIMIT 1
    ";
    $id = (int)$pdo->query($sql)->fetchColumn();
    return $id > 0 ? $id : 1;
  } catch (Throwable $e) {
    return 1;
  }
}

/**
 * idevento seleccionado (GET) o actual
 */
function getSelectedEventoId(PDO $pdo): int {
  $idevento = isset($_GET['idevento']) ? (int)$_GET['idevento'] : 0;
  if ($idevento > 0) return $idevento;
  return getEventoActualId($pdo);
}

$idevento = getSelectedEventoId($pdo);

// -----------------------------------------------------------------
// ENDPOINTS AJAX
// -----------------------------------------------------------------

/**
 * LISTA EVENTOS PARA EL SELECT
 */
if ($action === 'events') {
  header('Content-Type: application/json; charset=utf-8');
  try {
    $rows = $pdo->query("
      SELECT idevento, nombre, fecha_inicio, fecha_fin, activo
      FROM eventos
      ORDER BY activo DESC, fecha_inicio DESC, idevento DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $idevento_actual = 1;
    foreach ($rows as $ev) {
      if ((int)$ev['activo'] === 1) {
        $idevento_actual = (int)$ev['idevento'];
        break;
      }
    }
    if ($idevento_actual <= 0 && count($rows) > 0) $idevento_actual = (int)$rows[0]['idevento'];

    echo json_encode([
      'success' => true,
      'idevento_actual' => $idevento_actual,
      'eventos' => $rows
    ]);
    exit;
  } catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    exit;
  }
}
/**
 * FILTROS (departamentos + rutas) POR EVENTO
 */
if ($action === 'filters') {
  header('Content-Type: application/json; charset=utf-8');

  try {
    $idevento = getSelectedEventoId($pdo);

    // Departamentos (solo paradas de rutas del evento)
    $sqlDept = "
      SELECT DISTINCT p.departamento
      FROM paradas p
      INNER JOIN ruta r ON r.idruta = p.idruta
      WHERE r.idevento = :idevento
        AND p.departamento IS NOT NULL AND p.departamento <> ''
      ORDER BY p.departamento
    ";
    $stmt = $pdo->prepare($sqlDept);
    $stmt->execute([':idevento' => $idevento]);
    $departamentos = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Rutas (solo del evento)
    $sqlRutas = "
      SELECT idruta, nombre
      FROM ruta
      WHERE idevento = :idevento
      ORDER BY idruta
    ";
    $stmt = $pdo->prepare($sqlRutas);
    $stmt->execute([':idevento' => $idevento]);
    $rutas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
      'success' => true,
      'idevento' => $idevento,
      'departamentos' => $departamentos,
      'rutas' => $rutas
    ]);
    exit();
  } catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    exit();
  }
}

/**
 * STATS (KPIs) - MISMA LÓGICA, SOLO FILTRO POR EVENTO
 */
if ($action === 'stats') {
  header('Content-Type: application/json; charset=utf-8');

  try {
    $idevento = isset($_GET['idevento']) ? (int)$_GET['idevento'] : 0;
    if ($idevento <= 0) $idevento = getEventoActualId($pdo);

    // 1) PERSONAS EN RUTA
    $sqlPersonasRuta = "
      SELECT COALESCE(SUM(r.total_personas),0) AS personas_en_ruta
      FROM reporte r
      INNER JOIN ruta ru ON ru.idruta = r.idruta
      WHERE ru.idevento = :idevento
        AND ru.activa = 1
        AND (ru.flag_arrival IS NULL OR ru.flag_arrival = 0)
    ";
    $stmt = $pdo->prepare($sqlPersonasRuta);
    $stmt->execute([':idevento' => $idevento]);
    $personas_en_ruta = (int)$stmt->fetchColumn();

    // 1b) PERSONAS EN ESTADIO
    $sqlPersonasEstadio = "
      SELECT COALESCE(SUM(r.total_personas),0) AS personas_en_estadio
      FROM reporte r
      INNER JOIN ruta ru ON ru.idruta = r.idruta
      WHERE ru.idevento = :idevento
        AND ru.flag_arrival = 1
    ";
    $stmt = $pdo->prepare($sqlPersonasEstadio);
    $stmt->execute([':idevento' => $idevento]);
    $personas_en_estadio = (int)$stmt->fetchColumn();

    // 2) TOTAL ESTIMADO
    $sqlEstimated = "
      SELECT COALESCE(SUM(p.estimado_personas),0) AS total_estimated
      FROM paradas p
      INNER JOIN ruta r ON r.idruta = p.idruta
      WHERE r.idevento = :idevento
        AND r.activa = 1
        AND (r.flag_arrival IS NULL OR r.flag_arrival = 0)
    ";
    $stmt = $pdo->prepare($sqlEstimated);
    $stmt->execute([':idevento' => $idevento]);
    $total_estimated = (int)$stmt->fetchColumn();

    // 3) routes_active
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM ruta WHERE idevento = :idevento AND activa = 1");
    $stmt->execute([':idevento' => $idevento]);
    $routes_active = (int)$stmt->fetchColumn();

    // 4) routes_total
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM ruta WHERE idevento = :idevento");
    $stmt->execute([':idevento' => $idevento]);
    $routes_total = (int)$stmt->fetchColumn();

    // 5) STATUS (último reporte por ruta)
    $sqlStatus = "
      SELECT
        SUM(CASE WHEN lr.idaccion IS NULL THEN 1 ELSE 0 END) AS sin_problema,
        SUM(CASE WHEN lr.idaccion IS NOT NULL AND LOWER(a.tipo_accion) = 'critico' THEN 1 ELSE 0 END) AS falla,
        SUM(CASE WHEN lr.idaccion IS NOT NULL AND LOWER(a.tipo_accion) = 'inconveniente' THEN 1 ELSE 0 END) AS inconveniente
      FROM ruta r
      LEFT JOIN (
        SELECT r1.*
        FROM reporte r1
        INNER JOIN (
          SELECT idruta, MAX(fecha_reporte) AS max_fecha
          FROM reporte
          GROUP BY idruta
        ) mx ON r1.idruta = mx.idruta AND r1.fecha_reporte = mx.max_fecha
      ) lr ON lr.idruta = r.idruta
      LEFT JOIN acciones a ON a.idaccion = lr.idaccion
      WHERE r.idevento = :idevento
    ";
    $stmt = $pdo->prepare($sqlStatus);
    $stmt->execute([':idevento' => $idevento]);
    $status = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['sin_problema'=>0,'falla'=>0,'inconveniente'=>0];

    // 7) retorno / salida / en estadio (acciones)
    $stmt = $pdo->prepare("
      SELECT COUNT(DISTINCT rep.idruta)
      FROM reporte rep
      INNER JOIN ruta ru ON ru.idruta = rep.idruta
      WHERE ru.idevento = :idevento AND rep.idaccion = 1
    ");
    $stmt->execute([':idevento' => $idevento]);
    $routes_retorno_punto = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("
      SELECT COUNT(DISTINCT rep.idruta)
      FROM reporte rep
      INNER JOIN ruta ru ON ru.idruta = rep.idruta
      WHERE ru.idevento = :idevento AND rep.idaccion = 17
    ");
    $stmt->execute([':idevento' => $idevento]);
    $routes_salida_evento = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("
      SELECT COUNT(DISTINCT rep.idruta)
      FROM reporte rep
      INNER JOIN ruta ru ON ru.idruta = rep.idruta
      WHERE ru.idevento = :idevento AND rep.idaccion = 16
    ");
    $stmt->execute([':idevento' => $idevento]);
    $routes_en_estadio = (int)$stmt->fetchColumn();

    echo json_encode([
      'success'             => true,
      'idevento'            => $idevento,
      'personas_en_ruta'    => $personas_en_ruta,
      'personas_en_estadio' => $personas_en_estadio,
      'total_estimated'     => $total_estimated,
      'routes_active'       => $routes_active,
      'routes_total'        => $routes_total,
      'routes_en_estadio'   => $routes_en_estadio,
      'routes_retorno_punto'=> $routes_retorno_punto,
      'routes_salida_evento'=> $routes_salida_evento,
      'sin_problema'        => (int)$status['sin_problema'],
      'inconveniente'       => (int)$status['inconveniente'],
      'falla'               => (int)$status['falla'],
    ]);
    exit;

  } catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success'=>false,'error' => 'Error en stats: ' . $e->getMessage()]);
    exit;
  }
}

// -----------------------------------------------------------------
// MAP (POR EVENTO)
// -----------------------------------------------------------------
if ($action === 'map') {
  header('Content-Type: application/json; charset=utf-8');

  try {
    $idevento = getSelectedEventoId($pdo);

    // ======== RECIBIR FILTROS ========
    $departamento = $_GET['departamento'] ?? null;
    $tipo_accion  = $_GET['tipo_accion'] ?? null;
    $idruta       = $_GET['idruta'] ?? null;

    // OJO: aquí agregamos el filtro base por evento
    $where = " WHERE r.idevento = :idevento AND p.latitud IS NOT NULL AND p.longitud IS NOT NULL ";
    if ($departamento) $where .= " AND r.idruta in( select distinct pp.idruta from paradas pp where pp.departamento = :departamento )";
    if ($tipo_accion)  $where .= " AND a.tipo_accion = :tipo_accion ";
    if ($idruta)       $where .= " AND r.idruta = :idruta ";

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

    // binds
    $stmt->bindValue(':idevento', $idevento, PDO::PARAM_INT);
    if ($departamento) $stmt->bindValue(':departamento', $departamento);
    if ($tipo_accion)  $stmt->bindValue(':tipo_accion', $tipo_accion);
    if ($idruta)       $stmt->bindValue(':idruta', $idruta);

    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Obtener suma de personas reportadas por ruta (POR EVENTO)
    $sqlPersonasPorRuta = "
      SELECT rep.idruta, SUM(rep.total_personas) AS total_personas_reportadas
      FROM reporte rep
      INNER JOIN ruta ru ON ru.idruta = rep.idruta
      WHERE ru.idevento = :idevento
      GROUP BY rep.idruta
    ";
    $stmt2 = $pdo->prepare($sqlPersonasPorRuta);
    $stmt2->execute([':idevento' => $idevento]);

    $personasPorRuta = [];
    foreach ($stmt2->fetchAll(PDO::FETCH_ASSOC) as $row) {
      $personasPorRuta[(int)$row['idruta']] = (int)$row['total_personas_reportadas'];
    }

    // Agrupamos por ruta
    $routes = [];
    foreach ($rows as $row) {
      $idr = (int)$row['idruta'];

      if (!isset($routes[$idr])) {
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

        // Obtener última ubicación del bus
        $sqlUbic = "
          SELECT lat, lng, fecha_registro
          FROM ubicaciones
          WHERE idruta = :idruta
          ORDER BY fecha_registro DESC
          LIMIT 1
        ";
        $stmtUbic = $pdo->prepare($sqlUbic);
        $stmtUbic->execute([':idruta' => $idr]);
        $ubicacion = $stmtUbic->fetch(PDO::FETCH_ASSOC);

        $routes[$idr] = [
          'idruta'          => $idr,
          'ruta_nombre'     => $row['ruta_nombre'],
          'placa'           => $row['placa'],
          'conductor'       => $row['conductor'],
          'total_personas'  => (int)$row['total_personas'],
          'total_becarios'  => (int)$row['total_becarios'],
          'total_menores12' => (int)$row['total_menores12'],
          'fecha_reporte'   => $row['fecha_reporte'],
          'status'          => $status,
          'accion_nombre'   => $row['accion_nombre'],
          'path'            => [],
          'bus_lat'         => $ubicacion ? (float)$ubicacion['lat'] : null,
          'bus_lng'         => $ubicacion ? (float)$ubicacion['lng'] : null,
          'bus_fecha'       => $ubicacion ? $ubicacion['fecha_registro'] : null,
          'total_personas_reportadas' => $personasPorRuta[$idr] ?? 0,
        ];
      }

      // Agregar punto de la parada
      if ($row['latitud'] !== null && $row['longitud'] !== null) {
        $routes[$idr]['path'][] = [
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
// LISTADO DE REPORTES RECIENTES (POR EVENTO)
// -----------------------------------------------------------------
if ($action === 'recent_reports') {
  header('Content-Type: application/json; charset=utf-8');

  try {
    // Mejor: permitir que el cliente mande idevento, y si no, usar el "seleccionado"
    $idevento = isset($_GET['idevento']) ? (int)$_GET['idevento'] : (int)getSelectedEventoId($pdo);
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
    if ($limit < 1) $limit = 20;
    if ($limit > 200) $limit = 200;

    if ($idevento <= 0) {
      echo json_encode(['ok' => true, 'data' => [], 'msg' => 'No hay idevento seleccionado']);
      exit;
    }

    $sql = "
      SELECT r.idreporte, rt.nombre AS ruta_nombre, r.comentario, r.total_personas,
             r.fecha_reporte, a.nombre AS accion_nombre, a.tipo_accion
      FROM reporte r
      INNER JOIN ruta rt ON rt.idruta = r.idruta
      LEFT JOIN acciones a ON a.idaccion = r.idaccion
      WHERE rt.idevento = :idevento
      ORDER BY r.fecha_reporte DESC
      LIMIT $limit
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':idevento', $idevento, PDO::PARAM_INT);
    $stmt->execute();

    echo json_encode(['ok' => true, 'idevento' => $idevento, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
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
    <div class="row mb-2 align-items-end">
      <div class="col-sm-7">
        <h1>Centro de Monitoreo - Dashboard Global</h1>
        <p class="text-muted mb-0">Resumen en tiempo real de rutas, personas y eventos</p>
      </div>

      <!-- SELECT EVENTO (solo UI; no toca KPIs) -->
      <div class="col-sm-3">
        <label class="mb-1"><i class="fas fa-calendar-alt mr-1"></i>Evento</label>
        <select id="event-select" class="form-control form-control-sm"></select>
        <small class="text-muted">Selecciona otro evento para recargar la data.</small>
      </div>

      <div class="col-sm-2 text-right">
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

    <!-- KPIs (SIN CAMBIOS) -->
    <div class="row kpi-row">

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

      <div class="col-xl-2 col-md-4 col-sm-6 mb-3">
        <div class="kpi-tile kpi-teal">
          <div class="kpi-body">
            <div class="kpi-label">Total de personas en Fusalmo</div>
            <div class="kpi-value" id="kpi_total_estadio">0</div>
          </div>
          <div class="kpi-icon">
            <i class="fas fa-futbol"></i>
          </div>
        </div>
      </div>

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

      <div class="col-xl-2 col-md-4 col-sm-6 mb-3">
        <div class="kpi-tile kpi-blue">
          <div class="kpi-body">
            <div class="kpi-label">Rutas en Fusalmo</div>
            <div class="kpi-value" id="kpi_routes_en_estadio">0 / 0</div>
          </div>
          <div class="kpi-icon">
            <i class="fas fa-route"></i>
          </div>
        </div>
      </div>

      <div class="col-xl-2 col-md-4 col-sm-6 mb-3">
        <div class="kpi-tile kpi-blue">
          <div class="kpi-body">
            <div class="kpi-label">Rutas activas hacia Fusalmo</div>
            <div class="kpi-value" id="kpi_activas_hacia_estadio">0 / 0</div>
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
    <div class="row mt-3">
      <div class="col-lg-5">

        <div class="card mb-3">
          <div class="card-header">
            <h3 class="card-title">Total personas: En Fusalmo vs Estimadas</h3>
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
                    <th>Comentario</th>
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

<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://maps.googleapis.com/maps/api/js?key=AIzaSyC2Zm7v7BAdKaA-KAvna0q4y0lQgwvE1V4&libraries=geometry"></script>

<script>
  let idevento = <?= (int)$idevento ?>;

  function fmtNumber(n) {
    return (n || 0).toLocaleString();
  }

  function buildUrl(action, params = {}) {
    const url = new URL(location.pathname, window.location.origin);
    url.searchParams.set('action', action);

    // siempre mandar idevento para TODO menos events
    if (action !== 'events') {
      url.searchParams.set('idevento', String(idevento));
    }

    Object.keys(params).forEach(k => {
      if (params[k] !== null && params[k] !== undefined && params[k] !== '') {
        url.searchParams.set(k, params[k]);
      }
    });

    return url.toString();
  }

  async function fetchEvents() {
    const res = await fetch(buildUrl('events'));
    if (!res.ok) {
      const txt = await res.text();
      console.error('events error:', txt);
      throw new Error('Error fetching events');
    }
    const json = await res.json();
    console.log('fetchEvents() json =>', json);
    return json;
  }

  async function fetchStats() {
    const res = await fetch(buildUrl('stats'));
    if (!res.ok) throw new Error('Error fetching stats');
    return res.json();
  }

  async function fetchMapData() {
    const params = {
      departamento: document.getElementById("f_departamento").value,
      tipo_accion: document.getElementById("f_tipo_accion").value,
      idruta: document.getElementById("f_idruta").value
    };
    const res = await fetch(buildUrl('map', params));
    return res.json();
  }

  async function fetchRecentReports(limit = 50) {
    const res = await fetch(buildUrl('recent_reports', { limit }));
    if (!res.ok) throw new Error('Error fetching reports');
    return res.json();
  }

  async function fetchFilters() {
    const res = await fetch(buildUrl('filters'));
    if (!res.ok) throw new Error('Error fetching filters');
    return res.json();
  }

  // ====== Select de Eventos ======
async function initEventSelect() {
  const res = await fetchEvents(); // puede venir {eventos: [...] } o [...]
  const eventos = Array.isArray(res) ? res : (res.eventos || []);
  const sel = document.getElementById('event-select');
  sel.innerHTML = '';

  if (!eventos.length) {
    const opt = document.createElement('option');
    opt.value = '0';
    opt.textContent = 'No hay eventos';
    sel.appendChild(opt);
    return;
  }

  const saved = parseInt(localStorage.getItem('idevento_dashboard') || '0', 10);

  // idevento global: si no existe, lo creamos
  window.idevento = window.idevento || 0;

  // idevento_actual si viene del backend, si no, usar el primero
  const backendActual = (res && res.idevento_actual) ? parseInt(res.idevento_actual, 10) : 0;
  const prefer = saved > 0 ? saved : (backendActual || window.idevento || eventos[0].idevento);

  eventos.forEach(ev => {
    const opt = document.createElement('option');
    opt.value = ev.idevento;

  const esActual = (ev.activo != null && parseInt(ev.activo, 10) === 1);

    opt.textContent = `${ev.nombre}${esActual ? ' (Actual)' : ''}`;
    sel.appendChild(opt);
  });

  window.idevento = parseInt(prefer, 10) || eventos[0].idevento;
  sel.value = String(window.idevento);

  sel.onchange = async () => {
    window.idevento = parseInt(sel.value, 10) || window.idevento;
    localStorage.setItem('idevento_dashboard', String(window.idevento));
    await loadFilters();
    await refreshAll();
  };
}

  // ====== Filtros (departamentos/rutas) ======
  async function loadFilters() {
    try {
      const data = await fetchFilters();

      const depSel = document.getElementById('f_departamento');
      const rutaSel = document.getElementById('f_idruta');

      depSel.innerHTML = '<option value="">Todos</option>';
      (data.departamentos || []).forEach(d => {
        const opt = document.createElement('option');
        opt.value = d;
        opt.textContent = d;
        depSel.appendChild(opt);
      });

      rutaSel.innerHTML = '<option value="">Todas</option>';
      (data.rutas || []).forEach(r => {
        const opt = document.createElement('option');
        opt.value = r.idruta;
        opt.textContent = `${r.idruta} - ${r.nombre}`;
        rutaSel.appendChild(opt);
      });
    } catch (e) {
      console.error("Error loadFilters:", e);
    }
  }

  let chartPersons = null;
  let chartStatus = null;

  async function renderKpisAndChart() {
    try {
      const stats = await fetchStats();

      // ---------- KPIs (SIN CAMBIOS) ----------
      $('#kpi_total_reported').text(fmtNumber(stats.personas_en_ruta || 0));
      $('#kpi_total_estimated').text(fmtNumber(stats.total_estimated || 0));
      $('#kpi_total_estadio').text(fmtNumber(stats.personas_en_estadio || 0));

      document.getElementById('kpi_routes_active').innerText =
        fmtNumber(stats.routes_active || 0) + ' / ' + fmtNumber(stats.routes_total || 0);

      document.getElementById('kpi_routes_return_home').innerText =
        fmtNumber(stats.routes_retorno_punto || 0) + ' / ' + fmtNumber(stats.routes_total || 0);

      document.getElementById('kpi_routes_arrived_home').innerText =
        fmtNumber(stats.routes_salida_evento || 0) + ' / ' + fmtNumber(stats.routes_total || 0);

      document.getElementById('kpi_routes_en_estadio').innerText =
        fmtNumber(stats.routes_en_estadio || 0) + ' / ' + fmtNumber(stats.routes_total || 0);

      document.getElementById('kpi_activas_hacia_estadio').innerText =
        fmtNumber((stats.routes_active - stats.routes_en_estadio) || 0);

      $('#kpi_sin_problema').text(fmtNumber(stats.sin_problema || 0));
      $('#kpi_inconveniente').text(fmtNumber(stats.inconveniente || 0));
      $('#kpi_critico').text(fmtNumber(stats.falla || 0));

      $('#last-update').text(new Date().toLocaleString());

      // ---------- ECharts: Personas en estadio vs estimadas ----------
      if (!chartPersons) chartPersons = echarts.init(document.getElementById('chartPersons'));

      const personsOption = {
        tooltip: { trigger: 'axis' },
        xAxis: { type: 'category', data: ['En Ruta', 'Estimadas en ruta', 'En Estadio'] },
        yAxis: { type: 'value', min: 0 },
        series: [{
          type: 'bar',
          data: [
            stats.personas_en_ruta || 0,
            stats.total_estimated || 0,
            stats.personas_en_estadio || 0
          ],
          label: { show: true, position: 'top' }
        }]
      };
      chartPersons.setOption(personsOption);

      // ---------- ECharts: Estado de las rutas ----------
      if (!chartStatus) chartStatus = echarts.init(document.getElementById('chartStatus'));

      const statusOption = {
        tooltip: { trigger: 'item', formatter: '{b}: {c} ({d}%)' },
        legend: { orient: 'horizontal', bottom: 0 },
        color: ['#046205', '#ffa500', '#ff004c'],
        series: [{
          name: 'Estado',
          type: 'pie',
          radius: ['40%', '70%'],
          label: { show: true, formatter: '{b}: {c}' },
          labelLine: { show: true },
          data: [
            { value: stats.sin_problema || 0, name: 'Sin problema' },
            { value: stats.inconveniente || 0, name: 'Inconveniente' },
            { value: stats.falla || 0, name: 'Crítico' }
          ]
        }]
      };
      chartStatus.setOption(statusOption);

    } catch (err) {
      console.error(err);
    }
  }

  window.addEventListener('resize', () => {
    if (chartPersons) chartPersons.resize();
    if (chartStatus) chartStatus.resize();
  });

  let dataTable = null;

  async function renderRecentReports() {
    try {
      const resp = await fetchRecentReports(50);

      if (!resp || !resp.data || !Array.isArray(resp.data)) {
        console.warn("Respuesta inesperada en recent_reports:", resp);
        return;
      }

      const rows = resp.data;
      const tbody = $('#tblReports tbody');
      tbody.empty();

      rows.forEach((r, idx) => {
        const tr = $('<tr>');
        tr.append($('<td>').text(idx + 1));
        tr.append($('<td>').text(r.ruta_nombre || ''));
        tr.append($('<td>').text(r.comentario || ''));
        tr.append($('<td>').text(r.total_personas || 0));
        tr.append($('<td>').text(r.accion_nombre || 'Sin acción'));
        tr.append($('<td>').text(r.fecha_reporte || ''));
        tbody.append(tr);
      });

      if (dataTable) dataTable.destroy();
      dataTable = $('#tblReports').DataTable({
        paging: true,
        searching: true,
        info: false,
        pageLength: 10,
        order: [[5, 'desc']]
      });

    } catch (err) {
      console.error("Error renderRecentReports:", err);
    }
  }

  // MAP (tu lógica original abajo no la toqué)
  let mapInstance = null;
  let markers = [];
  let routeLines = [];

  function colorForStatus(status) {
    if (status === 'sin_problema') return '#046205';
    if (status === 'inconveniente') return '#ffa500';
    if (status === 'critico') return '#ff004c';
    return '#6c757d';
  }

  async function renderMap() {
    // --- aquí sigue EXACTAMENTE tu código original ---
    // (No lo reescribo para no tocar tu lógica visual; solo cambió el fetch con idevento)
    try {
      const routes = await fetchMapData();
      const mapEl = document.getElementById('map');

      if (!routes || routes.length === 0) {
        mapInstance = new google.maps.Map(mapEl, { center: { lat: 13.6929, lng: -89.2182 }, zoom: 8 });
        return;
      }

      let centerLat = 13.6929, centerLng = -89.2182;
      if (routes[0].path && routes[0].path.length > 0) {
        centerLat = parseFloat(routes[0].path[0].lat);
        centerLng = parseFloat(routes[0].path[0].lng);
      }

      mapInstance = new google.maps.Map(mapEl, { center: { lat: centerLat, lng: centerLng }, zoom: 8 });

      markers.forEach(m => m.setMap(null));
      markers = [];
      routeLines.forEach(l => l.setMap(null));
      routeLines = [];

      const bounds = new google.maps.LatLngBounds();
      const directionsService = new google.maps.DirectionsService();

      routes.forEach(rt => {
        const status = rt.status || 'sin_problema';
        const color = colorForStatus(status);

        const pathCoords = (rt.path || []).map(p => ({
          lat: parseFloat(p.lat),
          lng: parseFloat(p.lng)
        }));

        if (pathCoords.length >= 2) {
          const origin = pathCoords[0];
          const destination = pathCoords[pathCoords.length - 1];
          const waypoints = pathCoords.slice(1, -1).map(p => ({ location: p, stopover: false }));

          directionsService.route({
            origin, destination, waypoints,
            travelMode: google.maps.TravelMode.DRIVING,
            optimizeWaypoints: false
          }, (result, statusDir) => {
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
              mapInstance.fitBounds(bounds);
            }
          });
        }

        // marcador bus si existe
        if (rt.bus_lat && rt.bus_lng) {
          const pos = { lat: parseFloat(rt.bus_lat), lng: parseFloat(rt.bus_lng) };
          const marker = new google.maps.Marker({
            position: pos,
            map: mapInstance,
            title: rt.ruta_nombre || ('Ruta ' + rt.idruta)
          });
          markers.push(marker);
          bounds.extend(pos);
        }
      });

      if (!bounds.isEmpty()) mapInstance.fitBounds(bounds);

    } catch (e) {
      console.error("renderMap error:", e);
    }
  }

  async function refreshAll() {
    await renderKpisAndChart();
    await renderRecentReports();
    await renderMap();
  }

  // Eventos de filtros
  document.addEventListener('change', (e) => {
    if (e.target && (e.target.id === 'f_departamento' || e.target.id === 'f_tipo_accion' || e.target.id === 'f_idruta')) {
      renderMap();
    }
  });

  document.addEventListener('DOMContentLoaded', async () => {
    await initEventSelect();
    await loadFilters();
    await refreshAll();

    setInterval(refreshAll, 60000);
  });
</script>

<?php require __DIR__ . '/../templates/footer.php'; ?>