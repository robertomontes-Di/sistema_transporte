<?php
ini_set('display_errors', 0);
error_reporting(0);

require_once "../includes/db.php";
require_once "../includes/config.php";

/* Obtener rutas */
try {
    $stmt = $pdo->query("SELECT idruta, nombre FROM ruta ORDER BY nombre");
    $rutas = $stmt->fetchAll();
} catch (Exception $e) {
    $rutas = [];
}

/* Obtener agentes */
try {
    $stmt = $pdo->query("SELECT idagente, nombre FROM agente ORDER BY nombre");
    $agentes = $stmt->fetchAll();
} catch (Exception $e) {
    $agentes = [];
}

// Preseleccionados por GET
$selectedRuta    = isset($_GET['idruta'])   ? (int)$_GET['idruta']   : 0;
$selectedAgente  = isset($_GET['idagente']) ? (int)$_GET['idagente'] : 0;

/* Obtener acciones (por si las quieres usar luego) */
try {
    $stmt2 = $pdo->query("SELECT idaccion, nombre FROM acciones ORDER BY nombre");
    $acciones = $stmt2->fetchAll();
} catch (Exception $e) {
    $acciones = [];
}

$pageTitle   = "Monitoreo - Crear Reporte";
$currentPage = "monitoreo";

require "../templates/header.php";
?>

<!-- =======================
     Encabezado de contenido
======================== -->
<section class="content-header">
  <div class="container-fluid">
    <div class="row mb-2">
      <div class="col-sm-8">
        <h1>Crear Registro de Monitoreo</h1>
        <p class="text-muted mb-0">
          Registro rápido para que el agente reporte el estado de la ruta en tiempo real.
        </p>
      </div>
    </div>
  </div>
</section>

<!-- =======================
     Contenido principal
======================== -->
<section class="content">
  <div class="container-fluid">

    <div class="row justify-content-center">
      <div class="col-md-6">

        <div class="card card-info">
          <div class="card-header">
            <h3 class="card-title">Nuevo reporte de agente</h3>
          </div>

          <form id="formReporte">
            <div class="card-body">

              <div id="ajaxError" class="text-danger mb-2" style="display:none;"></div>

              <!-- Agente -->
              <div class="form-group">
                <label for="agente">Agente:</label>
                <select id="agente" name="idagente" class="form-control" required>
                  <option value="">Seleccione un agente</option>
                  <?php foreach ($agentes as $a): ?>
                    <option value="<?= $a['idagente'] ?>"
                      <?= $selectedAgente === (int)$a['idagente'] ? 'selected' : '' ?>>
                      <?= htmlspecialchars($a['nombre']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <!-- Ruta -->
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
                <input type="number" id="total_personas" name="total_personas"
                       class="form-control" min="0" required>
              </div>

              <!-- Comentario -->
              <div class="form-group">
                <label for="comentario">Comentario (opcional):</label>
                <input type="text" id="comentario" name="comentario" class="form-control">
              </div>

            </div><!-- /.card-body -->

            <div class="card-footer">
              <button type="submit" class="btn btn-primary guardar">Guardar</button>
              <a href="../index.php" class="btn btn-secondary">Cancelar</a>
              <button type="button" class="btn btn-info" id="btnListaReportes" style="display:none;">
                Ver lista de reportes
              </button>
            </div>
          </form>

        </div><!-- /.card -->

      </div>
    </div>

  </div><!-- /.container-fluid -->
</section>

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
            error: function() {
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

        var idruta   = $(this).val();
        var idagente = $('#agente').val();

        if (!idruta) {
            $("#btnListaReportes").hide().removeAttr('data-href');
            return;
        }

        if (!idagente) {
            alert('Seleccione un agente primero.');
            $(this).val('');
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

                var listHref = 'lista_reportes.php?idruta=' +
                               encodeURIComponent(idruta) +
                               '&agente=' + encodeURIComponent(idagente);

                $("#btnListaReportes").attr('data-href', listHref).fadeIn();
            },
            error: function () {
                $("#ajaxError").text("Error al obtener datos de la ruta.").show();
            }
        });
    });

    /* === BOTÓN: ir a lista_reportes con idruta seleccionado === */
    $("#btnListaReportes").on("click", function () {
        var href = $(this).attr('data-href');
        if (!href) {
            alert('Seleccione una ruta primero.');
            return;
        }
        window.location.href = href;
    });

    // Preseleccionar agente/ruta si vienen por GET
    var preRuta    = <?= json_encode($selectedRuta) ?>;
    var preAgente  = <?= json_encode($selectedAgente) ?>;

    if (preAgente > 0) {
        $("#agente").val(preAgente);
    }
    if (preRuta > 0) {
        $("#ruta").val(preRuta).trigger('change');
    }

});
</script>

<?php
require "../templates/footer.php";
