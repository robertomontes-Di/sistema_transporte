<?php
// admin/admin_rutas_listado.php

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';

// Obtener rutas
try {
    $rutas = $pdo->query("SELECT idruta, nombre, destino FROM ruta ORDER BY idruta DESC")->fetchAll();
} catch (Exception $e) {
    $rutas = [];
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

  </div><!-- /.container-fluid -->
</section>

</div><!-- /.content-wrapper -->

<script>
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
</script>

<?php require __DIR__ . '/../templates/footer.php'; ?>
