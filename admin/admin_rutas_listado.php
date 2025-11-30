<?php
// admin/admin_rutas_listado.php

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';

// Obtener rutas
try {
    $rutas = $pdo->query("
        SELECT idruta, nombre, destino
        FROM ruta
        ORDER BY idruta DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $rutas = [];
}

// Obtener encargados de ruta (líderes)
try {
    $encargados = $pdo->query("
        SELECT idencargado_ruta, nombre, telefono
        FROM encargado_ruta
        ORDER BY nombre ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $encargados = [];
}

$pageTitle   = "Listado de Rutas";
$currentPage = "admin_rutas";

require __DIR__ . '/../templates/header.php';
?>

<!-- Content Wrapper -->
<div class="content-wrapper">

  <section class="content-header">
    <div class="container-fluid">
      <div class="row mb-2">
        <div class="col-sm-6">
          <h1>Administración de rutas</h1>
        </div>
        <div class="col-sm-6 text-right">
          <a href="admin_ruta.php" class="btn btn-primary">
            <i class="fas fa-plus"></i> Nueva ruta
          </a>
        </div>
      </div>
    </div>
  </section>

  <section class="content">
    <div class="container-fluid">

      <!-- CARD: LISTADO DE RUTAS -->
      <div class="card">
        <div class="card-header">
          <h3 class="card-title">Rutas registradas</h3>
        </div>

        <div class="card-body table-responsive">
          <table class="table table-striped table-hover table-sm">
            <thead class="thead-light">
              <tr>
                <th>ID</th>
                <th>Nombre</th>
                <th>Destino</th>
                <th style="width: 150px;">Acciones</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($rutas as $r): ?>
              <tr>
                <td><?= htmlspecialchars($r['idruta']) ?></td>
                <td><?= htmlspecialchars($r['nombre']) ?></td>
                <td><?= htmlspecialchars($r['destino']) ?></td>
                <td>
                  <a href="admin_ruta.php?id=<?= (int)$r['idruta'] ?>" class="btn btn-sm btn-info" title="Editar ruta">
                    <i class="fas fa-edit"></i>
                  </a>
                  <button
                    class="btn btn-sm btn-danger btn-delete-ruta"
                    data-id="<?= (int)$r['idruta'] ?>"
                    title="Eliminar ruta"
                  >
                    <i class="fas fa-trash"></i>
                  </button>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>

          <?php if (empty($rutas)): ?>
            <div class="p-3"><em>No hay rutas registradas.</em></div>
          <?php endif; ?>

        </div>
      </div>

      <!-- CARD: LÍDERES DE RUTA -->
      <div class="card mt-3">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h3 class="card-title mb-0">Líderes de ruta</h3>
          <button class="btn btn-success btn-sm" id="btnNuevoEncargado">
            <i class="fas fa-user-plus"></i> Nuevo líder de ruta
          </button>
        </div>

        <div class="card-body table-responsive">
          <table class="table table-striped table-hover table-sm">
            <thead class="thead-light">
              <tr>
                <th>ID</th>
                <th>Nombre</th>
                <th>Teléfono (clave inicial)</th>
                <th style="width: 150px;">Acciones</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($encargados as $e): ?>
              <tr>
                <td><?= (int)$e['idencargado_ruta'] ?></td>
                <td><?= htmlspecialchars($e['nombre']) ?></td>
                <td><?= htmlspecialchars($e['telefono']) ?></td>
                <td>
                  <button
                    class="btn btn-sm btn-info btn-edit-enc"
                    data-id="<?= (int)$e['idencargado_ruta'] ?>"
                    data-nombre="<?= htmlspecialchars($e['nombre'], ENT_QUOTES) ?>"
                    data-telefono="<?= htmlspecialchars($e['telefono'], ENT_QUOTES) ?>"
                    title="Editar líder"
                  >
                    <i class="fas fa-edit"></i>
                  </button>
                  <button
                    class="btn btn-sm btn-danger btn-del-enc"
                    data-id="<?= (int)$e['idencargado_ruta'] ?>"
                    title="Eliminar líder"
                  >
                    <i class="fas fa-trash"></i>
                  </button>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>

          <?php if (empty($encargados)): ?>
            <div class="p-3"><em>No hay líderes de ruta registrados.</em></div>
          <?php endif; ?>
        </div>
      </div>

    </div><!-- /.container-fluid -->
  </section>

</div><!-- /.content-wrapper -->

<!-- MODAL: Crear / editar líder de ruta -->
<div class="modal fade" id="modalEncargado" tabindex="-1" role="dialog" aria-labelledby="modalEncargadoLabel" aria-hidden="true">
  <div class="modal-dialog" role="document">
    <form id="formEncargado" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="modalEncargadoLabel">Nuevo líder de ruta</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div class="modal-body">

        <input type="hidden" name="idencargado_ruta" id="enc_id">

        <div class="form-group">
          <label for="enc_nombre">Nombre del líder</label>
          <input
            type="text"
            class="form-control"
            name="nombre"
            id="enc_nombre"
            required
          >
        </div>

        <div class="form-group">
          <label for="enc_telefono">Teléfono (clave de acceso)</label>
          <input
            type="text"
            class="form-control"
            name="telefono"
            id="enc_telefono"
            required
          >
          <small class="form-text text-muted">
            Este número se usará como clave de acceso al módulo de reportes (puede cambiarse después).
          </small>
        </div>

      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
        <button type="submit" class="btn btn-primary">Guardar</button>
      </div>
    </form>
  </div>
</div>

<script>
$(function () {
  // Eliminar ruta
  $(document).on('click', '.btn-delete-ruta', function () {
    if (!confirm('¿Eliminar esta ruta y todas sus paradas?')) return;

    const id = $(this).data('id');

    $.post('ajax/delete_ruta.php', { idruta: id }, function (resp) {
      try {
        const r = JSON.parse(resp);
        if (r.success) {
          location.reload();
        } else {
          alert('Error: ' + (r.error || ''));
        }
      } catch (e) {
        alert('Error inesperado en la respuesta del servidor.');
      }
    });
  });

  // NUEVO: ABRIR MODAL "NUEVO LÍDER"
  $('#btnNuevoEncargado').on('click', function () {
    $('#enc_id').val('');
    $('#enc_nombre').val('');
    $('#enc_telefono').val('');
    $('#modalEncargadoLabel').text('Nuevo líder de ruta');
    $('#modalEncargado').modal('show');
  });

  // NUEVO: CARGAR DATOS PARA EDITAR LÍDER
  $(document).on('click', '.btn-edit-enc', function () {
    const id       = $(this).data('id');
    const nombre   = $(this).data('nombre');
    const telefono = $(this).data('telefono');

    $('#enc_id').val(id);
    $('#enc_nombre').val(nombre);
    $('#enc_telefono').val(telefono);
    $('#modalEncargadoLabel').text('Editar líder de ruta');
    $('#modalEncargado').modal('show');
  });

  // NUEVO: GUARDAR (INSERT/UPDATE) LÍDER
  $('#formEncargado').on('submit', function (e) {
    e.preventDefault();

    $.post('ajax/save_encargado_ruta.php', $(this).serialize(), function (resp) {
      if (typeof resp === 'string') {
        try { resp = JSON.parse(resp); } catch (e) { resp = { success: false, error: 'Respuesta inválida' }; }
      }

      if (resp.success) {
        $('#modalEncargado').modal('hide');
        location.reload();
      } else {
        alert('Error al guardar el líder: ' + (resp.error || ''));
      }
    }, 'json').fail(function () {
      alert('Error de comunicación con el servidor.');
    });
  });

  // NUEVO: ELIMINAR LÍDER
  $(document).on('click', '.btn-del-enc', function () {
    if (!confirm('¿Eliminar este líder de ruta? (Si está asignado a una ruta puede fallar)')) return;

    const id = $(this).data('id');

    $.post('ajax/delete_encargado_ruta.php', { idencargado_ruta: id }, function (resp) {
      if (typeof resp === 'string') {
        try { resp = JSON.parse(resp); } catch (e) { resp = { success: false, error: 'Respuesta inválida' }; }
      }

      if (resp.success) {
        location.reload();
      } else {
        alert('No se pudo eliminar el líder: ' + (resp.error || ''));
      }
    }, 'json').fail(function () {
      alert('Error de comunicación con el servidor.');
    });
  });

});
</script>

<?php require __DIR__ . '/../templates/footer.php'; ?>
