<?php
// admin_eventos.php
require_once __DIR__ . '/../includes/db.php';

$pageTitle   = 'Administración de Eventos';
$currentPage = 'admin_eventos'; // si tu sidebar usa esto para "active"

$flash = ['type' => null, 'msg' => null];

function post($k, $default = null) {
  return isset($_POST[$k]) ? trim((string)$_POST[$k]) : $default;
}
function get($k, $default = null) {
  return isset($_GET[$k]) ? $_GET[$k] : $default;
}
function toInt($v, $default = 0) {
  $n = (int)$v;
  return $n > 0 ? $n : $default;
}

// --------------------------------------------------------
// ACCIONES: guardar, toggle_activo, set_actual, delete
// --------------------------------------------------------
try {

  // Guardar (crear / editar)
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'save') {
    $idevento     = toInt(post('idevento'));
    $nombre       = post('nombre', '');
    $fecha_inicio = post('fecha_inicio', null);
    $fecha_fin    = post('fecha_fin', null);
    $activo       = post('activo', '0') === '1' ? 1 : 0;
    $es_actual    = post('es_actual', '0') === '1' ? 1 : 0;

    if ($nombre === '') {
      throw new Exception("El nombre del evento es obligatorio.");
    }

    // Normalizar fechas vacías a NULL
    $fecha_inicio = ($fecha_inicio === '') ? null : $fecha_inicio;
    $fecha_fin    = ($fecha_fin === '') ? null : $fecha_fin;

    // Si es_actual = 1, aseguramos que sea el único
    if ($es_actual === 1) {
      $pdo->exec("UPDATE eventos SET es_actual = 0");
    }

    if ($idevento > 0) {
      $sql = "
        UPDATE eventos
        SET nombre = :nombre,
            fecha_inicio = :fi,
            fecha_fin = :ff,
            activo = :activo,
            es_actual = :es_actual
        WHERE idevento = :id
      ";
      $stmt = $pdo->prepare($sql);
      $stmt->execute([
        ':nombre' => $nombre,
        ':fi' => $fecha_inicio,
        ':ff' => $fecha_fin,
        ':activo' => $activo,
        ':es_actual' => $es_actual,
        ':id' => $idevento
      ]);

      $flash = ['type' => 'success', 'msg' => 'Evento actualizado correctamente.'];
    } else {
      $sql = "
        INSERT INTO eventos (nombre, fecha_inicio, fecha_fin, activo, es_actual)
        VALUES (:nombre, :fi, :ff, :activo, :es_actual)
      ";
      $stmt = $pdo->prepare($sql);
      $stmt->execute([
        ':nombre' => $nombre,
        ':fi' => $fecha_inicio,
        ':ff' => $fecha_fin,
        ':activo' => $activo,
        ':es_actual' => $es_actual
      ]);

      $flash = ['type' => 'success', 'msg' => 'Evento creado correctamente.'];
    }
  }

  // Toggle activo
  if (get('action') === 'toggle_activo') {
    $id = toInt(get('id'));
    if ($id <= 0) throw new Exception("ID inválido.");

    $stmt = $pdo->prepare("SELECT activo FROM eventos WHERE idevento = :id");
    $stmt->execute([':id' => $id]);
    $cur = (int)$stmt->fetchColumn();

    $new = ($cur === 1) ? 0 : 1;
    $stmt = $pdo->prepare("UPDATE eventos SET activo = :a WHERE idevento = :id");
    $stmt->execute([':a' => $new, ':id' => $id]);

    $flash = ['type' => 'success', 'msg' => 'Estado (activo) actualizado.'];
  }

  // Delete
  if (get('action') === 'delete') {
    $id = toInt(get('id'));
    if ($id <= 0) throw new Exception("ID inválido.");

    // Si hay rutas referenciando este evento, mejor bloquear (evitar FK issues)
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM ruta WHERE idevento = :id");
    $stmt->execute([':id' => $id]);
    $countRutas = (int)$stmt->fetchColumn();

    if ($countRutas > 0) {
      throw new Exception("No se puede eliminar: este evento tiene rutas asignadas ($countRutas).");
    }

    $stmt = $pdo->prepare("DELETE FROM eventos WHERE idevento = :id");
    $stmt->execute([':id' => $id]);

    $flash = ['type' => 'success', 'msg' => 'Evento eliminado.'];
  }

} catch (Throwable $e) {
  $flash = ['type' => 'danger', 'msg' => $e->getMessage()];
}

