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

</body>
</html>
