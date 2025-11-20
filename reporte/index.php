<?php
ini_set('display_errors', 0);
error_reporting(0);
require_once "../includes/db.php";
require_once "../includes/config.php";

/* Obtener rutas */
$sql_rutas = "SELECT idruta, nombre FROM ruta ORDER BY nombre";
try {
    $stmt = $pdo->query($sql_rutas);
    $rutas = $stmt->fetchAll();
} catch (Exception $e) {
    $rutas = [];
}

// If page receives ?idruta=..., preselect that route
$selectedRuta = isset($_GET['idruta']) ? intval($_GET['idruta']) : 0;

/* Obtener acciones */
$sql_acciones = "SELECT idaccion, nombre FROM acciones ORDER BY nombre";
try {
    $stmt2 = $pdo->query($sql_acciones);
    $acciones = $stmt2->fetchAll();
} catch (Exception $e) {
    $acciones = [];
}
$title = "Crear Registro";
include "../templates/header.php";
include "../templates/navbar.php";
//include "../templates/sidebar.php";
?>
<main class="app-main" id="main" tabindex="-1">
<div class="app-content">
          <!--begin::Container-->
          <div class="container-fluid">
            <!--begin::Row-->
          <div class="row g-4">
              <div class="col-md-6">
               <div class="card card-info card-outline mb-4">
                <div class="card-header">
<div class="card-title">Nuevo Reporte</div>

<div id="ajaxError" style="color: #b00020; display:none; margin-bottom:12px;"></div>

<form id="formReporte" class="needs-validation" novalidate="">
    <div class="card-body">
<label for="ruta">Ruta:</label>
<select id="ruta" name="idruta">
    <option value="">Seleccione una ruta</option>
    <?php foreach ($rutas as $r) : ?>
        <option value="<?php echo $r['idruta']; ?>" <?php if ($selectedRuta === (int)$r['idruta']) echo 'selected'; ?>><?php echo htmlspecialchars($r['nombre']); ?></option>
    <?php endforeach; ?>
</select>

<input type="hidden" id="idparada" name="idparada" value="">

<div id="boxEncargado" style="display:none;">
    <div class="field-label">Encargado de ruta:</div>
    <div id="lblEncargado" style="font-weight:bold;"></div>
</div>

<div id="boxParada" style="display:none;">
    <div class="field-label">Parada actual:</div>
    <div id="lblParada" style="font-weight:bold;"></div>
</div>

<div id="boxAcciones" style="display:none;">
    <label for="accion">Acción:</label>
    <select id="accion" name="idaccion"></select>
</div>

<label for="total_personas">Cantidad de personas:</label>
<input type="number" id="total_personas" name="total_personas" min="0" required>

<label for="comentario">Comentario (opcional):</label>
<input type="text" id="comentario" name="comentario">
</div>
<div class="card-footer">
<button type="submit" class="btn guardar">Guardar</button>
<button type="button" class="btn cancelar" onclick="window.location.href='../index.php'">Cancelar</button>
<button type="button" class="btn" id="btnListaReportes" style="display:none;">Ver lista de reportes</button>
</div>
</form>

                </div>
              </div>
            </div>
          </div>
        </div>
</main>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
$(function () {

    /* === ENVIAR FORMULARIO POR AJAX === */
    $("#formReporte").on("submit", function(e) {
        e.preventDefault();

        let $btn = $(".guardar");
        $btn.prop("disabled", true).text("Guardando...");

        $.ajax({
            url: "guardar.php",
            method: "POST",
            data: $(this).serialize(),
            dataType: "json",
            success: function(resp) {
                $btn.prop("disabled", false).text("Guardar");

                if (resp.success) {
                    alert(resp.message || "Reporte guardado.");
                    if (resp.redirect) window.location = resp.redirect;
                } else {
                    alert(resp.error || "Error al guardar.");
                }
            },
            error: function(xhr) {
                $btn.prop("disabled", false).text("Guardar");
                alert("Error del servidor.");
            }
        });
    });

    /* === CARGAR INFO AL CAMBIAR RUTA === */
    $("#ruta").on("change", function () {

        $("#ajaxError").hide();
        $("#boxEncargado, #boxParada, #boxAcciones").hide();
        $("#idparada").val("");

        var idruta = $(this).val();
        if (!idruta) {
            // If no route selected, hide the button and clear its href
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
                    data.acciones.forEach(a => {
                        $("#accion").append(`<option value="${a.idaccion}">${a.nombre}</option>`);
                    });
                    $("#boxAcciones").fadeIn();
                }
                // Update the 'Ver lista de reportes' button href for the selected route
                var listHref = 'lista_reportes.php?idruta=' + encodeURIComponent(idruta);
                $("#btnListaReportes").attr('data-href', listHref).fadeIn();
            },
            error: function () {
                $("#ajaxError").text("Error al obtener datos de la ruta.").show();
            }
        });
    });

    /* === BOTÓN: ir a lista_reportes con idruta seleccionado === */
    $("#btnListaReportes").on("click", function () {
        var href = $(this).attr('data-href') || $(this).data('href');
        if (!href) {
            alert('Seleccione una ruta primero.');
            return;
        }
        window.location.href = href;
    });

    // If server provided a selected route, trigger change to load its data
    var preselected = <?php echo json_encode($selectedRuta); ?>;
    if (preselected && preselected > 0) {
        $("#ruta").trigger('change');
    }

});
</script>


<?php include "../templates/footer.php"; ?>
