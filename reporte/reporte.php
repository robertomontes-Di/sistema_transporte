<?php
// reporte/reporte.php — Flujo principal de reportes para líder de ruta

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
date_default_timezone_set('America/El_Salvador');
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
// 2) Cargar info básica de la ruta
// ===============================
try {
    $stmt = $pdo->prepare("
        SELECT r.idruta,
               r.nombre AS ruta_nombre,
               r.destino,
               r.flag_arrival,
               r.activa,
               b.placa,
               b.conductor,
               b.telefono AS telefono_motorista,
               b.capacidad_asientos,
               e.nombre  AS encargado_nombre,
               e.telefono AS telefono_encargado
        FROM ruta r
        LEFT JOIN bus b
               ON r.idbus = b.idbus
        LEFT JOIN encargado_ruta e
               ON r.idencargado_ruta = e.idencargado_ruta
        WHERE r.idruta = :idruta
        LIMIT 1
    ");
    $stmt->execute([':idruta' => $idruta]);
    $ruta = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$ruta) {
        throw new RuntimeException('Ruta no encontrada');
    }
} catch (Throwable $e) {
    die('Error al cargar la ruta: ' . htmlspecialchars($e->getMessage()));
}

// ===============================
// 3) Configuración del bus para la ruta (nombre, tel, placa, etc.)
// ===============================
try {
    $stmt = $pdo->prepare("
        SELECT *
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
// 4) Buscar idaccion de “Salida del punto de inicio”
// ===============================
$idAccionSalida = null;
try {
    $stmt = $pdo->prepare("
        SELECT idaccion
        FROM acciones
        WHERE nombre = 'Salida del punto de inicio'
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
$tienePrimerReporte = 0;
try {
    if ($idAccionSalida) {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM reporte 
            WHERE idruta = :idruta 
             
        ");
        $stmt->execute([
            ':idruta'   => $idruta,
            ':idaccion' => $idAccionSalida
        ]);
        $tienePrimerReporte = $stmt->fetchColumn() > 0;
    }
} catch (Throwable $e) {
    $tienePrimerReporte = 0;
}

// ===============================
// 6) Obtener catálogo de acciones
// ===============================
$acciones = [];
try {
    $stmt = $pdo->query("
        SELECT idaccion, nombre, tipo_accion, flag_arrival, orden
        FROM acciones
        ORDER BY orden ASC, nombre ASC
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

// Etiquetas amigables (por si se usan en otra parte)
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
    'abordaje de personas',
    'salida del punto de inicio',
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

    // ---------- FORM 1: Configurar datos del bus ----------
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
                // Empezamos transacción para dejar todo coherente
                $pdo->beginTransaction();

                // 1) Guardar / actualizar tabla ruta_config_bus
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

                // 2) Actualizar también tabla bus si estuviera ligada
                if (!empty($ruta['placa'])) {
                    $stmt = $pdo->prepare("
                        UPDATE bus
                        SET conductor = :nombre,
                            telefono  = :tel
                        WHERE placa = :placa
                        LIMIT 1
                    ");
                    $stmt->execute([
                        ':nombre' => $nombreMotorista,
                        ':tel'    => $telMotorista,
                        ':placa'  => $ruta['placa']
                    ]);
                }

                $pdo->commit();
                $success = 'Datos del autobús actualizados correctamente.';
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = 'Error al guardar la información del autobús.';
            }
        }
    }

    // ---------- FORM 2: Primer reporte “Salida del punto de inicio” ----------
    if ($formStep === 'primer_reporte' && $idAccionSalida) {
        $totalPersonas = isset($_POST['total_personas'])
            ? max(1, (int)$_POST['total_personas'])
            : 0;
        $comentario = trim($_POST['comentario'] ?? '');
        $idparada   = isset($_POST['idparada']) ? (int)$_POST['idparada'] : 0;

        if ($totalPersonas <= 0) {
            $errors[] = 'Debe indicar la cantidad de personas que salen desde el punto de inicio.';
        }

        if (!$errors) {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO reporte (idruta, total_personas, comentario, idaccion, idparada)
                    VALUES (:idruta, :total, :comentario, :idaccion, :idparada)
                ");
                $stmt->execute([
                    ':idruta'     => $idruta,
                    ':total'      => $totalPersonas,
                    ':comentario' => $comentario,
                    ':idaccion'   => $idAccionSalida,
                    ':idparada'   => $idparada
                ]);

                // 🔥 ACTIVAR la ruta cuando se envía "Salida del punto de inicio"
                $stmtActiva = $pdo->prepare("UPDATE ruta SET activa = 1 WHERE idruta = :idruta");
                $stmtActiva->execute([':idruta' => $idruta]);

                if ($stmtActiva->rowCount() === 0) {
                    $errors[] = 'Aviso: no se actualizó ninguna fila en ruta (idruta='.(int)$idruta.').';
                } else {
                    $success            = 'Primer reporte registrado correctamente (Salida del punto de inicio).';
                    $tienePrimerReporte = true;
                }
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
        $flagArrivalAccion = 0;
        if ($idaccion > 0) {
            foreach ($acciones as $a) {
                if ((int)$a['idaccion'] === $idaccion) {
                    $nombreAccion    = $a['nombre'];
                    $flagArrivalAccion = (int)$a['flag_arrival'];
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
                // Para este flujo usamos idparada opcional (si aplica)
                $idparada = isset($_POST['idparada']) ? (int)$_POST['idparada'] : 0;

                $stmt = $pdo->prepare("
                    INSERT INTO reporte (idruta, total_personas, comentario, idaccion, idparada)
                    VALUES (:idruta, :total, :comentario, :idaccion, :idparada)
                ");
                $stmt->execute([
                    ':idruta'     => $idruta,
                    ':total'      => $totalPersonas,
                    ':comentario' => $comentario,
                    ':idaccion'   => $idaccion,
                    ':idparada'   => $idparada
                ]);

                // 🔥 ACTIVAR LA RUTA AL REGISTRAR CUALQUIER NUEVO REPORTE
                $pdo->prepare("
                    UPDATE ruta
                    SET activa = 1
                    WHERE idruta = :idruta
                ")->execute([':idruta' => $idruta]);

                // 🔥 LLEGADA AL ESTADIO (idaccion = 16) → marcar flag_arrival = 1
                if ($idaccion === 16) {
                    $pdo->prepare("
                        UPDATE ruta
                        SET flag_arrival = 1
                        WHERE idruta = :idruta
                    ")->execute([':idruta' => $idruta]);
                }

                $success = 'Reporte registrado correctamente.';
            } catch (Throwable $e) {
                $errors[] = 'Error al guardar el reporte: ' . $e->getMessage();
            }
        }
    }

    // ---------- FORM 4: Reporte solo de ubicación ----------
    if ($formStep === 'ubicacion') {
        $lat  = isset($_POST['lat']) ? (float)$_POST['lat'] : null;
        $lng  = isset($_POST['lng']) ? (float)$_POST['lng'] : null;
        $prec = isset($_POST['precision']) ? (float)$_POST['precision'] : null;

        if ($lat === null || $lng === null) {
            $errors[] = 'No se recibió la ubicación GPS.';
        }

        if (!$errors) {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO ubicaciones (idruta, latitud, longitud, precision_gps)
                    VALUES (:idruta, :lat, :lng, :prec)
                ");
                $stmt->execute([
                    ':idruta' => $idruta,
                    ':lat'    => $lat,
                    ':lng'    => $lng,
                    ':prec'   => $prec
                ]);

                $success = 'Ubicación enviada correctamente.';
            } catch (Throwable $e) {
                $errors[] = 'Error al guardar la ubicación: ' . $e->getMessage();
            }
        }
    }
}

// ===============================
// 9) Preparar datos para los selects del formulario
// ===============================
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

// ===============================
// HTML / VISTA
// ===============================
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Reporte de ruta</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link
    rel="stylesheet"
    href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css"
  >
 <style>
  .wizard-steps {
    display: flex; gap: 10px; margin: 10px 0 20px;
  }
  .wizard-step {
    flex: 1; display:flex; align-items:center;
    background:#e9ecef; border-radius:8px; overflow:hidden;
    border: 1px solid rgba(0,0,0,.08);
  }
  .wizard-step .num {
    width: 44px; height: 44px; display:flex; align-items:center; justify-content:center;
    font-weight: 700; background:#adb5bd; color:#fff;
  }
  .wizard-step .label {
    padding: 0 12px; font-weight:600; color:#495057; font-size:.95rem;
  }
  .wizard-step.active .num,
  .wizard-step.done .num { background:#20c997; }
  .wizard-step.active .label { color:#212529; }
  .wizard-step.done { background:#f8f9fa; }
  .wizard-panel { display:none; }
  .wizard-panel.active { display:block; }
  .wizard-actions { display:flex; gap:10px; margin-top:15px; }
  .wizard-actions .btn { flex:1; }
  .wizard-summary { background:#f8f9fa; border:1px solid rgba(0,0,0,.08); border-radius:8px; padding:12px; }
  .wizard-summary small { color:#6c757d; }
</style>
 <style>
    body {
      background-color: #f1f5f9;
    }
    .card-header {
      background-color: #0d6efd;
      color: #fff;
    }
    .btn-ubicacion {
      background-color: #198754;
      color: #fff;
    }
  </style>
</head>
<body>
<div class="container my-4">

  <!-- Encabezado de la ruta -->
  <div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
      <div>
        <h5 class="mb-1">
          Ruta: <?= htmlspecialchars($ruta['ruta_nombre'] ?? $rutaNombre) ?>
        </h5>
        <small>
          Destino: <?= htmlspecialchars($ruta['destino'] ?? '') ?><br>
          Líder: <?= htmlspecialchars($ruta['encargado_nombre'] ?? '') ?>
        </small>
      </div>
      <a href="logout.php" class="btn btn-light btn-sm">Salir</a>
    </div>
  </div>

  <?php if ($errors): ?>
    <div class="alert alert-danger">
      <ul class="mb-0">
        <?php foreach ($errors as $e): ?>
          <li><?= htmlspecialchars($e) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <?php if ($success): ?>
    <div class="alert alert-success">
      <?= htmlspecialchars($success) ?>
    </div>
  <?php endif; ?>

  <!-- CARD: Nuevo reporte -->
  <div class="card mb-4">
    <div class="card-body">
      <h5 class="card-title">Nuevo reporte</h5>
      <p class="card-text">
        Envíe reportes de ruta, incidencias o emergencias. Use el botón verde para mandar solo la ubicación del bus.
      </p>

      <?php
      // Separar acciones por tipo de severidad (normal / crítico / inconveniente)
      ?>

<!-- -------------------FORM Wizard  2026---------------------------- -->
<form method="post" autocomplete="off" id="formNuevoReporte">
  <input type="hidden" name="form_step" value="nuevo_reporte">
  <input type="hidden" name="idaccion" id="idaccion_real" value="">

  <!-- Wizard header -->
  <div class="wizard-steps">
    <div class="wizard-step active" data-step="1">
      <div class="num">1</div><div class="label">Tipo</div>
    </div>
    <div class="wizard-step" data-step="2">
      <div class="num">2</div><div class="label">Detalle</div>
    </div>
    <div class="wizard-step" data-step="3">
      <div class="num">3</div><div class="label">Confirmar</div>
    </div>
  </div>

  <!-- STEP 1 -->
  <div class="wizard-panel active" data-panel="1">
    <div class="form-group">
      <label for="tipo_reporte">Tipo de reporte</label>
      <select name="tipo_reporte" id="tipo_reporte" class="form-control" required>
        <option value="">Seleccione…</option>
        <option value="normal">Normal</option>
        <option value="incidente">Incidente</option>
      </select>
      <small class="form-text text-muted">Elige si es un reporte normal o un incidente.</small>
    </div>

    <div class="wizard-actions">
      <button type="button" class="btn btn-primary" id="btnNext1">Siguiente</button>
    </div>
  </div>

  <!-- STEP 2 -->
  <div class="wizard-panel" data-panel="2">
    <!-- NORMAL -->
    <div class="form-group" id="grupo_normal" style="display:none;">
      <label for="idaccion_normal">Detalle del reporte (normal)</label>
      <select id="idaccion_normal" class="form-control">
        <option value="">Seleccione…</option>
        <?php foreach ($accionesNormal as $a): ?>
          <?php $requiere = accionRequierePersonas($a['nombre'], $accionesRequierenPersonas) ? '1' : '0'; ?>
          <option value="<?= (int)$a['idaccion'] ?>" data-requiere-personas="<?= $requiere ?>">
            <?= htmlspecialchars($a['nombre']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <!-- INCIDENTE -->
    <div class="form-group" id="grupo_incidente" style="display:none;">
      <label for="idaccion_incidente">Detalle del incidente</label>
      <select id="idaccion_incidente" class="form-control">
        <option value="">Seleccione…</option>

        <?php if (!empty($accionesCritico)): ?>
          <optgroup label="Crítico">
            <?php foreach ($accionesCritico as $a): ?>
              <?php $requiere = accionRequierePersonas($a['nombre'], $accionesRequierenPersonas) ? '1' : '0'; ?>
              <option value="<?= (int)$a['idaccion'] ?>" data-requiere-personas="<?= $requiere ?>">
                <?= htmlspecialchars($a['nombre']) ?>
              </option>
            <?php endforeach; ?>
          </optgroup>
        <?php endif; ?>

        <?php if (!empty($accionesInconveniente)): ?>
          <optgroup label="Inconveniente">
            <?php foreach ($accionesInconveniente as $a): ?>
              <?php $requiere = accionRequierePersonas($a['nombre'], $accionesRequierenPersonas) ? '1' : '0'; ?>
              <option value="<?= (int)$a['idaccion'] ?>" data-requiere-personas="<?= $requiere ?>">
                <?= htmlspecialchars($a['nombre']) ?>
              </option>
            <?php endforeach; ?>
          </optgroup>
        <?php endif; ?>
      </select>
    </div>

    <div class="form-group">
      <label for="total_personas_main">Cantidad de personas que subieron</label>
      <input type="number" name="total_personas" id="total_personas_main"
             class="form-control" min="0" disabled>
      <small class="form-text text-muted">
        Solo se habilita para eventos como “Abordaje de personas” o “Salida del punto de inicio”.
      </small>
    </div>

    <div class="form-group">
      <label for="comentario">Comentario (opcional)</label>
      <textarea name="comentario" id="comentario" class="form-control" rows="3"></textarea>
    </div>

    <div class="wizard-actions">
      <button type="button" class="btn btn-outline-secondary" id="btnBack2">Atrás</button>
      <button type="button" class="btn btn-primary" id="btnNext2">Siguiente</button>
    </div>
  </div>

  <!-- STEP 3 -->
  <div class="wizard-panel" data-panel="3">
    <div class="wizard-summary">
      <div><strong>Tipo:</strong> <span id="sumTipo">—</span></div>
      <div><strong>Acción:</strong> <span id="sumAccion">—</span></div>
      <div><strong>Personas:</strong> <span id="sumPersonas">—</span></div>
      <div class="mt-2"><strong>Comentario:</strong></div>
      <small id="sumComentario">—</small>
    </div>

    <div class="wizard-actions">
      <button type="button" class="btn btn-outline-secondary" id="btnBack3">Atrás</button>
      <button type="submit" class="btn btn-success">Confirmar y enviar</button>
    </div>
  </div>
</form>


<!-- 
      <form method="post" autocomplete="off" id="formNuevoReporte">
        <input type="hidden" name="form_step" value="nuevo_reporte"> -->

        <!-- Campo REAL que verá el backend (idaccion numérico) -->
        <!-- <input type="hidden" name="idaccion" id="idaccion_real" value=""> -->

        <!-- 1) Tipo de reporte: Normal / Incidente -->
        <!-- <div class="form-group">
          <label for="tipo_reporte">Tipo de reporte</label>
          <select name="tipo_reporte" id="tipo_reporte" class="form-control" required>
            <option value="">Seleccione…</option>
            <option value="normal">Normal</option>
            <option value="incidente">Incidente</option>
          </select>
        </div> -->

        <!-- 2) Detalle cuando es NORMAL -->
        <!-- <div class="form-group" id="grupo_normal" style="display:none;">
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
        </div> -->

        <!-- 3) Detalle cuando es INCIDENTE -->
        <!-- <div class="form-group" id="grupo_incidente" style="display:none;">
          <label for="idaccion_incidente">Detalle del incidente</label>
          <select id="idaccion_incidente" class="form-control">
            <option value="">Seleccione…</option> -->

            <!-- Crítico -->
            <!-- <?php if (!empty($accionesCritico)): ?>
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
            <?php endif; ?> -->

            <!-- Inconveniente -->
            <!-- <?php if (!empty($accionesInconveniente)): ?>
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
        </div> -->

        <!-- 4) Cantidad de personas (se activa solo si aplica) -->
        <!-- <div class="form-group">
          <label for="total_personas_main">Cantidad de personas que subieron</label>
          <input type="number"
                name="total_personas"
                id="total_personas_main"
                class="form-control"
                min="0"
                disabled>
          <small class="form-text text-muted">
            Solo se habilita para eventos como “Abordaje de personas”, “Salida del punto de inicio” o “Retorno a punto de inicio”.
          </small>
        </div> -->

        <!-- 5) Comentario general -->
        <!-- <div class="form-group">
          <label for="comentario">Comentario (opcional)</label>
          <textarea
            name="comentario"
            id="comentario"
            class="form-control"
            rows="3"
          ></textarea>
        </div>

        <button type="submit" class="btn btn-primary btn-block">
          Enviar reporte
        </button>
      </form> -->

      <hr>

      <!-- Botón aparte para enviar solo ubicación -->
      <!-- <form method="post" id="formUbicacion" class="mt-3">
        <input type="hidden" name="form_step" value="ubicacion">
        <input type="hidden" name="lat" id="lat">
        <input type="hidden" name="lng" id="lng">
        <input type="hidden" name="precision" id="precision">

        <button type="button" id="btnUbicacion" class="btn btn-ubicacion btn-block">
          Enviar ubicación
        </button>
        <small class="form-text text-muted">
          Este botón solo envía la posición GPS de la unidad (sin crear un nuevo evento de reporte).
        </small>
      </form> -->
    </div>
  </div>

</div>

<script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
<script>
(function() {
// Botón "Enviar ubicación"
const btnUbicacion = document.getElementById('btnUbicacion');
if (btnUbicacion) {
  btnUbicacion.addEventListener('click', function() {
    if (!navigator.geolocation) {
      alert('La geolocalización no es soportada en este dispositivo.');
      return;
    }

    btnUbicacion.disabled = true;
    btnUbicacion.textContent = 'Enviando ubicación...';

    navigator.geolocation.getCurrentPosition(
      function(pos) {
        const lat    = pos.coords.latitude;
        const lng    = pos.coords.longitude;
        const idruta = <?= (int)$idruta ?>;

        fetch('guardar_ubicacion.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
          },
          body: 'lat=' + encodeURIComponent(lat) +
                '&lng=' + encodeURIComponent(lng) +
                '&idruta=' + encodeURIComponent(idruta)
        })
        .then(r => r.json())
        .then(data => {
          console.log('Respuesta guardar_ubicacion.php:', data);
          if (data && data.success) {
            alert('Ubicación enviada correctamente.');
          } else {
            const msg = (data && data.msg) ? data.msg : 'Error desconocido al guardar la ubicación.';
            alert('Error al guardar la ubicación: ' + msg);
          }
        })
        .catch((err) => {
          console.error('Error fetch ubicación:', err);
          alert('Error al enviar la ubicación.');
        })
        .finally(() => {
          btnUbicacion.disabled = false;
          btnUbicacion.textContent = 'Enviar ubicación';
        });
      },
      function(err) {
        alert('No se pudo obtener la ubicación: ' + err.message);
        btnUbicacion.disabled = false;
        btnUbicacion.textContent = 'Enviar ubicación';
      }
    );
  });
}

})();
</script>
<script>
document.addEventListener('DOMContentLoaded', function () {
  const steps = Array.from(document.querySelectorAll('.wizard-step'));
  const panels = Array.from(document.querySelectorAll('.wizard-panel'));

  const tipoReporte   = document.getElementById('tipo_reporte');
  const grupoNormal   = document.getElementById('grupo_normal');
  const grupoIncidente= document.getElementById('grupo_incidente');
  const selNormal     = document.getElementById('idaccion_normal');
  const selIncidente  = document.getElementById('idaccion_incidente');
  const inputPersonas = document.getElementById('total_personas_main');
  const idaccionReal  = document.getElementById('idaccion_real');
  const comentario    = document.getElementById('comentario');

  const btnNext1 = document.getElementById('btnNext1');
  const btnNext2 = document.getElementById('btnNext2');
  const btnBack2 = document.getElementById('btnBack2');
  const btnBack3 = document.getElementById('btnBack3');

  const sumTipo = document.getElementById('sumTipo');
  const sumAccion = document.getElementById('sumAccion');
  const sumPersonas = document.getElementById('sumPersonas');
  const sumComentario = document.getElementById('sumComentario');

  function setStep(n) {
    panels.forEach(p => p.classList.toggle('active', p.dataset.panel == n));
    steps.forEach(s => {
      const sn = parseInt(s.dataset.step, 10);
      s.classList.toggle('active', sn === n);
      s.classList.toggle('done', sn < n);
    });
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }

  function limpiarSelectsDetalle() {
    if (selNormal) selNormal.value = '';
    if (selIncidente) selIncidente.value = '';
    inputPersonas.disabled = true;
    inputPersonas.value = '';
    idaccionReal.value = '';
  }

  function actualizarVistaDetallePorTipo() {
    limpiarSelectsDetalle();
    if (tipoReporte.value === 'normal') {
      grupoNormal.style.display = 'block';
      grupoIncidente.style.display = 'none';
    } else if (tipoReporte.value === 'incidente') {
      grupoNormal.style.display = 'none';
      grupoIncidente.style.display = 'block';
    } else {
      grupoNormal.style.display = 'none';
      grupoIncidente.style.display = 'none';
    }
  }

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

  // Eventos existentes (misma lógica tuya)
  tipoReporte.addEventListener('change', actualizarVistaDetallePorTipo);

  if (selNormal) {
    selNormal.addEventListener('change', function () {
      idaccionReal.value = this.value || '';
      actualizarInputPersonas(this);
    });
  }

  if (selIncidente) {
    selIncidente.addEventListener('change', function () {
      idaccionReal.value = this.value || '';
      actualizarInputPersonas(this);
    });
  }

  // Wizard navegación
  btnNext1.addEventListener('click', function () {
    if (!tipoReporte.value) {
      alert('Selecciona el tipo de reporte.');
      return;
    }
    actualizarVistaDetallePorTipo();
    setStep(2);
  });

  btnBack2.addEventListener('click', () => setStep(1));

  btnNext2.addEventListener('click', function () {
    if (!idaccionReal.value) {
      alert('Selecciona el detalle del reporte.');
      return;
    }

    // Si el input está habilitado, debe tener valor > 0
    if (!inputPersonas.disabled) {
      const val = parseInt(inputPersonas.value || '0', 10);
      if (val <= 0) {
        alert('Ingresa la cantidad de personas.');
        return;
      }
    }

    // Resumen (Paso 3)
    sumTipo.textContent = (tipoReporte.value === 'normal') ? 'Normal' : 'Incidente';

    // Texto de la acción seleccionada
    let accionTxt = '—';
    if (tipoReporte.value === 'normal' && selNormal.value) {
      accionTxt = selNormal.options[selNormal.selectedIndex].text;
    }
    if (tipoReporte.value === 'incidente' && selIncidente.value) {
      accionTxt = selIncidente.options[selIncidente.selectedIndex].text;
    }
    sumAccion.textContent = accionTxt;

    sumPersonas.textContent = inputPersonas.disabled ? 'No aplica' : (inputPersonas.value || '—');
    sumComentario.textContent = comentario.value ? comentario.value : '—';

    setStep(3);
  });

  btnBack3.addEventListener('click', () => setStep(2));

  // Inicial
  actualizarVistaDetallePorTipo();
});
</script>
<script>
document.addEventListener('DOMContentLoaded', function () {
  const tipoReporte   = document.getElementById('tipo_reporte');
  const grupoNormal   = document.getElementById('grupo_normal');
  const grupoIncidente= document.getElementById('grupo_incidente');
  const selNormal     = document.getElementById('idaccion_normal');
  const selIncidente  = document.getElementById('idaccion_incidente');
  const inputPersonas = document.getElementById('total_personas_main');
  const idaccionReal  = document.getElementById('idaccion_real');

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

  function limpiarSelects() {
    selNormal.value = '';
    selIncidente.value = '';
    inputPersonas.disabled = true;
    inputPersonas.value = '';
    idaccionReal.value = '';
  }

  tipoReporte.addEventListener('change', function () {
    limpiarSelects();
    if (this.value === 'normal') {
      grupoNormal.style.display = 'block';
      grupoIncidente.style.display = 'none';
    } else if (this.value === 'incidente') {
      grupoNormal.style.display = 'none';
      grupoIncidente.style.display = 'block';
    } else {
      grupoNormal.style.display = 'none';
      grupoIncidente.style.display = 'none';
    }
  });

  selNormal.addEventListener('change', function () {
    idaccionReal.value = this.value || '';
    actualizarInputPersonas(this);
  });

  selIncidente.addEventListener('change', function () {
    idaccionReal.value = this.value || '';
    actualizarInputPersonas(this);
  });
});
</script>
</body>
</html>