// --------------------------------------------------------
// Cargar evento a editar (si aplica)
// --------------------------------------------------------
$edit = null;
$editId = toInt(get('edit'));
if ($editId > 0) {
  $stmt = $pdo->prepare("SELECT * FROM eventos WHERE idevento = :id");
  $stmt->execute([':id' => $editId]);
  $edit = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$edit) {
    $flash = ['type' => 'warning', 'msg' => 'Evento no encontrado para editar.'];
  }
}

// --------------------------------------------------------
// Listado: sin vista, calculando "actual" también por fecha
// --------------------------------------------------------
$eventos = $pdo->query("
  SELECT
    idevento,
    nombre,
    fecha_inicio,
    fecha_fin,
    activo,
    CASE
      WHEN activo = 1 THEN 1
      WHEN fecha_inicio IS NOT NULL
       AND fecha_inicio <= CURDATE()
       AND (fecha_fin IS NULL OR fecha_fin >= CURDATE())
      THEN 1
      ELSE 0
    END AS actual_calc
  FROM eventos
  ORDER BY actual_calc DESC, activo DESC, fecha_inicio DESC, idevento DESC
")->fetchAll(PDO::FETCH_ASSOC);

// --------------------------------------------------------
// TEMPLATE
// --------------------------------------------------------
require __DIR__ . '/../templates/header.php';
?>

<section class="content-header">
  <div class="container-fluid">
    <div class="row mb-2 align-items-end">
      <div class="col-sm-8">
        <h1>Administración — Eventos</h1>
        <p class="text-muted mb-0">Crear, editar y seleccionar el evento actual.</p>
      </div>
      <div class="col-sm-4 text-right">
        <a href="admin_eventos.php" class="btn btn-sm btn-secondary">
          <i class="fas fa-sync-alt"></i> Refrescar
        </a>
      </div>
    </div>
  </div>
</section>

<section class="content">
  <div class="container-fluid">

    <?php if ($flash['msg']): ?>
      <div class="alert alert-<?= htmlspecialchars($flash['type']) ?> alert-dismissible fade show" role="alert">
        <?= htmlspecialchars($flash['msg']) ?>
        <button type="button" class="close" data-dismiss="alert" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
    <?php endif; ?>

    <!-- FORM CREAR/EDITAR -->
    <div class="card">
      <div class="card-header">
        <h3 class="card-title">
          <?= $edit ? 'Editar evento' : 'Crear nuevo evento' ?>
        </h3>
      </div>

      <form method="POST" action="admin_eventos.php">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="idevento" value="<?= $edit ? (int)$edit['idevento'] : 0 ?>">

        <div class="card-body">
          <div class="row">
            <div class="col-md-5">
              <div class="form-group">
                <label>Nombre del evento</label>
                <input type="text" name="nombre" class="form-control"
                       value="<?= htmlspecialchars($edit['nombre'] ?? '') ?>"
                       placeholder="Ej: Evento Fusalmo" required>
              </div>
            </div>

            <div class="col-md-3">
              <div class="form-group">
                <label>Fecha inicio</label>
                <input type="date" name="fecha_inicio" class="form-control"
                       value="<?= htmlspecialchars($edit['fecha_inicio'] ?? '') ?>">
              </div>
            </div>

            <div class="col-md-3">
              <div class="form-group">
                <label>Fecha fin</label>
                <input type="date" name="fecha_fin" class="form-control"
                       value="<?= htmlspecialchars($edit['fecha_fin'] ?? '') ?>">
              </div>
            </div>

            <div class="col-md-1"></div>
          </div>

          <div class="row">
            <div class="col-md-2">
              <div class="form-group">
                <label>Activo</label>
                <select name="activo" class="form-control">
                  <option value="1" <?= (($edit['activo'] ?? 1) == 1) ? 'selected' : '' ?>>Sí</option>
                  <option value="0" <?= (isset($edit['activo']) && (int)$edit['activo'] === 0) ? 'selected' : '' ?>>No</option>
                </select>
              </div>
            </div>

            <div class="col-md-3">
              <div class="form-group">
                <label>¿Marcar como actual?</label>
                <select name="es_actual" class="form-control">
                  <option value="0" <?= (isset($edit['es_actual']) && (int)$edit['es_actual'] === 0) ? 'selected' : '' ?>>No</option>
                  <option value="1" <?= (($edit['es_actual'] ?? 0) == 1) ? 'selected' : '' ?>>Sí</option>
                </select>
                <small class="text-muted">Si eliges “Sí”, se desmarca en los demás eventos.</small>
              </div>
            </div>

            <div class="col-md-7 d-flex align-items-end justify-content-end">
              <?php if ($edit): ?>
                <a href="admin_eventos.php" class="btn btn-secondary mr-2">
                  <i class="fas fa-times"></i> Cancelar
                </a>
              <?php endif; ?>
              <button type="submit" class="btn btn-primary">
                <i class="fas fa-save"></i> Guardar
              </button>
            </div>
          </div>

        </div>
      </form>
    </div>

    <!-- LISTADO -->
    <div class="card">
      <div class="card-header">
        <h3 class="card-title">Listado de eventos</h3>
      </div>

      <div class="card-body p-1">
        <div class="table-responsive">
          <table class="table table-striped table-sm mb-0">
            <thead>
              <tr>
                <th>ID</th>
                <th>Nombre</th>
                <th>Inicio</th>
                <th>Fin</th>
                <th>Activo</th>
                <th>Actual</th>
                <th style="width: 260px;">Acciones</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($eventos as $ev): ?>
                <tr>
                  <td><?= (int)$ev['idevento'] ?></td>
                  <td><?= htmlspecialchars($ev['nombre'] ?? '') ?></td>
                  <td><?= htmlspecialchars($ev['fecha_inicio'] ?? '') ?></td>
                  <td><?= htmlspecialchars($ev['fecha_fin'] ?? '') ?></td>

                  <td>
                    <?php if ((int)$ev['activo'] === 1): ?>
                      <span class="badge badge-success">Sí</span>
                    <?php else: ?>
                      <span class="badge badge-secondary">No</span>
                    <?php endif; ?>
                  </td>

                  <td>
                    <?php if ((int)$ev['actual_calc'] === 1): ?>
                      <span class="badge badge-primary">Actual</span>
                    <?php else: ?>
                      <span class="badge badge-light">—</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <a class="btn btn-sm btn-warning" href="admin_eventos.php?edit=<?= (int)$ev['idevento'] ?>">
                      <i class="fas fa-edit"></i> Editar
                    </a>

                    <a class="btn btn-sm btn-secondary" href="admin_eventos.php?action=toggle_activo&id=<?= (int)$ev['idevento'] ?>">
                      <i class="fas fa-toggle-on"></i> Activar/Des
                    </a>

                    <a class="btn btn-sm btn-danger" href="admin_eventos.php?action=delete&id=<?= (int)$ev['idevento'] ?>"
                       onclick="return confirm('¿Eliminar este evento? (Solo si no tiene rutas asignadas)');">
                      <i class="fas fa-trash"></i> Eliminar
                    </a>
                  </td>
                </tr>
              <?php endforeach; ?>

              <?php if (count($eventos) === 0): ?>
                <tr><td colspan="7" class="text-center text-muted p-3">No hay eventos registrados.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

  </div>
</section>

<?php require __DIR__ . '/../templates/footer.php'; ?>