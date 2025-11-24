<?php
// admin/admin_rutas_listado.php

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';

// =============================
// 1) Rutas (para el listado)
// =============================
try {
    $rutas = $pdo->query("
        SELECT idruta, nombre, destino
        FROM ruta
        ORDER BY idruta DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $rutas = [];
}

// =============================
// 2) Rutas + líder + clave
// =============================
try {
    $sqlLideres = "
        SELECT 
            r.idruta,
            r.nombre,
            r.destino,
            er.nombre  AS encargado_nombre,
            er.telefono,
            rc.ultima_actualizacion
        FROM ruta r
        LEFT JOIN encargado_ruta er ON er.idencargado_ruta = r.idencargado_ruta
        LEFT JOIN ruta_clave rc     ON rc.idruta = r.idruta
        ORDER BY r.idruta DESC
    ";
    $lideres = $pdo->query($sqlLideres)->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $lideres = [];
}

// Mapa simple idruta -> info para usar en JS
$infoPorRuta = [];
foreach ($lideres as $row) {
    $infoPorRuta[$row['idruta']] = [
        'encargado' => $row['encargado_nombre'] ?? '',
        'telefono'  => $row['telefono'] ?? '',
        'ultima'    => $row['ultima_actualizacion'] ?? '',
    ];
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
        <h1>Listado de Rutas</h1>
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

    <!-- ==========================
         A) Tabla de rutas
         ========================== -->
    <div class="card mb-3">
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
                <a href="admin_ruta.php?id=<?= $r['idruta'] ?>" class="btn btn-sm btn-info">
                  <i class="fas fa-edit"></i>
                </a>
                <button class="btn btn-sm btn-danger btn-delete" data-id="<?= $r['idruta'] ?>">
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

    <!-- ==========================
         B) Gestión de líder y clave
         ========================== -->
    <div class="card">
      <div class="card-header">
        <h3 class="card-title">Líderes de ruta y claves de acceso</h3>
      </div>
      <div class="card-body">

        <div class="row">
          <!-- Formulario para asignar / cambiar clave -->
          <div class="col-md-5 border-right">
            <h5>Configurar clave de acceso</h5>

            <form id="formClaveRuta" autocomplete="off">
              <div class="form-group">
                <label for="selRutaClave">Ruta</label>
                <select name="idruta" id="selRutaClave" class="form-control" required>
                  <option value="">Seleccione una ruta…</option>
                  <?php foreach ($rutas as $r): ?>
                    <option value="<?= (int)$r['idruta'] ?>">
                      #<?= (int)$r['idruta'] ?> - <?= htmlspecialchars($r['nombre']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <small id="infoRutaSeleccionada" class="form-text text-muted">
                  Seleccione una ruta para ver el líder actual y sugerir la clave.
                </small>
              </div>

              <div class="form-group">
                <label for="claveRuta">Clave de acceso</label>
                <input type="text"
                       name="clave"
                       id="claveRuta"
                       class="form-control"
                       required
                       autocomplete="new-password">
                <small class="form-text text-muted">
                  Si dejas el teléfono del líder, lo usaremos como clave. Puedes escribir una clave distinta si lo deseas.
                </small>
              </div>

              <button type="submit" class="btn btn-primary">
                <i class="fas fa-save"></i> Guardar / actualizar clave
              </button>
            </form>
          </div>

          <!-- Tabla resumen de líderes -->
          <div class="col-md-7">
            <h5 class="mb-2">Resumen de rutas y líderes</h5>
            <div class="table-responsive">
              <table class="table table-sm table-striped mb-0">
                <thead>
                  <tr>
                    <th>Ruta</th>
                    <th>Destino</th>
                    <th>Líder</th>
                    <th>Teléfono</th>
                    <th>Clave configurada</th>
                    <th>Última actualización</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (empty($lideres)): ?>
                    <tr>
                      <td colspan="6"><em>No hay rutas configuradas todavía.</em></td>
                    </tr>
                  <?php else: ?>
                    <?php foreach ($lideres as $l): ?>
                      <tr>
                        <td>#<?= (int)$l['idruta'] . ' - ' . htmlspecialchars($l['nombre']) ?></td>
                        <td><?= htmlspecialchars($l['destino'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($l['encargado_nombre'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($l['telefono'] ?? '-') ?></td>
                        <td>
                          <?php if (!empty($l['ultima_actualizacion'])): ?>
                            <span class="badge badge-success">Sí</span>
                          <?php else: ?>
                            <span class="badge badge-secondary">No</span>
                          <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($l['ultima_actualizacion'] ?? '-') ?></td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div><!-- /.row -->

      </div>
    </div>

  </div><!-- /.container-fluid -->
</section>

</div><!-- /.content-wrapper -->

<script>
// ---------------- Eliminar ruta ----------------
$(document).on('click', '.btn-delete', function(){
  if (!confirm('¿Eliminar esta ruta y todas sus paradas?')) return;

  const id = $(this).data('id');

  $.post('ajax/delete_ruta.php', { idruta: id }, function(resp){
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

// ---------------- Gestión de clave ----------------
const rutaInfo = <?=
    json_encode($infoPorRuta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
?>;

$('#selRutaClave').on('change', function () {
  const id = this.value;
  const infoBox = $('#infoRutaSeleccionada');
  const inputClave = $('#claveRuta');

  if (!id || !rutaInfo[id]) {
    infoBox.text('Seleccione una ruta para ver el líder actual y sugerir la clave.');
    inputClave.val('');
    return;
  }

  const info = rutaInfo[id];
  let texto = 'Líder asignado: ' + (info.encargado || '—');
  if (info.telefono) {
    texto += ' | Tel: ' + info.telefono + '.';
    // sugerir teléfono como clave por defecto
    inputClave.val(info.telefono);
  } else {
    inputClave.val('');
  }

  if (info.ultima) {
    texto += ' Última actualización de clave: ' + info.ultima;
  }

  infoBox.text(texto);
});

// Enviar clave por AJAX a admin/ajax/guardar_clave_ruta.php
$('#formClaveRuta').on('submit', function(e){
  e.preventDefault();

  const $btn = $(this).find('button[type=submit]');
  $btn.prop('disabled', true);

  $.post('ajax/guardar_clave_ruta.php', $(this).serialize(), function(resp){
    if (typeof resp === 'string') {
      try { resp = JSON.parse(resp); } catch (e) { resp = null; }
    }
    if (resp && resp.success) {
      alert('Clave guardada correctamente.');
      location.reload();
    } else {
      alert('Error al guardar clave: ' + (resp && resp.error ? resp.error : 'Respuesta no válida'));
    }
  }, 'json').fail(function(){
    alert('Error de comunicación con el servidor.');
  }).always(function(){
    $btn.prop('disabled', false);
  });
});
</script>

<?php require __DIR__ . '/../templates/footer.php'; ?>
