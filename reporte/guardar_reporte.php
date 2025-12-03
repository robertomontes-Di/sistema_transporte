<?php
// reporte/guardar_reporte.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'success' => false,
        'errors'  => ['Método no permitido']
    ]);
    exit;
}

if (empty($_SESSION['idruta'])) {
    echo json_encode([
        'success' => false,
        'errors'  => ['Sesión inválida, vuelva a iniciar sesión.']
    ]);
    exit;
}

$idruta   = (int)$_SESSION['idruta'];
$formStep = $_POST['form_step'] ?? '';

$errors  = [];
$success = false;
$nextStep = null;

// ==================
// Helpers / Datos base
// ==================
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

$accionesRequierenPersonas = [
    'Salida del punto de inicio',
    'Abordaje de personas',
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

// Buscar idaccion de “Salida hacia el estadio”
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

// ¿Bus ya configurado?
$configBus = null;
try {
    $stmt = $pdo->prepare("
        SELECT idruta
        FROM ruta_config_bus
        WHERE idruta = :idruta
        LIMIT 1
    ");
    $stmt->execute([':idruta' => $idruta]);
    $configBus = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $configBus = null;
}

// ¿Ya existe primer reporte hoy?
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

// Traer todas las acciones (para validar en server-side)
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

// ==================================================
// 1) FORM: Configuración del bus
// ==================================================
if ($formStep === 'config_bus') {

    $nombreMotorista = trim($_POST['nombre_motorista'] ?? '');
    $telMotorista    = trim($_POST['telefono_motorista'] ?? '');
    $capacidad       = ($_POST['capacidad_aprox'] ?? '') !== '' ? (int)$_POST['capacidad_aprox'] : null;
    $placa           = trim($_POST['placa'] ?? '');

    if ($nombreMotorista === '') $errors[] = 'Debe ingresar el nombre del motorista.';
    if ($telMotorista === '')    $errors[] = 'Debe ingresar el teléfono del motorista.';

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
                ':placa'  => $placa,
            ]);

            $success  = true;
            // Si todavía no hay primer reporte, paso siguiente = primer_reporte
            $nextStep = $tienePrimerReporte ? 'nuevo_reporte' : 'primer_reporte';
        } catch (Throwable $e) {
            $errors[] = 'Error al guardar la información del autobús.';
        }
    }
}

// ==================================================
// 2) FORM: Primer reporte “Salida hacia el estadio”
// ==================================================
if ($formStep === 'primer_reporte' && $idAccionSalida) {

    // Seguridad extra: si ya existe, NO permitir duplicado
    if ($tienePrimerReporte) {
        $errors[] = "El primer reporte ('Salida del punto de inicio') ya fue enviado hoy.";
    }

    // Obtener la parada principal (primera parada de la ruta)
    $idParadaDefault = null;
    try {
        $stmtParada = $pdo->prepare("
            SELECT idparada
            FROM paradas
            WHERE idruta = :idruta
            ORDER BY orden ASC
            LIMIT 1
        ");
        $stmtParada->execute([':idruta' => $idruta]);
        $idParadaDefault = $stmtParada->fetchColumn();
    } catch (Throwable $e) {
        $idParadaDefault = null;
    }

    if (!$idParadaDefault) {
        $errors[] = 'La ruta no tiene paradas configuradas. Contacte al área de soporte.';
    }

    $totalPersonas = isset($_POST['total_personas']) ? (int)$_POST['total_personas'] : 0;
    $comentario    = trim($_POST['comentario'] ?? '');

    if ($totalPersonas <= 0) {
        $errors[] = 'Debe indicar la cantidad de personas a bordo.';
    }

    if (!$errors) {
        try {
            // Dejamos el INSERT + UPDATE en una transacción
            $pdo->beginTransaction();

            // 1) Insertar el primer reporte
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
                ':idparada'   => $idParadaDefault,
                ':idaccion'   => $idAccionSalida,
                ':total'      => $totalPersonas,
                ':comentario' => $comentario
            ]);

            // 2) Activar la ruta cuando sale hacia el estadio
            $stmtActiva = $pdo->prepare("
                UPDATE ruta
                SET activa = 1
                WHERE idruta = :idruta
            ");
            $stmtActiva->execute([':idruta' => $idruta]);

            $pdo->commit();

            // Para el frontend (respuesta JSON)
            $success            = true;
            $nextStep           = 'nuevo_reporte';
            $tienePrimerReporte = true;

        } catch (Throwable $e) {
            $pdo->rollBack();
            $errors[] = 'Error al guardar el primer reporte: ' . $e->getMessage();
        }
    }
}


// ==================================================
// 3) FORM: Nuevo reporte general
// ==================================================
// --- Tu código existente para guardar un nuevo reporte ---

if ($formStep === 'nuevo_reporte') {
    // ... (aquí va tu validación de datos existente)

    if (!$errors) {
        try {
            $pdo->beginTransaction(); // Iniciar transacción para seguridad

            // 1. OBTENER EL NOMBRE DE LA ACCIÓN SELECCIONADA
            $stmtNombre = $pdo->prepare("SELECT nombre FROM acciones WHERE idaccion = :idaccion LIMIT 1");
            $stmtNombre->execute([':idaccion' => $idaccion]);
            $nombreAccion = $stmtNombre->fetchColumn();

            // 2. INSERTAR EL REPORTE (como ya lo haces)
            $stmt = $pdo->prepare("
                INSERT INTO reporte
                    (idruta, idparada, idaccion, total_personas, comentario, fecha_reporte)
                VALUES
                    (:idruta, :idparada, :idaccion, :total, :comentario, NOW())
            ");
            $stmt->execute([
                ':idruta'     => $idruta,
                ':idparada'   => 0, // Usas 0 para reportes de líder
                ':idaccion'   => $idaccion,
                ':total'      => $totalPersonas,
                ':comentario' => $comentario
            ]);

            // 3. LÓGICA ADICIONAL: ACTUALIZAR LA BANDERA SI ES NECESARIO
            // Comprobamos si el nombre de la acción es el de llegada al estadio
            if ($nombreAccion === 'LLegada a Estadio Magico Gonzales') {
                $stmtUpdate = $pdo->prepare("
                    UPDATE ruta
                    SET flag_llegada_estadio = 1,
                        fecha_llegada_estadio = NOW()
                    WHERE idruta = :idruta
                ");
                $stmtUpdate->execute([':idruta' => $idruta]);
            }
            
            // Si todo fue bien, confirmamos los cambios
            $pdo->commit();

            $success = 'Reporte registrado correctamente.';

        } catch (Throwable $e) {
            $pdo->rollBack(); // Revertir cambios si algo falla
            $errors[] = 'Error al guardar el reporte: ' . $e->getMessage();
        }
    }
}


// ==================================================
// Respuesta JSON
// ==================================================
echo json_encode([
    'success'  => $success,
    'errors'   => $errors,
    'nextStep' => $nextStep,
]);
?>