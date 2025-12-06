<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';

/* ================================================
   CONSULTA GLOBAL DE TODOS LOS REPORTES
   ================================================ */

$sql = "
    SELECT 
        r.idreporte,
        r.fecha_reporte AS fecha,
        r.idruta,
        rt.nombre AS nombre_ruta,
        a.nombre AS accion,
        r.total_personas,
        r.comentario,
        ag.nombre as nombre_agente,
        er.nombre as nombre_encargado
    FROM reporte r
    LEFT JOIN ruta rt             ON rt.idruta = r.idruta
    LEFT JOIN acciones a          ON a.idaccion = r.idaccion
    LEFT JOIN agente ag           ON ag.idagente = rt.idagente
    LEFT JOIN encargado_ruta er   ON er.idencargado_ruta = rt.idencargado_ruta
    ORDER BY r.fecha_reporte DESC, r.idreporte DESC
";

$stmt = $pdo->query($sql);
$reportes = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* Datos para layout */
$pageTitle   = "Reportes Globales";
$currentPage = "monitoreo_listado";

require __DIR__ . '/../templates/header.php';
?>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">

<!-- Content Header -->
<section class="content-header">
  <div class="container-fluid">
    <div class="row mb-2">
      <div class="col-sm-8">
        <h1>Reportes Globales</h1>
        <p class="text-muted mb-0">Resumen de todos los reportes realizados</p>
      </div>
    </div>
  </div>
</section>

<!-- Main content -->
<section class="content">
  <div class="container-fluid">

    <div class="card">

      <div class="card-header">
        <h3 class="card-title">Listado de Reportes</h3>
      </div>

      <div class="card-body table-responsive">

        <table id="tblReportes" class="table table-striped table-bordered table-hover table-sm" style="width:100%;">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Fecha</th>
                    <th>Ruta</th>
                    <th>Encargado</th>
                    <th>Agente</th>
                    <th>Acción</th>
                    <th>Personas</th>
                    <th>Comentario</th>
                </tr>
            </thead>

            <!-- Filtros individuales -->
            <thead>
              <tr class="filters">
                <th><input type="text" class="form-control form-control-sm" placeholder="Buscar ID"></th>
                <th><input type="text" class="form-control form-control-sm" placeholder="Buscar fecha"></th>
                <th><input type="text" class="form-control form-control-sm" placeholder="Buscar ruta"></th>
                <th><input type="text" class="form-control form-control-sm" placeholder="Encargado"></th>
                <th><input type="text" class="form-control form-control-sm" placeholder="Agente"></th>
                <th><input type="text" class="form-control form-control-sm" placeholder="Acción"></th>
                <th><input type="text" class="form-control form-control-sm" placeholder="Personas"></th>
                <th><input type="text" class="form-control form-control-sm" placeholder="Comentario"></th>
              </tr>
            </thead>

            <tbody>
              <?php foreach ($reportes as $r): ?>
                <tr>
                  <td><?= (int)$r['idreporte'] ?></td>
                  <td><?= htmlspecialchars($r['fecha']) ?></td>
                  <td><?= htmlspecialchars($r['idruta'] . ' - ' . ($r['nombre_ruta'] ?? '')) ?></td>
                  <td><?= htmlspecialchars($r['nombre_encargado'] ?? '') ?></td>
                  <td><?= htmlspecialchars($r['nombre_agente'] ?? '') ?></td>
                  <td><?= htmlspecialchars($r['accion'] ?? '') ?></td>
                  <td><?= (int)$r['total_personas'] ?></td>
                  <td><?= htmlspecialchars($r['comentario'] ?? '') ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>

        </table>

      </div>

    </div>

  </div>
</section>

<!-- DATATABLE JS -->
<script>
$(document).ready(function() {

    var tabla = $('#tblReportes').DataTable({
        pageLength: 25,
        orderCellsTop: true,
        fixedHeader: true
    });

    // Filtros individuales por columna
    $('#tblReportes thead tr.filters th').each(function(i){
        $('input', this).on('keyup change', function(){
            if (tabla.column(i).search() !== this.value) {
                tabla.column(i).search(this.value).draw();
            }
        });
    });

});
</script>

<?php
require __DIR__ . '/../templates/footer.php';
?>
