<?php
// reporte/index.php — Login por ruta + clave

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

// Si ya está logueado, mandarlo directo al form de reporte
if (!empty($_SESSION['idruta'])) {
    header('Location: reporte.php');
    exit;
}

// =============================
// Cargar rutas para el <select>
// =============================
$rutas = [];
try {
    $sql = "SELECT idruta, nombre FROM ruta ORDER BY nombre";
    $rutas = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    // si falla, dejamos el array vacío
}

// =============================
// Procesar login
// =============================
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $idruta = isset($_POST['idruta']) ? (int)$_POST['idruta'] : 0;
    $clave  = trim($_POST['clave'] ?? '');

    if ($idruta <= 0) {
        $errors[] = 'Debe seleccionar una ruta.';
    }
    if ($clave === '') {
        $errors[] = 'Debe ingresar la clave.';
    }

    if (!$errors) {
        // Traer info de la ruta:
        // - clave_hash (si existe)
        // - teléfono del encargado (para primer login)
        $sql = "
            SELECT 
                r.idruta,
                r.nombre,
                rc.clave_hash,
                er.telefono
            FROM ruta r
            LEFT JOIN ruta_clave rc 
                   ON rc.idruta = r.idruta
            LEFT JOIN encargado_ruta er
                   ON er.idencargado_ruta = r.idencargado_ruta
            WHERE r.idruta = :idruta
            LIMIT 1
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':idruta' => $idruta]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            $errors[] = 'Ruta no encontrada.';
        } else {
            $loginOk = false;

            // Caso 1: ya tiene clave configurada en ruta_clave
            if (!empty($row['clave_hash'])) {
                if (password_verify($clave, $row['clave_hash'])) {
                    $loginOk = true;
                }
            } else {
                // Caso 2: primera vez, usar teléfono del encargado
                $telefono = preg_replace('/\D+/', '', (string)($row['telefono'] ?? ''));
                $claveNormalizada = preg_replace('/\D+/', '', $clave);

                if ($telefono !== '' && $telefono === $claveNormalizada) {
                    $loginOk = true;

                    // Sembrar registro en ruta_clave con la clave ingresada
                    $hash = password_hash($clave, PASSWORD_DEFAULT);
                    $ins = $pdo->prepare("
                        INSERT INTO ruta_clave (idruta, clave_hash)
                        VALUES (:idruta, :hash)
                        ON DUPLICATE KEY UPDATE
                            clave_hash = VALUES(clave_hash),
                            ultima_actualizacion = CURRENT_TIMESTAMP
                    ");
                    $ins->execute([
                        ':idruta' => $idruta,
                        ':hash'   => $hash
                    ]);
                }
            }

            if ($loginOk) {
                // Guardar datos de sesión
                $_SESSION['idruta']      = $row['idruta'];
                $_SESSION['ruta_nombre'] = $row['nombre'];

                // Más adelante aquí podemos guardar también id del líder, etc.
                header('Location: reporte.php');
                exit;
            } else {
                $errors[] = 'Clave incorrecta para la ruta seleccionada.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Ingreso de Reportes - Transporte</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- Bootstrap básico para que se vea bien en móvil -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">

    <style>
        body {
            background: #f4f6f9;
        }
        .login-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .login-card {
            max-width: 420px;
            width: 100%;
            box-shadow: 0 0 12px rgba(0,0,0,0.08);
            border-radius: 8px;
            background: #ffffff;
        }
        .login-card .card-header {
            background: #0b7cc2;
            color: #fff;
            border-bottom: none;
            border-radius: 8px 8px 0 0;
        }
    </style>
</head>
<body>

<div class="login-container">
  <div class="card login-card">
    <div class="card-header text-center">
      <h5 class="mb-0">Ingreso para reporte de ruta</h5>
      <small>Seleccione su ruta e ingrese la clave del líder</small>
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
          <div class="form-group" style="display:none;">
            <label for="ruta">Ruta2</label>
            <select id="ruta" name="idruta" class="form-control" required >
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
            <input type="number" min="0" max="100" id="total_personas" name="total_personas"
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
           
            <button type="submit" class="btn btn-primary">
              Guardar
            </button>
              </div>
             
        </div>

      </form>
<div class="card-footer d-flex justify-content-around">
    <button type="button" id="btnListaReportes" class="btn btn-success">
        Mis reportes
    </button>

  
</div>
    </div>
    <div class="card-body">

      <?php if ($errors): ?>
        <div class="alert alert-danger">
          <?php foreach ($errors as $e): ?>
            <div><?= htmlspecialchars($e) ?></div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <form method="post" autocomplete="off">
        <div class="form-group">
          <label for="idruta">Ruta</label>
          <select name="idruta" id="idruta" class="form-control" required>
            <option value="">Seleccione una ruta</option>
            <?php foreach ($rutas as $r): ?>
              <option value="<?= (int)$r['idruta'] ?>"
                <?= (isset($idruta) && $idruta == (int)$r['idruta']) ? 'selected' : '' ?>>
                <?= htmlspecialchars($r['nombre']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group">
          <label for="clave">Clave (teléfono del líder)</label>
          <input type="password" name="clave" id="clave"
                 class="form-control" required>
        </div>

        <button type="submit" class="btn btn-primary btn-block">
          Ingresar
        </button>
      </form>
    </div>
  </div>
</div>

</div> <!-- /.report-wrapper -->

<!-- jQuery para el AJAX del form -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

<script>
$(function () {

    $("#formReporte").on("submit", function(e) {
        e.preventDefault();

           // VALIDAR ACCIÓN
    let accion = $("#accion").val();
    if (!accion || accion === "") {
        alert("Debe seleccionar una acción antes de guardar.");
        $("#accion").focus();
        return; // Detiene el envío
    }
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
                     $("#accion").append(
                            '<option value="">Seleccione una acción</option>'
                        );
                    data.acciones.forEach(function(a){
                        $("#accion").append(
                            '<option value="' + a.idaccion + '">' + a.nombre + '</option>'
                        );
                    });
                    $("#boxAcciones").fadeIn();
                }
            },

            error: function () {
                $("#ajaxError").text("Error al obtener datos de la ruta.").show();
            }
        });
    });
    $("#btnListaReportes").on("click", function () {
          var idruta = $("#ruta").val();
    if (idruta) {
        window.location = 'lista_reportes.php' ;
    }
    });

    var preselected = <?= json_encode($selectedRuta) ?>;
    if (preselected > 0) {
        $("#ruta").val(preselected).trigger('change');
    }
});
/*
document.addEventListener('DOMContentLoaded', function () {
  const btn = document.getElementById("btnUbicacion");
  if (!btn) return;
   var idruta = $("#ruta").val();
    if (!idruta) {
      alert("No se ha seleccionado ninguna ruta.");
      return;
    }

  btn.addEventListener("click", () => {
    if (!navigator.geolocation) {
      alert("La geolocalización no es soportada por este navegador.");
      return;
    } */

   /*  navigator.geolocation.getCurrentPosition(
       successCallback,
  errorCallback,
  {
    enableHighAccuracy: true,   // 🔥 Fuerza a usar GPS real
    timeout: 10000,            // Máx. 10 seg esperando señal GPS
    maximumAge: 0              // No uses coordenadas en caché
  }
      
        (pos) => {
        const lat = pos.coords.latitude;
        const lng = pos.coords.longitude;
       const idruta = ;

        fetch("guardar_ubicacion.php", {
          method: "POST",
          headers: { "Content-Type": "application/x-www-form-urlencoded" },
          body: `lat=${encodeURIComponent(lat)}&lng=${encodeURIComponent(lng)}&idruta=${encodeURIComponent(idruta)}`
        })
        .then(r => r.json())
        .then(res => {
          if (res.success) {
            alert("Ubicación guardada correctamente.");
          } else {
            alert("Error al guardar ubicación: " + (res.msg || ''));
          }
        })
        .catch(() => alert("Error de comunicación con el servidor."));
      },
      (error) => {
        alert("No se pudo obtener la ubicación: " + error.message);
      }
    ); */
