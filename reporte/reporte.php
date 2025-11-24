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

$idruta      = (int)$_SESSION['idruta'];
$rutaNombre  = $_SESSION['ruta_nombre'] ?? '';

// Cargar info básica de la ruta (opcional para mostrar en la UI)
$rutaInfo = null;
try {
    $stmt = $pdo->prepare("
        SELECT r.idruta, r.nombre, r.destino, er.nombre AS encargado, er.telefono
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
// 2) Ver si el bus ya está configurado
// ===============================
$configBus = null;
try {
    $stmt = $pdo->prepare("SELECT * FROM ruta_config_bus WHERE idruta = :idruta LIMIT 1");
    $stmt->execute([':idruta' => $idruta]);
    $configBus = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $configBus = null;
}

// ===============================
// 3) Buscar idaccion de “Salida hacia el estadio”
// ===============================
$idAccionSalida = null;
try {
    $stmt = $pdo->prepare("SELECT idaccion FROM acciones WHERE nombre = 'Salida hacia el estadio' LIMIT 1");
    $stmt->execute();
    $idAccionSalida = $stmt->fetchColumn();
} catch (Throwable $e) {
    $idAccionSalida = null;
}

// ¿Ya existe un reporte “Salida hacia el estadio” HOY para esta ruta?
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

// ===============================
// 4) Cargar lista de acciones para el form principal
// ===============================
$acciones = [];
try {
    $stmt = $pdo->query("SELECT idaccion, nombre FROM acciones ORDER BY nombre");
    $acciones = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $acciones = [];
}

// Mapa: idaccion => nombre normalizado (para reglas en backend)
$accionesNombrePorId = [];
foreach ($acciones as $a) {
    $id = (int)$a['idaccion'];
    $accionesNombrePorId[$id] = mb_strtolower(trim($a['nombre'] ?? ''), 'UTF-8');
}

/**
 * AGRUPAR ACCIONES POR CATEGORÍA (solo frontend)
 */
$accionesPorGrupo = [
    'ruta'       => [],
    'incidencia' => [],
    'emergencia' => [],
    'asistencia' => [],
    'vehiculo'   => [],
    'otro'       => [],
    'otras'      => [],
];

foreach ($acciones as $a) {
    $nombreRaw = $a['nombre'] ?? '';
    $nombre    = mb_strtolower(trim($nombreRaw), 'UTF-8');

    if (in_array($nombre, [
        'salida hacia el estadio',
        'llegada a parada',
        'salida de parada',
        'regreso a casa',
    ], true)) {
        $accionesPorGrupo['ruta'][] = $a;
    } elseif (in_array($nombre, [
        'tráfico en carretera',
        'trafico en carretera',
        'accidente en carretera',
        'modificacion de ruta por imprevisto',
        'modificación de ruta por imprevisto',
        'retraso de la unidad de transporte',
        'retraso por apoyo a otra ruta',
        'retraso por espera de beneficiarios',
        'ruta cancelada',
    ], true)) {
        $accionesPorGrupo['incidencia'][] = $a;
    } elseif ($nombre === 'atencion de emergencia medica' || $nombre === 'atención de emergencia médica') {
        $accionesPorGrupo['emergencia'][] = $a;
    } elseif (in_array($nombre, [
        'instrucciones erróneas sobre la ruta',
        'instrucciones erroneas sobre la ruta',
        'desconocimiento de la ruta por parte del motorista',
    ], true)) {
        $accionesPorGrupo['asistencia'][] = $a;
    } elseif (in_array($nombre, [
        'desperfectos mecanicos',
        'desperfectos mecánicos',
        'motorista con problemas de salud',
    ], true)) {
        $accionesPorGrupo['vehiculo'][] = $a;
    } elseif (mb_strpos($nombre, 'otro') !== false) {
        $accionesPorGrupo['otro'][] = $a;
    } else {
        $accionesPorGrupo['otras'][] = $a;
    }
}

// ===============================
// 5) Cargar paradas de la ruta (para el select en nuevo reporte)
// ===============================
$paradasRuta = [];
try {
    $stmt = $pdo->prepare("
        SELECT idparada, punto_abordaje, orden
        FROM paradas
        WHERE idruta = :idruta
        ORDER BY orden ASC
    ");
    $stmt->execute([':idruta' => $idruta]);
    $paradasRuta = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $paradasRuta = [];
}

// ===============================
// 6) Manejo de formularios (POST)
// ===============================
$errors  = [];
$success = null;

// listas de reglas para backend
$accionesQueRequierenParadaPHP = [
    'llegada a parada',
    'salida de parada',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formStep = $_POST['form_step'] ?? '';

    // ---------- FORM 1: Guardar config de bus ----------
    if ($formStep === 'config_bus') {
        $nombreMotorista = trim($_POST['nombre_motorista'] ?? '');
        $telMotorista    = trim($_POST['telefono_motorista'] ?? '');
        $capacidad       = isset($_POST['capacidad_aprox']) && $_POST['capacidad_aprox'] !== ''
                            ? (int)$_POST['capacidad_aprox'] : null;
        $placa           = trim($_POST['placa'] ?? '');

        if ($nombreMotorista === '') $errors[] = 'Debe ingresar el nombre del motorista.';
        if ($telMotorista === '')    $errors[] = 'Debe ingresar el teléfono del motorista.';

        if (!$errors) {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO ruta_config_bus (idruta, nombre_motorista, telefono_motorista, capacidad_aprox, placa)
                    VALUES (:idruta, :nombre, :tel, :cap, :placa)
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

                $success   = 'Datos del autobús guardados correctamente.';
                $configBus = [
                    'nombre_motorista'   => $nombreMotorista,
                    'telefono_motorista' => $telMotorista,
                    'capacidad_aprox'    => $capacidad,
                    'placa'              => $placa
                ];
            } catch (Throwable $e) {
                $errors[] = 'Error al guardar la información del autobús.';
            }
        }
    }

    // ---------- FORM 2: Primer reporte (Salida hacia el estadio) ----------
    if ($formStep === 'primer_reporte' && $idAccionSalida) {
        $totalPersonas = isset($_POST['total_personas']) ? (int)$_POST['total_personas'] : 0;
        $comentario    = trim($_POST['comentario'] ?? '');

        if ($totalPersonas <= 0) {
            $errors[] = 'Debe indicar la cantidad de personas a bordo.';
        }

        if (!$errors) {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO reporte
                        (idruta, idagente, idparada, idaccion, total_personas, total_becarios, total_menores12, comentario, fecha_reporte)
                    VALUES
                        (:idruta, NULL, NULL, :idaccion, :total, 0, 0, :comentario, NOW())
                ");
                $stmt->execute([
                    ':idruta'     => $idruta,
                    ':idaccion'   => $idAccionSalida,
                    ':total'      => $totalPersonas,
                    ':comentario' => $comentario
                ]);

                $success = 'Primer reporte registrado correctamente (Salida hacia el estadio).';
                $tienePrimerReporte = true;
            } catch (Throwable $e) {
                $errors[] = 'Error al guardar el primer reporte.';
            }
        }
    }

    // ---------- FORM 3: Nuevo reporte general ----------
    if ($formStep === 'nuevo_reporte') {
        $idaccion      = isset($_POST['idaccion']) ? (int)$_POST['idaccion'] : 0;
        $totalPersonas = ($_POST['total_personas'] ?? '') !== ''
                            ? (int)$_POST['total_personas'] : 0;
        $comentario    = trim($_POST['comentario'] ?? '');
        $idparada      = isset($_POST['idparada']) && $_POST['idparada'] !== ''
                            ? (int)$_POST['idparada'] : null;

        if ($idaccion <= 0) {
            $errors[] = 'Debe seleccionar el tipo de reporte.';
        }

        // Determinar si esta acción requiere parada (según nombre)
        $nombreAccionSel = $accionesNombrePorId[$idaccion] ?? '';
        $requiereParada  = in_array($nombreAccionSel, $accionesQueRequierenParadaPHP, true);

        if ($requiereParada && !$idparada) {
            $errors[] = 'Debe seleccionar la parada para este tipo de reporte.';
        }

        if (!$errors) {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO reporte
                        (idruta, idagente, idparada, idaccion, total_personas, total_becarios, total_menores12, comentario, fecha_reporte)
                    VALUES
                        (:idruta, NULL, :idparada, :idaccion, :total, 0, 0, :comentario, NOW())
                ");
                $stmt->execute([
                    ':idruta'     => $idruta,
                    ':idparada'   => $idparada,
                    ':idaccion'   => $idaccion,
                    ':total'      => $totalPersonas,
                    ':comentario' => $comentario
                ]);

                $success = 'Reporte registrado correctamente.';
            } catch (Throwable $e) {
                $errors[] = 'Error al guardar el reporte.';
            }
        }
    }
}

