<?php
// acciones.php (en la raíz del sistema)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';

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

try {
    $stmt = $pdo->query("
        SELECT idaccion, nombre, tipo_accion
        FROM acciones
        ORDER BY tipo_accion, nombre
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $data = [];
    foreach ($rows as $r) {
        $tipo = $r['tipo_accion'] ?? 'otro';
        $data[] = [
            'idaccion'      => (int)$r['idaccion'],
            'nombre'        => $r['nombre'],
            'tipo_accion'   => $tipo,
            'etiqueta_tipo' => etiquetaTipoAccion($tipo),
        ];
    }

    echo json_encode([
        'success' => true,
        'data'    => $data
    ]);
} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'error'   => 'Error al obtener las acciones.'
    ]);
}
?>