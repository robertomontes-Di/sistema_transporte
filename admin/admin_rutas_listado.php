<?php
require_once __DIR__ . '/../includes/db.php';
$rutas = $pdo->query("SELECT idruta, nombre, destino FROM ruta ORDER BY idruta DESC")->fetchAll();
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Listado de Rutas - Admin</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"/>
</head>
<body class="container py-4">
<h2>Rutas</h2>
<p><a href="admin_ruta.php" class="btn btn-primary">Crear nueva ruta</a></p>
<table class="table table-striped">
  <thead><tr><th>ID</th><th>Nombre</th><th>Destino</th><th>Acciones</th></tr></thead>
  <tbody>
  <?php foreach($rutas as $r): ?>
    <tr>
      <td><?=htmlspecialchars($r['idruta'])?></td>
      <td><?=htmlspecialchars($r['nombre'])?></td>
      <td><?=htmlspecialchars($r['destino'])?></td>
      <td>
        <a href="admin_ruta.php?id=<?= $r['idruta'] ?>" class="btn btn-sm btn-info">Editar</a>
        <button class="btn btn-sm btn-danger btn-delete" data-id="<?= $r['idruta'] ?>">Eliminar</button>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
$(document).on('click', '.btn-delete', function(){
  if (!confirm('Eliminar ruta y sus paradas?')) return;
  const id = $(this).data('id');
  $.post('ajax/delete_ruta.php',{ idruta: id }, function(resp){
    try{
      const r = JSON.parse(resp);
      if (r.success) location.reload(); else alert('Error: '+(r.error||''));
    }catch(e){
      alert('Error inesperado');
    }
  });
});
</script>
</body>
</html>