// ===============================
// 7) Determinar paso actual de la vista
// ===============================
$step = 'main';
if (!$configBus) {
    $step = 'config_bus';
} elseif (!$tienePrimerReporte && $idAccionSalida) {
    $step = 'primer_reporte';
} else {
    $step = 'nuevo_reporte';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Reporte de Ruta</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- Bootstrap simple, igual que el login -->
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
            max-width: 600px;
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
        <p class="text-muted">Completa esta información una sola vez antes de enviar reportes.</p>

        <form method="post" autocomplete="off">
          <input type="hidden" name="form_step" value="config_bus">

          <div class="form-group">
            <label for="nombre_motorista">Nombre del motorista</label>
            <input type="text" name="nombre_motorista" id="nombre_motorista"
                   class="form-control" required>
          </div>

          <div class="form-group">
            <label for="telefono_motorista">Teléfono del conductor</label>
            <input type="text" name="telefono_motorista" id="telefono_motorista"
                   class="form-control" required>
          </div>

          <div class="form-group">
            <label for="capacidad_aprox">Capacidad aproximada del autobús</label>
            <input type="number" name="capacidad_aprox" id="capacidad_aprox"
                   class="form-control" min="0" placeholder="Ej. 100">
          </div>

          <div class="form-group">
            <label for="placa">Placa</label>
            <input type="text" name="placa" id="placa" class="form-control">
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
            <input type="number" name="total_personas" id="total_personas"
                   class="form-control" required min="1">
          </div>

          <div class="form-group">
            <label for="comentario">Comentario (opcional)</label>
            <input type="text" name="comentario" id="comentario"
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
          Envíe reportes de ruta, incidencias o emergencias. Use el botón verde para mandar solo ubicación.
        </p>

        <form method="post" autocomplete="off" id="formNuevoReporte">
          <input type="hidden" name="form_step" value="nuevo_reporte">

          <div class="form-group">
            <label for="idaccion">Tipo de reporte</label>
            <select name="idaccion" id="idaccion" class="form-control" required>
              <option value="">Seleccione…</option>

              <?php if (!empty($accionesPorGrupo['ruta'])): ?>
                <optgroup label="Reporte de Ruta">
                  <?php foreach ($accionesPorGrupo['ruta'] as $a): ?>
                    <option value="<?= (int)$a['idaccion'] ?>"
                            data-nombre="<?= htmlspecialchars($a['nombre']) ?>"
                            data-grupo="ruta">
                      <?= htmlspecialchars($a['nombre']) ?>
                    </option>
                  <?php endforeach; ?>
                </optgroup>
              <?php endif; ?>

              <?php if (!empty($accionesPorGrupo['incidencia'])): ?>
                <optgroup label="Reporte de Incidencia">
                  <?php foreach ($accionesPorGrupo['incidencia'] as $a): ?>
                    <option value="<?= (int)$a['idaccion'] ?>"
                            data-nombre="<?= htmlspecialchars($a['nombre']) ?>"
                            data-grupo="incidencia">
                      <?= htmlspecialchars($a['nombre']) ?>
                    </option>
                  <?php endforeach; ?>
                </optgroup>
              <?php endif; ?>

              <?php if (!empty($accionesPorGrupo['emergencia'])): ?>
                <optgroup label="Reporte de Emergencia">
                  <?php foreach ($accionesPorGrupo['emergencia'] as $a): ?>
                    <option value="<?= (int)$a['idaccion'] ?>"
                            data-nombre="<?= htmlspecialchars($a['nombre']) ?>"
                            data-grupo="emergencia">
                      <?= htmlspecialchars($a['nombre']) ?>
                    </option>
                  <?php endforeach; ?>
                </optgroup>
              <?php endif; ?>

              <?php if (!empty($accionesPorGrupo['asistencia'])): ?>
                <optgroup label="Necesito Asistencia">
                  <?php foreach ($accionesPorGrupo['asistencia'] as $a): ?>
                    <option value="<?= (int)$a['idaccion'] ?>"
                            data-nombre="<?= htmlspecialchars($a['nombre']) ?>"
                            data-grupo="asistencia">
                      <?= htmlspecialchars($a['nombre']) ?>
                    </option>
                  <?php endforeach; ?>
                </optgroup>
              <?php endif; ?>

              <?php if (!empty($accionesPorGrupo['vehiculo'])): ?>
                <optgroup label="Reporte del estado del vehículo">
                  <?php foreach ($accionesPorGrupo['vehiculo'] as $a): ?>
                    <option value="<?= (int)$a['idaccion'] ?>"
                            data-nombre="<?= htmlspecialchars($a['nombre']) ?>"
                            data-grupo="vehiculo">
                      <?= htmlspecialchars($a['nombre']) ?>
                    </option>
                  <?php endforeach; ?>
                </optgroup>
              <?php endif; ?>

              <?php if (!empty($accionesPorGrupo['otro'])): ?>
                <optgroup label="Otro">
                  <?php foreach ($accionesPorGrupo['otro'] as $a): ?>
                    <option value="<?= (int)$a['idaccion'] ?>"
                            data-nombre="<?= htmlspecialchars($a['nombre']) ?>"
                            data-grupo="otro">
                      <?= htmlspecialchars($a['nombre']) ?>
                    </option>
                  <?php endforeach; ?>
                </optgroup>
              <?php endif; ?>

              <?php if (!empty($accionesPorGrupo['otras'])): ?>
                <optgroup label="Otras acciones">
                  <?php foreach ($accionesPorGrupo['otras'] as $a): ?>
                    <option value="<?= (int)$a['idaccion'] ?>"
                            data-nombre="<?= htmlspecialchars($a['nombre']) ?>"
                            data-grupo="otras">
                      <?= htmlspecialchars($a['nombre']) ?>
                    </option>
                  <?php endforeach; ?>
                </optgroup>
              <?php endif; ?>

            </select>
          </div>

          <!-- NUEVO: Select de parada (solo para llegada/salida de parada) -->
          <div class="form-group" id="grupo_parada" style="display:none;">
            <label for="idparada" id="label_parada">Parada (si aplica)</label>
            <select name="idparada" id="idparada" class="form-control">
              <option value="">Seleccione parada…</option>
              <?php foreach ($paradasRuta as $p): ?>
                <option value="<?= (int)$p['idparada'] ?>">
                  <?= htmlspecialchars(($p['orden'] ?? '') . '. ' . ($p['punto_abordaje'] ?? 'Parada')) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <small class="form-text text-muted">
              Solo se requiere para acciones de llegada/salida en una parada específica.
            </small>
          </div>

          <div class="form-group">
            <label for="total_personas_main" id="label_total_personas">Cantidad de personas (si aplica)</label>
            <input type="number" name="total_personas" id="total_personas_main"
                   class="form-control" min="0" disabled>
            <small class="form-text text-muted">
              Solo se habilita para eventos que requieren actualizar personas a bordo.
            </small>
          </div>

          <div class="form-group">
            <label for="comentario_main" id="label_comentario">Comentario (opcional)</label>
            <input type="text" name="comentario" id="comentario_main"
                   class="form-control">
          </div>

          <button type="submit" class="btn btn-primary btn-block mb-2">
            Enviar reporte
          </button>
        </form>

        <hr>

        <button type="button" class="btn btn-ubicacion btn-block" id="btnUbicacion">
          Enviar ubicación
        </button>

        <small class="text-muted d-block mt-2">
          Este botón solo envía la posición GPS de la unidad (sin crear un nuevo evento de reporte).
        </small>

      <?php endif; ?>

    </div>
  </div>
</div>

<script>
/**
 * FRONTEND:
 * - Agrupa visualmente las acciones (ya está en el HTML con <optgroup>).
 * - Habilita/deshabilita "Cantidad de personas" según la acción.
 * - Marca comentario obligatorio para "Otro (especifique)".
 * - Muestra/oculta el select de PARADA para llegada/salida de parada.
 */
(function() {
  const selectAccion   = document.getElementById('idaccion');
  const inputPersonas  = document.getElementById('total_personas_main');
  const labelPersonas  = document.getElementById('label_total_personas');
  const inputComent    = document.getElementById('comentario_main');
  const labelComent    = document.getElementById('label_comentario');

  const grupoParada    = document.getElementById('grupo_parada');
  const selectParada   = document.getElementById('idparada');
  const labelParada    = document.getElementById('label_parada');

  // Acciones que requieren cantidad de personas
  const accionesQueRequierenPersonas = [
    'salida hacia el estadio',
    'salida de parada'
  ];

  // Acciones que requieren una parada
  const accionesQueRequierenParada = [
    'llegada a parada',
    'salida de parada'
  ];

  function normalizarNombre(nombre) {
    return (nombre || '').toLowerCase().trim();
  }

  function accionRequierePersonas(nombre) {
    const n = normalizarNombre(nombre);
    return accionesQueRequierenPersonas.some(txt => n.includes(txt));
  }

  function accionRequiereParada(nombre) {
    const n = normalizarNombre(nombre);
    return accionesQueRequierenParada.some(txt => n.includes(txt));
  }

  function accionRequiereComentarioObligatorio(nombre) {
    const n = normalizarNombre(nombre);
    // Criterio simple: acciones que contengan la palabra "otro"
    return n.includes('otro');
  }

  if (selectAccion) {
    selectAccion.addEventListener('change', function() {
      const opt    = this.options[this.selectedIndex];
      const nombre = opt ? (opt.getAttribute('data-nombre') || '') : '';

      // 1) Personas
      if (accionRequierePersonas(nombre)) {
        inputPersonas.disabled = false;
        if (labelPersonas) {
          labelPersonas.textContent = 'Cantidad de personas *';
        }
      } else {
        inputPersonas.value = '';
        inputPersonas.disabled = true;
        if (labelPersonas) {
          labelPersonas.textContent = 'Cantidad de personas (si aplica)';
        }
      }

      // 2) Comentario obligatorio para "Otro"
      if (inputComent && labelComent) {
        if (accionRequiereComentarioObligatorio(nombre)) {
          inputComent.required = true;
          labelComent.textContent = 'Comentario (obligatorio para este tipo de reporte)';
        } else {
          inputComent.required = false;
          labelComent.textContent = 'Comentario (opcional)';
        }
      }

      // 3) Parada obligatoria para llegada/salida de parada
      if (grupoParada && selectParada && labelParada) {
        if (accionRequiereParada(nombre)) {
          grupoParada.style.display = 'block';
          selectParada.required = true;
          labelParada.textContent = 'Parada *';
        } else {
          grupoParada.style.display = 'none';
          selectParada.required = false;
          selectParada.value = '';
          labelParada.textContent = 'Parada (si aplica)';
        }
      }
    });
  }

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
            if (data && data.success) {
              alert('Ubicación enviada correctamente.');
            } else {
              alert('Error al guardar la ubicación.');
            }
          })
          .catch(() => {
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

</body>
</html>
