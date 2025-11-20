<?php
ini_set('display_errors', 0);
error_reporting(0);

require_once "../includes/db.php";
require_once "../includes/config.php";

/* Obtener rutas */
try {
    $rutas = $pdo->query("SELECT idruta, nombre FROM ruta ORDER BY nombre")->fetchAll();
} catch (Exception $e) {
    $rutas = [];
}

$selectedRuta = isset($_GET['idruta']) ? intval($_GET['idruta']) : 0;

/* Obtener acciones (por si luego las necesitas) */
try {
    $acciones = $pdo->query("SELECT idaccion, nombre FROM acciones ORDER BY nombre")->fetchAll();
} catch (Exception $e) {
    $acciones = [];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Crear Reporte</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- Bootstrap 4.6 (local, mismo que usa el sistema) -->
    <link rel="stylesheet" href="<?= BASE_URL ?>/dist/bootstrap-4.6.2/css/bootstrap.min.css">

    <!-- AdminLTE 3.2 (local) -->
    <link rel="stylesheet" href="<?= BASE_URL ?>/dist/AdminLTE-3.2.0/dist/css/adminlte.min.css">

    <!-- (Opcional) Font Awesome si luego quisieras iconos aquí -->
    <!--
    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css"
          crossorigin="anonymous" referrerpolicy="no-referrer" />
    -->

    <style>
        body {
            background-color: #f4f6f9; /* mismo fondo que AdminLTE */
        }
        .report-wrapper {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 15px;
        }
        .report-card {
            width: 100%;
            max-width: 520px;
        }
    </style>
</head>
<body>

<div class="report-wrapper">

  <div class="report-card">

    <div class="card card-info">
      <div class="card-header">
        <h3 class="card-title mb-0">Nuevo reporte de ruta</h3>
      </div>

      <form id="formReporte">
        <div class="card-body">

          <p class="text-muted mb-3">
            Completa los datos para registrar el evento reportado por el personal en ruta.
          </p>

          <div id="ajaxError" class="alert alert-danger py-2 px-3 mb-3" style="display:none;"></div>

          <!-- Selección de ruta -->
          <div class="form-group">
            <label for="ruta">Ruta</label>
            <select id="ruta" name="idruta" class="form-control" required>
              <option value="">Seleccione una ruta</option>
              <?php foreach ($rutas as $r): ?>
                <option value="<?= $r['idruta'] ?>"
                  <?= $selectedRuta === (int)$r['idruta'] ? 'selected' : '' ?>>
                  <?= htmlspecialchars($r['nombre']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <input type="hidden" id="idparada" name="idparada" value="">

          <!-- Encargado -->
          <div id="boxEncargado" class="mb-3" style="display:none;">
            <label class="font-weight-bold d-block">Encargado de ruta</label>
            <div id="lblEncargado" class="text-muted"></div>
          </div>

          <!-- Parada actual -->
          <div id="boxParada" class="mb-3" style="display:none;">
            <label class="font-weight-bold d-block">Parada actual</label>
            <div id="lblParada" class="text-muted"></div>
          </div>

          <!-- Acción -->
          <div id="boxAcciones" class="form-group" style="display:none;">
            <label for="accion">Acción</label>
            <select id="accion" name="idaccion" class="form-control"></select>
          </div>

          <!-- Personas -->
          <div class="form-group">
            <label for="total_personas">Cantidad de personas</label>
            <input type="number" min="0" id="total_personas" name="total_personas"
                   class="form-control" required>
          </div>

          <!-- Comentario -->
          <div class="form-group">
            <label for="comentario">Comentario</label>
            <input type="text" id="comentario" name="comentario" class="form-control"
                   placeholder="Detalle breve del evento (opcional)">
          </div>

        </div>

        <div class="card-footer d-flex justify-content-between">
          <a href="../index.php" class="btn btn-secondary">Cancelar</a>
          <div>
            <button type="button" id="btnListaReportes"
                    class="btn btn-info mr-2"
                    style="display:none;">
              Ver lista de reportes
            </button>
            <button type="submit" class="btn btn-primary">
              Guardar
            </button>
          </div>
        </div>

      </form>

    </div>

  </div>

</div> <!-- /.report-wrapper -->

<!-- jQuery para el AJAX del form -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

<script>
$(function () {

    $("#formReporte").on("submit", function(e) {
        e.preventDefault();

        let btn = $("button[type=submit]");
        btn.prop("disabled", true).text("Guardando...");

        $.ajax({
            url: "guardar.php",
            method: "POST",
            data: $(this).serialize(),
            dataType: "json",
            success: function(resp) {
                btn.prop("disabled", false).text("Guardar");

                if (resp.success) {
                    alert(resp.message || "Reporte guardado.");
                    if (resp.redirect) window.location = resp.redirect;
                } else {
                    alert(resp.error || "Error al guardar.");
                }
            },
            error: function() {
                btn.prop("disabled", false).text("Guardar");
                alert("Error del servidor.");
            }
        });
    });

    $("#ruta").on("change", function () {

        $("#ajaxError").hide().text('');
        $("#boxEncargado, #boxParada, #boxAcciones").hide();
        $("#idparada").val("");

        var idruta = $(this).val();

        if (!idruta) {
            $("#btnListaReportes").hide().removeAttr('data-href');
            return;
        }

        $.ajax({
            url: "ruta_info.php",
            data: { idruta: idruta },
            type: "GET",
            dataType: "json",
            success: function (data) {

                if (data.encargado) {
                    $("#lblEncargado").text(
                        data.encargado.nombre + " (Tel: " + data.encargado.telefono + ")"
                    );
                    $("#boxEncargado").fadeIn();
                }

                if (data.parada) {
                    $("#lblParada").text(data.parada.punto_abordaje);
                    $("#idparada").val(data.parada.idparada);
                    $("#boxParada").fadeIn();
                }

                $("#accion").empty();
                if (Array.isArray(data.acciones) && data.acciones.length > 0) {
                    data.acciones.forEach(function(a){
                        $("#accion").append(
                            '<option value="' + a.idaccion + '">' + a.nombre + '</option>'
                        );
                    });
                    $("#boxAcciones").fadeIn();
                }

                $("#btnListaReportes")
                    .attr('data-href', 'lista_reportes.php?idruta=' + encodeURIComponent(idruta))
                    .fadeIn();
            },

            error: function () {
                $("#ajaxError").text("Error al obtener datos de la ruta.").show();
            }
        });
    });

    var preselected = <?= json_encode($selectedRuta) ?>;
    if (preselected > 0) {
        $("#ruta").val(preselected).trigger('change');
    }
});
</script>

<?php
// Footer global del sistema (JS de AdminLTE, etc.)
require "../templates/footer.php";
?>

</body>
</html>
