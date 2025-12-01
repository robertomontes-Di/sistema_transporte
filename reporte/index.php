<?php
session_start();

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

// Cargar todas las rutas (solo id + nombre)
try {
    $stmt = $pdo->query("
        SELECT r.idruta, r.nombre, r.destino, er.nombre AS encargado
        FROM ruta r
        LEFT JOIN encargado_ruta er ON er.idencargado_ruta = r.idencargado_ruta
        ORDER BY r.nombre ASC
    ");
    $rutas = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $rutas = [];
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Ingreso para reporte de ruta</title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<!-- Bootstrap -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">

<!-- Select2 CSS -->
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<style>
body {
    background: #eef1f5;
}
.login-container {
    max-width: 450px;
    margin: 60px auto;
}
.card-header {
    background: #0b7cc2;
    color: #fff;
    text-align: center;
}
.select2-container .select2-selection--single {
    height: 38px !important;
    padding: 4px !important;
}
.select2-selection__rendered {
    line-height: 28px !important;
}
.select2-selection__arrow {
    height: 34px !important;
}
</style>

</head>
<body>

<div class="login-container">
  <div class="card shadow">
    <div class="card-header">
      <h5 class="mb-0">Ingreso para reporte de ruta</h5>
      <small>Seleccione su ruta e ingrese la clave del líder</small>
    </div>

    <div class="card-body">

      <?php if (!empty($_GET['error'])): ?>
      <div class="alert alert-danger">
        <?= htmlspecialchars($_GET['error']) ?>
      </div>
      <?php endif; ?>

      <form method="post" action="login.php" autocomplete="off">

        <div class="form-group">
          <label for="idruta">Ruta</label>
          <select id="idruta" name="idruta" class="form-control" required>
            <option value="">Seleccione una ruta…</option>
            <?php foreach ($rutas as $r): ?>
              <option value="<?= $r['idruta'] ?>">
                <?= htmlspecialchars($r['nombre']) ?> —
                <?= htmlspecialchars($r['destino']) ?>
                <?php if ($r['encargado']): ?>
                  (<?= htmlspecialchars($r['encargado']) ?>)
                <?php endif; ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group">
          <label for="clave">Clave (teléfono del líder)</label>
          <input type="number"
                 id="clave"
                 name="clave"
                 class="form-control"
                 required
                 placeholder="Ejemplo: 77778888">
        </div>

        <button type="submit" class="btn btn-primary btn-block">
          Ingresar
        </button>

      </form>

    </div>
  </div>
</div>

<!-- jQuery -->
<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>

<!-- Select2 JS -->
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
$(document).ready(function() {
    $('#idruta').select2({
        placeholder: "Escriba para buscar su ruta…",
        allowClear: true,
        width: '100%',
        language: {
            noResults: () => "No se encontraron rutas",
            searching: () => "Buscando…"
        }
    });
});
</script>

</body>
</html>
