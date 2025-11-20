<?php
// reporte/lista_reportes.php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

// Validar parámetro idruta
if (!isset($_GET['idruta']) || empty($_GET['idruta'])) {
    // En lugar de morir en seco, mostramos un mensaje dentro del layout
    $idruta = null;
    $ruta   = null;
    $reportes = [];
    $errorMsg = "Ruta no válida. Falta el parámetro idruta.";
} else {
    $idruta = (int) $_GET['idruta'];
    $errorMsg = null;

    // Obtener info de la ruta
    $sqlRuta = "SELECT idruta, nombre, destino FROM ruta WHERE idruta = :idruta";
    $stmtRuta = $pdo->prepare($sqlRuta);
    $stmtRuta->execute([':idruta' => $idruta]);
    $ruta = $stmtRuta->fetch(PDO::FETCH_ASSOC);

    if (!$ruta) {
        $reportes = [];
        $errorMsg = "No se encontró la ruta con ID {$idruta}.";
    } else {
        // Obtener lista de reportes de la ruta
        $sqlRep = "
            SELECT 
                rep.idreporte,
                DATE_FORMAT(rep.fecha_reporte, '%Y-%m-%d %H:%i') AS fecha,
                p.punto_abordaje AS parada,
                a.nombre AS accion,
                rep.total_personas,
                rep.comentario
            FROM reporte rep
            LEFT JOIN paradas p ON rep.idparada = p.idparada
            LEFT JOIN acciones a ON rep.idaccion = a.idaccion
            WHERE rep.idruta = :idruta
            ORDER BY rep.fecha_reporte DESC
        ";
        $stmtRep = $pdo->prepare($sqlRep);
        $stmtRep->execute([':idruta' => $idruta]);
        $reportes = $stmtRep->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Configurar variables para el layout
$pageTitle   = $ruta ? ('Reportes - ' . $ruta['nombre']) : 'Listado de reportes';
$currentPage = 'reporte_listado';

require __DIR__ . '/../templates/header.php';
?>

<!-- Content Header -->
<section class="content-header">
  <div class="container-fluid">
    <div class="row mb-2">
      <div class="col-sm-6">
        <h1>Listado de reportes</h1>
        <?php if ($ruta): ?>
          <p class="text-muted mb-0">
            Ruta: <strong><?= htmlspecialchars($ruta['nombre']) ?></strong>
            <?php if (!empty($ruta['destino'])): ?>
              &mdash; Destino: <strong><?= htmlspecialchars($ruta['destino']) ?></strong>
            <?php endif; ?>
          </p>
        <?php endif; ?>
      </div>
      <div class="col-sm-6 text-right">
        <a href="<?= BASE_URL ?>/dashboard/dashboard_rutas.php" class="btn btn-sm btn-secondary mt-2">
          <i class="fas fa-arrow-left mr-1"></i> Volver al dashboard de rutas
        </a>
      </div>
    </div>
  </div>
</section>

<!-- Main content -->
<section class="content">
  <div class="container-fluid">

    <?php if ($errorMsg): ?>
      <div class="alert alert-danger">
        <?= htmlspecialchars($errorMsg) ?>
      </div>
    <?php else: ?>

      <div class="card">
        <div class="card-header">
          <h3 class="card-title">
            Reportes de la ruta #<?= (int)$ruta['idruta'] ?>
          </h3>
          <div class="card-tools">
            <span class="badge badge-primary">
              Total: <?= count($reportes) ?> reporte(s)
            </span>
          </div>
        </div>
        <div class="card-body table-responsive p-0">
          <?php if (empty($reportes)): ?>
            <div class="p-3">
              <em>No hay reportes registrados para esta ruta.</em>
            </div>
          <?php else: ?>
            <table class="table table-striped table-hover table-sm mb-0">
              <thead class="thead-light">
                <tr>
                  <th>#</th>
                  <th>ID Reporte</th>
                  <th>Fecha</th>
                  <th>Parada</th>
                  <th>Acción</th>
                  <th>Personas</th>
                  <th>Comentario</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($reportes as $idx => $r): ?>
                  <tr>
                    <td><?= $idx + 1 ?></td>
                    <td><?= (int)$r['idreporte'] ?></td>
                    <td><?= htmlspecialchars($r['fecha']) ?></td>
                    <td><?= htmlspecialchars($r['parada'] ?? '') ?></td>
                    <td><?= htmlspecialchars($r['accion'] ?? '') ?></td>
                    <td><?= (int)$r['total_personas'] ?></td>
                    <td><?= htmlspecialchars($r['comentario'] ?? '') ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>
      </div>

    <?php endif; ?>

  </div><!-- /.container-fluid -->
</section>

<?php
require __DIR__ . '/../templates/footer.php';
