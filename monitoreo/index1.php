<?php
ini_set('display_errors', 0);
error_reporting(0);
require_once "../includes/db.php";

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

/* Obtener agentes */
$sql_agentes = "SELECT idagente, nombre FROM agente ORDER BY nombre";
try {
    $stmt = $pdo->query($sql_agentes);
    $agentes = $stmt->fetchAll();
} catch (Exception $e) {
    $agentes = [];
}

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
include "../templates/sidebar.php";
?>

<h2>Nuevo Reporte</h2>

<div id="ajaxError" style="color: #b00020; display:none; margin-bottom:12px;"></div>

<form id="formReporte">
    <label for="agente">Agente:</label>
    <select id="agente" name="idagente">
        <option value="">Seleccione un agente</option>
        <?php foreach ($agentes as $a) : ?>
            <option value="<?= htmlspecialchars($a['idagente']) ?>">
                <?= htmlspecialchars($a['nombre']) ?>
            </option>
        <?php endforeach; ?>
    </select>

    <label for="ruta">Ruta:</label>
    <select id="ruta" name="idruta">
        <option value="">Seleccione una ruta</option>
        <?php foreach ($rutas as $r): ?>
            <option value="<?= htmlspecialchars($r['idruta']) ?>"
                <?= ($selectedRuta == $r['idruta']) ? 'selected' : '' ?>>
                <?= htmlspecialchars($r['nombre']) ?>
            </option>
        <?php endforeach; ?>
    </select>

    <!-- Hidden field for parada -->
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

    <button type="submit" class="btn guardar">Guardar</button>
    <button type="button" class="btn cancelar" onclick="window.location.href='../index.php'">Cancelar</button>
    <button type="button" class="btn" id="btnListaReportes" style="display:none;">Ver lista de reportes</button>

</form>

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
                    alert("Registro guardado correctamente.");
                    $("#formReporte")[0].reset();
                    $("#boxEncargado, #boxParada, #boxAcciones").hide();
                    $("#total_personas").val("");
                } else {
                    $("#ajaxError").text(resp.message || "Error al guardar.").show();
                }
            },
            error: function() {
                $btn.prop("disabled", false).text("Guardar");
                $("#ajaxError").text("Error de comunicación con el servidor.").show();
            }
        });
    });

    /* === CAMBIO DE RUTA: OBTENER INFO DE RUTA, ENCARGADO Y PARADA === */
    $("#ruta").on("change", function () {

        // Clear previous info
        $("#boxEncargado, #boxParada").hide();
        $("#lblEncargado, #lblParada").text("");
        $("#accion").empty();
        $("#boxAcciones").hide();
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
                    data.acciones.forEach(function (a) {
                        $("#accion").append(
                            $("<option>", {
                                value: a.idaccion,
                                text: a.nombre
                            })
                        );
                    });
                    $("#boxAcciones").fadeIn();
                }

                // Update the "Ver lista de reportes" button
                var href = "lista_reportes.php?idruta=" + encodeURIComponent(idruta);
                $("#btnListaReportes")
                    .show()
                    .attr('data-href', href);
            },
            error: function () {
                alert("No se pudo obtener la información de la ruta.");
            }
        });
    });

    // "Ver lista de reportes" button click
    $("#btnListaReportes").on("click", function () {
        var href = $(this).data('href');
        if (!href) {
            alert("Seleccione una ruta primero.");
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