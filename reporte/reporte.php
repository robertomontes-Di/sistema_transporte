<?php
// reporte/reporte.php — Flujo principal de reportes para líder de ruta

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

// ===============================
// 1) Validar sesión de la ruta
// ===============================
if (empty($_SESSION['idruta'])) {
    header('Location: index.php');
    exit;
}

$idruta     = (int)$_SESSION['idruta'];
$rutaNombre = $_SESSION['ruta_nombre'] ?? '';

// ===============================
// 1.1) Parada por defecto de la ruta
// ===============================
$idParadaDefault = 0; // valor seguro por defecto

try {
    $stmt = $pdo->prepare("
        SELECT idparada
        FROM paradas
        WHERE idruta = :idruta
        ORDER BY orden ASC, idparada ASC
        LIMIT 1
    ");
    $stmt->execute([':idruta' => $idruta]);
    $tmp = $stmt->fetchColumn();
    if ($tmp) {
        $idParadaDefault = (int)$tmp;
    }
} catch (Throwable $e) {
    // Si falla la consulta, nos quedamos con 0
}

// ===============================
// 2) Info básica de la ruta (UI)
// ===============================
$rutaInfo = null;
try {
    $stmt = $pdo->prepare("
        SELECT r.idruta, r.nombre, r.destino,
               er.nombre AS encargado, er.telefono
        FROM ruta r
        LEFT JOIN encargado_ruta er ON er.idencargado_ruta = r.idencargado_ruta
        WHERE r.idruta = :idruta
        LIMIT 1
    ");
    $stmt->execute([':idruta' => $idruta]);
    $rutaInfo = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $rutaInfo = null;
}

// ===============================
// 3) Ver si el bus ya está configurado
// ===============================
$configBus = null;
try {
    $stmt = $pdo->prepare("
        SELECT idruta, nombre_motorista, telefono_motorista,
               capacidad_aprox, placa
        FROM ruta_config_bus
        WHERE idruta = :idruta
        LIMIT 1
    ");
    $stmt->execute([':idruta' => $idruta]);
    $configBus = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $configBus = null;
}

// ===============================
// 4) Buscar idaccion de “Salida hacia el estadio”
// ===============================
$idAccionSalida = null;
try {
    $stmt = $pdo->prepare("
        SELECT idaccion
        FROM acciones
        WHERE nombre = 'Salida hacia el estadio'
        LIMIT 1
    ");
    $stmt->execute();
    $idAccionSalida = $stmt->fetchColumn();
} catch (Throwable $e) {
    $idAccionSalida = null;
}

// ===============================
// 5) Revisar si YA existe un primer reporte hoy
// ===============================
$tienePrimerReporte = false;
if ($idAccionSalida) {
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM reporte 
            WHERE idruta = :idruta 
              AND idaccion = :idaccion
              AND DATE(fecha_reporte) = CURRENT_DATE
        ");
        $stmt->execute([
            ':idruta'   => $idruta,
            ':idaccion' => $idAccionSalida
        ]);
        $tienePrimerReporte = ((int)$stmt->fetchColumn() > 0);
    } catch (Throwable $e) {
        $tienePrimerReporte = false;
    }
}

// ===================================================
// 6) Acciones: traer y agrupar por tipo_accion
// ===================================================
$acciones = [];
try {
    $stmt = $pdo->query("
        SELECT idaccion, nombre, tipo_accion
        FROM acciones
        ORDER BY tipo_accion, nombre
    ");
    $acciones = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $acciones = [];
}

// Agrupar por tipo_accion
$accionesPorTipo = [];
foreach ($acciones as $a) {
    $tipo = $a['tipo_accion'] ?: 'otro';
    if (!isset($accionesPorTipo[$tipo])) {
        $accionesPorTipo[$tipo] = [];
    }
    $accionesPorTipo[$tipo][] = $a;
}

// Etiquetas amigables
function etiquetaTipoAccion(string $tipo): string {
    switch (strtolower($tipo)) {
        case 'ruta':       return 'Reporte de Ruta';
        case 'incidencia': return 'Reporte de Incidencia';
        case 'emergencia': return 'Reporte de Emergencia';
        case 'asistencia': return 'Necesito Asistencia';
        case 'vehiculo':   return 'Reporte del estado del vehículo';
        case 'otro':       return 'Otro';
        default:
            return ucfirst($tipo);
    }
}

// ===================================================
// 7) Reglas de negocio: acciones que requieren personas
// ===================================================
$accionesRequierenPersonas = [
    'salida hacia el estadio',
    'salida de parada',
    'salida hacia el mágico gonzález',   // 👈 nueva
];


function accionRequierePersonas(?string $nombreAccion, array $lista): bool {
    if (!$nombreAccion) return false;
    $nombre = mb_strtolower($nombreAccion, 'UTF-8');
    foreach ($lista as $needle) {
        if (mb_strpos($nombre, $needle, 0, 'UTF-8') !== false) {
            return true;
        }
    }
    return false;
}

// ===============================
// 8) Manejo de formularios (POST)
// ===============================
$errors  = [];
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formStep = $_POST['form_step'] ?? '';

    // ---------- FORM 1: Configuración del bus ----------
    if ($formStep === 'config_bus') {
        $nombreMotorista = trim($_POST['nombre_motorista'] ?? '');
        $telMotorista    = trim($_POST['telefono_motorista'] ?? '');
        $capacidad       = (isset($_POST['capacidad_aprox']) && $_POST['capacidad_aprox'] !== '')
                            ? (int)$_POST['capacidad_aprox'] : null;
        $placa           = trim($_POST['placa'] ?? '');

        if ($nombreMotorista === '') {
            $errors[] = 'Debe ingresar el nombre del motorista.';
        }
        if ($telMotorista === '') {
            $errors[] = 'Debe ingresar el teléfono del motorista.';
        }

        if (!$errors) {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO ruta_config_bus
                        (idruta, nombre_motorista, telefono_motorista, capacidad_aprox, placa)
                    VALUES
                        (:idruta, :nombre, :tel, :cap, :placa)
                    ON DUPLICATE KEY UPDATE
                        nombre_motorista   = VALUES(nombre_motorista),
                        telefono_motorista = VALUES(telefono_motorista),
                        capacidad_aprox    = VALUES(capacidad_aprox),
                        placa              = VALUES(placa),
                        fecha_actualizado  = CURRENT_TIMESTAMP
                ");
                $stmt->execute([
                    ':idruta' => $idruta,
                    ':nombre' => $nombreMotorista,
                    ':tel'    => $telMotorista,
                    ':cap'    => $capacidad,
                    ':placa'  => $placa
                ]);

                $success = 'Datos del autobús guardados correctamente.';

                $configBus = [
                    'idruta'             => $idruta,
                    'nombre_motorista'   => $nombreMotorista,
                    'telefono_motorista' => $telMotorista,
                    'capacidad_aprox'    => $capacidad,
                    'placa'              => $placa,
                ];
            } catch (Throwable $e) {
                $errors[] = 'Error al guardar el reporte: ' . $e->getMessage();
            }
        }
    }

    // ---------- FORM 2: Primer reporte “Salida hacia el estadio” ----------
    if ($formStep === 'primer_reporte' && $idAccionSalida) {

        if ($tienePrimerReporte) {
            $errors[] = "El primer reporte ('Salida hacia el estadio') ya fue enviado hoy.";
        }

        $totalPersonas = isset($_POST['total_personas']) ? (int)$_POST['total_personas'] : 0;
        $comentario    = trim($_POST['comentario'] ?? '');

        if ($totalPersonas <= 0) {
            $errors[] = 'Debe indicar la cantidad de personas a bordo.';
        }

        if (!$errors) {
            try {
                // Usaremos idparada = 0 para los reportes del líder de ruta
                $idparada = 0;

                $stmt = $pdo->prepare("
                    INSERT INTO reporte
                        (idruta, idagente, idparada, idaccion,
                        total_personas, total_becarios, total_menores12,
                        comentario, fecha_reporte)
                    VALUES
                        (:idruta, NULL, :idparada, :idaccion,
                        :total, 0, 0,
                        :comentario, NOW())
                ");
                $stmt->execute([
                    ':idruta'     => $idruta,
                    ':idparada'   => $idparada,
                    ':idaccion'   => $idAccionSalida,
                    ':total'      => $totalPersonas,
                    ':comentario' => $comentario
                ]);

                $success            = 'Primer reporte registrado correctamente (Salida hacia el estadio).';
                $tienePrimerReporte = true;
            } catch (Throwable $e) {
                $errors[] = 'Error al guardar el reporte: ' . $e->getMessage();
            }
        }
    }

  // ---------- FORM 3: Nuevo reporte general ----------
  if ($formStep === 'nuevo_reporte') {
      $idaccion   = isset($_POST['idaccion']) ? (int)$_POST['idaccion'] : 0;
      $rawTotal   = trim($_POST['total_personas'] ?? '');
      $totalPersonas = ($rawTotal !== '') ? max(0, (int)$rawTotal) : 0;
      $comentario = trim($_POST['comentario'] ?? '');

      if ($idaccion <= 0) {
          $errors[] = 'Debe seleccionar el tipo de reporte.';
      }

      $nombreAccion = null;
      if ($idaccion > 0) {
          foreach ($acciones as $a) {
              if ((int)$a['idaccion'] === $idaccion) {
                  $nombreAccion = $a['nombre'];
                  break;
              }
          }
      }

      // ¿Esta acción suma personas?
      $requierePersonas = accionRequierePersonas($nombreAccion, $accionesRequierenPersonas);

      // Solo obligamos número de personas para las acciones que suman
      if ($requierePersonas && $totalPersonas <= 0) {
          $errors[] = 'Debe indicar la cantidad de personas para esta acción.';
      }

      // Si NO suma personas (incidentes, llegadas, etc.), forzamos a 0
      if (!$requierePersonas) {
          $totalPersonas = 0;
      }

      if (!$errors) {
          try {
              // Para este flujo usamos idparada = 0 por ahora
              $idparada = 0;

              $stmt = $pdo->prepare("
                  INSERT INTO reporte
                      (idruta, idagente, idparada, idaccion,
                      total_personas, total_becarios, total_menores12,
                      comentario, fecha_reporte)
                  VALUES
                      (:idruta, NULL, :idparada, :idaccion,
                      :total, 0, 0,
                      :comentario, NOW())
              ");
              $stmt->execute([
                  ':idruta'     => $idruta,
                  ':idparada'   => $idparada,
                  ':idaccion'   => $idaccion,
                  ':total'      => $totalPersonas, // aquí llegan solo incrementos
                  ':comentario' => $comentario
              ]);

              $success = 'Reporte registrado correctamente.';
          } catch (Throwable $e) {
              $errors[] = 'Error al guardar el reporte: ' . $e->getMessage();
          }
      }
  }
}

// ===============================
// 9) Determinar paso actual
// ===============================
if (!$configBus) {
    $step = 'config_bus';
} elseif (!$tienePrimerReporte && $idAccionSalida) {
    $step = 'primer_reporte';
} else {
    $step = 'nuevo_reporte';
}

$accionesRequierenPersonasJs = json_encode($accionesRequierenPersonas);
?>
<!-- A partir de aquí deja igual tu HTML y JS del formulario -->
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Reporte de Ruta</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- Bootstrap 4 simple (sin integrity para evitar bloqueos) -->
    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">

    <style>
        body {
            background: #f4f6f9;
        }
        .reporte-container {
            min-height: 100vh;
            display: flex;
            align-items: flex-start;
            justify-content: center;
            padding-top: 30px;
            padding-bottom: 30px;
        }
        .reporte-card {
            width: 100%;
            max-width: 650px;
            box-shadow: 0 0 12px rgba(0,0,0,0.08);
            border-radius: 8px;
            background: #fff;
        }
        .reporte-card .card-header {
            background: #0b7cc2;
            color: #fff;
            border-radius: 8px 8px 0 0;
        }
        .btn-ubicacion {
            background-color: #28a745;
            border-color: #28a745;
        }
    </style>
</head>
<body>

<div class="reporte-container">
  <div class="card reporte-card">
    <div class="card-header">
      <div class="d-flex justify-content-between align-items-center">
        <div>
          <h5 class="mb-0">Ruta: <?= htmlspecialchars($rutaNombre ?: ($rutaInfo['nombre'] ?? '')) ?></h5>
          <?php if ($rutaInfo): ?>
            <small>
              Destino: <?= htmlspecialchars($rutaInfo['destino'] ?? '-') ?>
              <?php if (!empty($rutaInfo['encargado'])): ?>
                <br>Líder: <?= htmlspecialchars($rutaInfo['encargado']) ?>
              <?php endif; ?>
            </small>
          <?php endif; ?>
        </div>
        <div>
          <a href="logout.php" class="btn btn-sm btn-outline-light">Salir</a>
        </div>
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

      <?php if ($success): ?>
        <div class="alert alert-success">
          <?= htmlspecialchars($success) ?>
        </div>
      <?php endif; ?>

      <!-- Paso 1: Configuración del autobús -->
      <?php if ($step === 'config_bus'): ?>
        <h6 class="mb-3">Validar datos del autobús</h6>
        <p class="text-muted">
          Completa esta información una sola vez antes de enviar reportes.
        </p>

        <form method="post" autocomplete="off">
          <input type="hidden" name="form_step" value="config_bus">

          <div class="form-group">
            <label for="nombre_motorista">Nombre del motorista</label>
            <input type="text"
                   name="nombre_motorista"
                   id="nombre_motorista"
                   class="form-control"
                   required>
          </div>

          <div class="form-group">
            <label for="telefono_motorista">Teléfono del conductor</label>
            <input type="number"
                   name="telefono_motorista"
                   id="telefono_motorista"
                   class="form-control"
                   min="50000000"
                   max="89999999"
                   required>
          </div>

          <div class="form-group">
            <label for="capacidad_aprox">Capacidad aproximada del autobús</label>
            <input type="number"
                   name="capacidad_aprox"
                   id="capacidad_aprox"
                   class="form-control"
                   min="0"
                   max="100"
                   placeholder="Ejemplo. 100">
          </div>

          <div class="form-group">
            <label for="placa">Placa</label>
            <input type="text"
                   name="placa"
                   id="placa"
                   class="form-control">
          </div>

          <button type="submit" class="btn btn-primary btn-block">
            Guardar datos del autobús
          </button>
        </form>

      <!-- Paso 2: Primer reporte (Salida hacia el estadio) -->
      <?php elseif ($step === 'primer_reporte'): ?>
        <h6 class="mb-3">Primer reporte: Salida hacia el estadio</h6>
        <p class="text-muted">
          Registra la cantidad total de personas a bordo al momento de salir hacia el estadio.
        </p>

        <form method="post" autocomplete="off">
          <input type="hidden" name="form_step" value="primer_reporte">

          <div class="form-group">
            <label for="total_personas">Cantidad de personas a bordo</label>
            <input type="number"
                   name="total_personas"
                   id="total_personas"
                   class="form-control"
                   required
                   min="1">
          </div>

          <div class="form-group">
            <label for="comentario">Comentario (opcional)</label>
            <input type="text"
                   name="comentario"
                   id="comentario"
                   class="form-control">
          </div>

          <button type="submit" class="btn btn-primary btn-block">
            Guardar primer reporte
          </button>
        </form>

      <!-- Paso 3: Nuevo reporte + botón Enviar ubicación -->
      <?php else: ?>
        <h6 class="mb-3">Nuevo reporte</h6>
        <p class="text-muted">
          Envíe reportes de ruta, incidencias o emergencias.  
          Use el botón verde para mandar solo la ubicación del bus.
        </p>
        <?php
      // Clasificar acciones por tipo_accion (normal / critico / inconveniente)
      $accionesNormal        = [];
      $accionesCritico       = [];
      $accionesInconveniente = [];

      foreach ($acciones as $a) {
          $tipo = strtolower($a['tipo_accion'] ?? '');
          if ($tipo === 'normal') {
              $accionesNormal[] = $a;
          } elseif ($tipo === 'critico') {
              $accionesCritico[] = $a;
          } elseif ($tipo === 'inconveniente') {
              $accionesInconveniente[] = $a;
          }
      }
      ?>
        <form method="post" autocomplete="off" id="formNuevoReporte">
  <input type="hidden" name="form_step" value="nuevo_reporte">

  <!-- Campo REAL que verá el backend (idaccion numérico) -->
  <input type="hidden" name="idaccion" id="idaccion_real" value="">

  <!-- 1) Tipo de reporte: Normal / Incidente -->
  <div class="form-group">
    <label for="tipo_reporte">Tipo de reporte</label>
    <select name="tipo_reporte" id="tipo_reporte" class="form-control" required>
      <option value="">Seleccione…</option>
      <option value="normal">Normal</option>
      <option value="incidente">Incidente</option>
    </select>
  </div>

  <!-- 2) Detalle cuando es NORMAL -->
  <div class="form-group" id="grupo_normal" style="display:none;">
    <label for="idaccion_normal">Detalle del reporte (normal)</label>
    <select id="idaccion_normal" class="form-control">
      <option value="">Seleccione…</option>
      <?php foreach ($accionesNormal as $a): ?>
        <?php
          $requiere = accionRequierePersonas($a['nombre'], $accionesRequierenPersonas) ? '1' : '0';
        ?>
        <option value="<?= (int)$a['idaccion'] ?>"
                data-requiere-personas="<?= $requiere ?>">
          <?= htmlspecialchars($a['nombre']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>

  <!-- 3) Detalle cuando es INCIDENTE -->
  <div class="form-group" id="grupo_incidente" style="display:none;">
    <label for="idaccion_incidente">Detalle del incidente</label>
    <select id="idaccion_incidente" class="form-control">
      <option value="">Seleccione…</option>

      <!-- Crítico -->
      <?php if (!empty($accionesCritico)): ?>
        <optgroup label="Crítico">
          <?php foreach ($accionesCritico as $a): ?>
            <?php
              $requiere = accionRequierePersonas($a['nombre'], $accionesRequierenPersonas) ? '1' : '0';
            ?>
            <option value="<?= (int)$a['idaccion'] ?>"
                    data-requiere-personas="<?= $requiere ?>">
              <?= htmlspecialchars($a['nombre']) ?>
            </option>
          <?php endforeach; ?>
        </optgroup>
      <?php endif; ?>

      <!-- Inconveniente -->
      <?php if (!empty($accionesInconveniente)): ?>
        <optgroup label="Inconveniente">
          <?php foreach ($accionesInconveniente as $a): ?>
            <?php
              $requiere = accionRequierePersonas($a['nombre'], $accionesRequierenPersonas) ? '1' : '0';
            ?>
            <option value="<?= (int)$a['idaccion'] ?>"
                    data-requiere-personas="<?= $requiere ?>">
              <?= htmlspecialchars($a['nombre']) ?>
            </option>
          <?php endforeach; ?>
        </optgroup>
      <?php endif; ?>
    </select>
  </div>

  <!-- 4) Cantidad de personas (se activa solo si aplica) -->
  <div class="form-group">
    <label for="total_personas_main">Cantidad de personas que subieron</label>
    <input type="number"
           name="total_personas"
           id="total_personas_main"
           class="form-control"
           min="0"
           disabled>
    <small class="form-text text-muted">
      Solo se habilita para eventos como “Salida hacia el estadio” o “Salida de parada”.
    </small>
  </div>

  <div class="form-group">
    <label for="comentario">Comentario (opcional)</label>
    <input type="text"
           name="comentario"
           id="comentario"
           class="form-control">
  </div>

  <button type="submit" class="btn btn-primary btn-block">
    Enviar reporte
  </button>
</form>
        <hr>

        <!-- Botón de ubicación SOLO disponible cuando ya hay primer reporte -->
        <button type="button"
                class="btn btn-ubicacion btn-block"
                id="btnUbicacion">
          Enviar ubicación
        </button>

        <small class="text-muted d-block mt-2">
          Este botón solo envía la posición GPS de la unidad
          (sin crear un nuevo evento de reporte).
        </small>

      <?php endif; ?>

    </div>
  </div>
</div>

<script>
// Reglas compartidas: nombres de acciones que requieren personas (desde PHP)
const ACCIONES_REQUIEREN_PERSONAS = <?= $accionesRequierenPersonasJs ?> || [];

// --- Lógica de selects y personas ---
(function () {
  const tipoReporte      = document.getElementById('tipo_reporte');
  const grupoNormal      = document.getElementById('grupo_normal');
  const grupoIncidente   = document.getElementById('grupo_incidente');
  const selNormal        = document.getElementById('idaccion_normal');
  const selIncidente     = document.getElementById('idaccion_incidente');
  const hiddenIdaccion   = document.getElementById('idaccion_real');
  const inputPersonas    = document.getElementById('total_personas_main');

  function resetDetalle() {
    if (selNormal)   selNormal.value = '';
    if (selIncidente) selIncidente.value = '';
    hiddenIdaccion.value   = '';
    inputPersonas.value    = '';
    inputPersonas.disabled = true;
    inputPersonas.required = false;
  }

  function aplicarDesdeSelect(select) {
    const opt = select.options[select.selectedIndex];
    if (!opt || !opt.value) {
      hiddenIdaccion.value   = '';
      inputPersonas.value    = '';
      inputPersonas.disabled = true;
      inputPersonas.required = false;
      return;
    }

    hiddenIdaccion.value = opt.value;

    const requiere = opt.dataset.requierePersonas === '1';
    if (requiere) {
      inputPersonas.disabled = false;
      inputPersonas.required = true;
    } else {
      inputPersonas.value    = '';
      inputPersonas.disabled = true;
      inputPersonas.required = false;
    }
  }

  if (tipoReporte) {
    tipoReporte.addEventListener('change', function () {
      resetDetalle();

      if (this.value === 'normal') {
        grupoNormal.style.display    = 'block';
        grupoIncidente.style.display = 'none';
      } else if (this.value === 'incidente') {
        grupoNormal.style.display    = 'none';
        grupoIncidente.style.display = 'block';
      } else {
        grupoNormal.style.display    = 'none';
        grupoIncidente.style.display = 'none';
      }
    });
  }

  if (selNormal) {
    selNormal.addEventListener('change', function () {
      aplicarDesdeSelect(this);
    });
  }

  if (selIncidente) {
    selIncidente.addEventListener('change', function () {
      aplicarDesdeSelect(this);
    });
  }

  // --- Aquí DEJAS tal cual el código que ya tienes de "Enviar ubicación" ---
  const btnUbicacion = document.getElementById('btnUbicacion');
  if (btnUbicacion) {
    btnUbicacion.addEventListener('click', function() {
      // ... tu código existente de geolocalización y fetch ...
    });
  }
})();
</script>
<script>
document.addEventListener('DOMContentLoaded', function () {
  const tipoReporte = document.getElementById('tipo_reporte');
  const grupoNormal = document.getElementById('grupo_normal');
  const grupoIncidente = document.getElementById('grupo_incidente');
  const selNormal = document.getElementById('idaccion_normal');
  const selIncidente = document.getElementById('idaccion_incidente');
  const inputPersonas = document.getElementById('total_personas');

  function actualizarInputPersonas(select) {
    const opt = select.options[select.selectedIndex];
    const requiere = opt && opt.dataset.requierePersonas === '1';
    if (requiere) {
      inputPersonas.disabled = false;
    } else {
      inputPersonas.disabled = true;
      inputPersonas.value = '';
    }
  }

  tipoReporte.addEventListener('change', function () {
    const v = this.value;

    if (v === 'normal') {
      grupoNormal.style.display = 'block';
      selNormal.disabled = false;

      grupoIncidente.style.display = 'none';
      selIncidente.disabled = true;
      selIncidente.value = '';

      actualizarInputPersonas(selNormal);

    } else if (v === 'incidente') {
      grupoIncidente.style.display = 'block';
      selIncidente.disabled = false;

      grupoNormal.style.display = 'none';
      selNormal.disabled = true;
      selNormal.value = '';

      actualizarInputPersonas(selIncidente);
    } else {
      grupoNormal.style.display = 'none';
      grupoIncidente.style.display = 'none';
      selNormal.disabled = true;
      selIncidente.disabled = true;
      inputPersonas.disabled = true;
      inputPersonas.value = '';
    }
  });

  selNormal.addEventListener('change', function () {
    actualizarInputPersonas(this);
  });

  selIncidente.addEventListener('change', function () {
    actualizarInputPersonas(this);
  });
});
</script>


</body>
</html>