/*     const watchID = navigator.geolocation.watchPosition(
  (pos) => {
    const lat = pos.coords.latitude;
    const lng = pos.coords.longitude;
    const accuracy = pos.coords.accuracy; // en metros

    console.log("Lat:", lat, "Lng:", lng, "Precisión:", accuracy);

    const idruta = ;

    fetch("guardar_ubicacion.php", {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: `lat=${lat}&lng=${lng}&idruta=${idruta}`
    });
  },

  (error) => {
    console.log("Error GPS:", error);
  },

  {
    enableHighAccuracy: true,   // 🔥 OBLIGA GPS real
    timeout: 15000,             // Tiempo suficiente para conectar satélites
    maximumAge: 0               // Siempre nueva lectura
  }
); 

  });


});*/
// 10 minutos en milisegundos
//const INTERVALO_REPORTE = 10 * 60 * 1000;
const INTERVALO_REPORTE = 1 * 60 * 1000;

let ultimaUbicacion = null;

// 1️⃣ Seguimiento continuo (mejor precisión)
navigator.geolocation.watchPosition(
  (pos) => {
    ultimaUbicacion = {
      lat: pos.coords.latitude,
      lng: pos.coords.longitude,
      accuracy: pos.coords.accuracy
    };

    console.log("GPS:", ultimaUbicacion);
  },

  (err) => console.log("Error GPS:", err),

  {
    enableHighAccuracy: true,
    timeout: 15000,
    maximumAge: 0
  }
);

// 2️⃣ Envío al servidor cada 10 minutos
setInterval(() => {
  if (!ultimaUbicacion) return;  // Aún no tenemos coordenadas

  const { lat, lng, accuracy } = ultimaUbicacion;
  const idruta = <?= json_encode($selectedRuta) ?>;

  console.log("📡 Enviando reporte…", lat, lng, accuracy);

  fetch("guardar_ubicacion.php", {
    method: "POST",
    headers: { "Content-Type": "application/x-www-form-urlencoded" },
    body: `lat=${lat}&lng=${lng}&idruta=${idruta}`
  })
  .then(r => r.json())
  .then(res => console.log("Servidor:", res))
  .catch(err => console.error("Error enviando:", err));

}, INTERVALO_REPORTE);

</script>

<?php
// Footer global del sistema (JS de AdminLTE, etc.)
require "../templates/footer.php";
?>

</body>
</html>
