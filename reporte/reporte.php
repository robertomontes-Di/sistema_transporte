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
                $errors[] = 'Error al guardar la información del autobús.';
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
                    ':idparada'   => $idParadaDefault, // ← FIX: nunca NULL
                    ':idaccion'   => $idAccionSalida,
                    ':total'      => $totalPersonas,
                    ':comentario' => $comentario
                ]);

                $success            = 'Primer reporte registrado correctamente (Salida hacia el estadio).';
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

        $requierePersonas = accionRequierePersonas($nombreAccion, $accionesRequierenPersonas);

        if ($requierePersonas && $totalPersonas <= 0) {
            $errors[] = 'Debe indicar la cantidad de personas para esta acción.';
        }

        if (!$errors) {
            try {
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
                    ':idparada'   => $idParadaDefault, // ← FIX: nunca NULL
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
