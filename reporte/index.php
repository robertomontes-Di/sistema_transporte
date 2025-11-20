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

/* Obtener acciones */
try {
    $acciones = $pdo->query("SELECT idaccion, nombre FROM acciones ORDER BY nombre")->fetchAll();
} catch (Exception $e) {
    $acciones = [];
}

$pageTitle = "Crear Reporte";
$currentPage = "reporte_crear";

require "../templates/header.php";
?>

<!-- Content Wrapper -->
<div class="content-wrapper">

<section class="content-header">
  <div class="container-fluid">
    <h1>Nuevo Reporte</h1>
  </div>
</section>

<section class="content">
  <div class="container-fluid">

    <div class="row justify-content-center">
      <div class="col-md-6">

        <div class="card card-info">
          <div class="card-header">
            <h3 class="card-title">Registrar evento de ruta</h3>
          </div>

          <form id="formReporte">
            <div class="card-body">

              <div id="ajaxError" class="text-danger mb-2" style="display:none;"></div>

              <!-- Selección de ruta -->
              <div class="form-group">
                <label for="ruta">Ruta:</label>
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
                <label class="font-weight-bold">Encargado de ruta:</label>
                <div id="lblEncargado"></div>
              </div>

              <!-- Parada actual -->
              <div id="boxParada" class="mb-3" style="display:none;">
                <label class="font-weight-bold">Parada actual:</label>
                <div id="lblParada"></div>
              </div>

              <!-- Acción -->
              <div id="boxAcciones" class="form-group" style="display:none;">
                <label for="accion">Acción:</label>
                <select id="accion" name="idaccion" class="form-control"></select>
              </div>

              <!-- Personas -->
              <div class="form-group">
                <label for="total_personas">Cantidad de personas:</label>
                <input type="number" min="0" id="total_personas" name="total_personas"
                       class="form-control" required>
              </div>

              <div class="form-group">
                <label for="comentario">Comentario:</label>
                <input type="text" id="comentario" name="comentario" class="form-control">
              </div>

            </div>

            <div class="card-footer">
              <button type="submit" class="btn btn-primary">Guardar</button>
              <a href="../index.php" class="btn btn-secondary">Cancelar</a>
              <button type="button" id="btnListaReportes" class="btn btn-info"
                      style="display:none;">Ver lista de reportes</button>
            </div>

          </form>

        </div>
      </div>
    </div>

  </div>
</section>

</div> <!-- /.content-wrapper -->

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

        $("#ajaxError").hide();
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
                    $("#lblEncargado").text(`${data.encargado.nombre} (Tel: ${data.encargado.telefono})`);
                    $("#boxEncargado").fadeIn();
                }

                if (data.parada) {
                    $("#lblParada").text(data.parada.punto_abordaje);
                    $("#idparada").val(data.parada.idparada);
                    $("#boxParada").fadeIn();
                }

                $("#accion").empty();
                if (Array.isArray(data.acciones) && data.acciones.length > 0) {
                    data.acciones.forEach(a => {
                        $("#accion").append(`<option value="${a.idaccion}">${a.nombre}</option>`);
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

    $("#btnListaReportes").on("click", function () {
        var href = $(this).attr('data-href');
        if (!href) {
            alert('Seleccione una ruta primero.');
            return;
        }
        window.location.href = href;
    });

    var preselected = <?= json_encode($selectedRuta) ?>;
    if (preselected > 0) $("#ruta").trigger('change');

});
</script>

<?php require "../templates/footer.php"; ?>
