<?php
require_once __DIR__ . '/../includes/db.php';

$sql = "
SELECT 
    SUM(rep.total_personas) AS total_personas,
    rep.idruta AS ruta,
    MAX(er.nombre) AS nombre_encargado,
    MAX(r.nombre) AS nombre_ruta
FROM transport.reporte rep
LEFT JOIN ruta r 
    ON r.idruta = rep.idruta
LEFT JOIN encargado_ruta er 
    ON er.idencargado_ruta = r.idencargado_ruta
GROUP BY ruta
ORDER BY ruta
";

$stmt = $pdo->query($sql);
$data = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = "Resumen por Ruta";
$currentPage = "reporte_resumen";

require __DIR__ . '/../templates/header.php';
?>

<div class="container-fluid mt-4">
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Resumen de Rutas (Total Personas)</h3>
        </div>

        <div class="card-body">

            <!-- Tabla -->
            <table id="tblResumen" class="table table-striped table-bordered table-sm" style="width:100%">
                <thead>
                    <tr>
                        <th>Ruta</th>
                        <th>Nombre Ruta</th>
                        <th>Encargado</th>
                        <th>Total Personas</th>
                    </tr>
                </thead>
                <thead>
                    <!-- Filtros individuales -->
                    <tr class="filters">
                        <th><input type="text" class="form-control form-control-sm" placeholder="Buscar ruta"></th>
                        <th><input type="text" class="form-control form-control-sm" placeholder="Buscar nombre"></th>
                        <th><input type="text" class="form-control form-control-sm" placeholder="Buscar encargado"></th>
                        <th><input type="text" class="form-control form-control-sm" placeholder="Buscar total"></th>
                    </tr>
                </thead>

                <tbody>
                    <?php foreach ($data as $row): ?>
                        <tr>
                            <td><?= htmlspecialchars($row['ruta']) ?></td>
                            <td><?= htmlspecialchars($row['nombre_ruta']) ?></td>
                            <td><?= htmlspecialchars($row['nombre_encargado']) ?></td>
                            <td><?= number_format($row['total_personas']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

        </div>
    </div>
</div>

<script>
// ---- Activar DataTable con filtros por columna ----

$(document).ready(function() {
    // Crear DataTable
    var table = $('#tblResumen').DataTable({
        orderCellsTop: true,
        fixedHeader: true,
        pageLength: 25,
    });

    // Activar filtros individuales
    $('#tblResumen thead tr.filters th').each(function(i) {
        $('input', this).on('keyup change', function() {
            if (table.column(i).search() !== this.value) {
                table
                    .column(i)
                    .search(this.value)
                    .draw();
            }
        });
    });
});
</script>

<?php require __DIR__ . '/../templates/footer.php'; ?>
