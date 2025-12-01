<?php
session_start();
require_once __DIR__ . '/../includes/db.php';

$idruta = intval($_POST['idruta'] ?? 0);
$clave  = trim($_POST['clave'] ?? '');

if ($idruta <= 0 || $clave === '') {
    header("Location: index.php?error=Datos incompletos");
    exit;
}

try {
    // Obtener encargado de la ruta
    $stmt = $pdo->prepare("
        SELECT er.idencargado_ruta, er.telefono, r.nombre
        FROM ruta r
        LEFT JOIN encargado_ruta er ON er.idencargado_ruta = r.idencargado_ruta
        WHERE r.idruta = :idruta
        LIMIT 1
    ");
    $stmt->execute([':idruta' => $idruta]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        header("Location: index.php?error=Ruta no encontrada");
        exit;
    }

    // Validar clave = teléfono del líder
    if ($clave !== $row["telefono"]) {
        header("Location: index.php?error=Clave incorrecta");
        exit;
    }

    // Si la clave es correcta -> crear sesión
    $_SESSION['idruta']       = $idruta;
    $_SESSION['ruta_nombre']  = $row['nombre'];

    header("Location: reporte.php");
    exit;

} catch (Throwable $e) {
    header("Location: index.php?error=Error interno");
    exit;
}
