<?php
// admin/carga_tablas.php — Carga masiva de tablas maestras desde CSV
// Tablas soportadas:
//   - encargado_ruta
//   - bus
//   - ruta
//   - ruta_config_bus

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

$errorMsg   = '';
$successMsg = '';

$tablasPermitidas = [
    'encargado_ruta',
    'bus',
    'ruta',
    'ruta_config_bus',
];

/**
 * Normaliza encabezados: minúsculas, trim y remueve BOM.
 */
function normalizarHeader(array $header): array
{
    foreach ($header as $i => $col) {
        if ($i === 0) {
            $col = preg_replace('/^\xEF\xBB\xBF/', '', $col); // quitar BOM
        }
        $header[$i] = strtolower(trim($col));
    }
    return $header;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $tabla   = $_POST['tabla'] ?? '';
    $truncate = isset($_POST['truncate']) ? true : false;

    if (!in_array($tabla, $tablasPermitidas, true)) {
        $errorMsg = 'Tabla no válida.';
    } elseif (
        !isset($_FILES['csv_file']) ||
        $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK
    ) {
        $errorMsg = 'No se recibió el archivo CSV correctamente.';
    } else {

        $tmpName = $_FILES['csv_file']['tmp_name'];

        if (!is_uploaded_file($tmpName)) {
            $errorMsg = 'Error al subir el archivo (no es un upload válido).';
        } else {

            // Si se marcó TRUNCAR, vaciar la tabla (desactivando FK checks)
            if ($truncate) {
                try {
                    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
                    $pdo->exec("TRUNCATE TABLE `$tabla`");
                    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
                } catch (Throwable $e) {
                    $errorMsg = 'Error al truncar la tabla: ' . $e->getMessage();
                }
            }

            if ($errorMsg === '') {
                $fh = fopen($tmpName, 'r');
                if (!$fh) {
                    $errorMsg = 'No se pudo abrir el CSV en el servidor.';
                } else {

                    // Leer encabezado
                    $header = fgetcsv($fh, 0, ',', '"', '\\');
                    if (!$header) {
                        $errorMsg = 'El CSV está vacío o no tiene encabezados.';
                    } else {

                        $header = normalizarHeader($header);

                        $pdo->beginTransaction();
                        $insertadas = 0;

                        try {
                            // -------------------------
                            // Lógica por tabla
                            // -------------------------
                            if ($tabla === 'encargado_ruta') {

                                $idxId       = array_search('idencargado_ruta', $header, true);
                                $idxNombre   = array_search('nombre', $header, true);
                                $idxTelefono = array_search('telefono', $header, true);

                                if ($idxNombre === false) {
                                    throw new RuntimeException(
                                        'El CSV de encargado_ruta debe tener al menos la columna "nombre". ' .
                                        'Opcionales: idencargado_ruta, telefono.'
                                    );
                                }

                                if ($idxId !== false) {
                                    $sql = "INSERT INTO encargado_ruta (idencargado_ruta, nombre, telefono)
                                            VALUES (:id, :nombre, :telefono)";
                                } else {
                                    $sql = "INSERT INTO encargado_ruta (nombre, telefono)
                                            VALUES (:nombre, :telefono)";
                                }
                                $stmt = $pdo->prepare($sql);

                                while (($row = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
                                    if (count($row) === 1 && trim($row[0]) === '') continue;

                                    $nombre = trim($row[$idxNombre] ?? '');
                                    if ($nombre === '') continue;

                                    $telefono = $idxTelefono !== false
                                        ? trim($row[$idxTelefono] ?? '')
                                        : null;

                                    $params = [
                                        ':nombre'   => $nombre,
                                        ':telefono' => $telefono,
                                    ];

                                    if ($idxId !== false) {
                                        $id = (int)($row[$idxId] ?? 0);
                                        if ($id <= 0) continue;
                                        $params[':id'] = $id;
                                    }

                                    $stmt->execute($params);
                                    $insertadas++;
                                }

                            } elseif ($tabla === 'bus') {

                                $idxId       = array_search('idbus', $header, true);
                                $idxPlaca    = array_search('placa', $header, true);
                                $idxCond     = array_search('conductor', $header, true);
                                $idxTel      = array_search('telefono', $header, true);
                                $idxTipo     = array_search('tipo_bus', $header, true);
                                $idxCap      = array_search('capacidad_asientos', $header, true);
                                $idxProv     = array_search('proveedor', $header, true);

                                if ($idxPlaca === false) {
                                    throw new RuntimeException(
                                        'El CSV de bus debe tener al menos la columna "placa". ' .
                                        'Recomendado: idbus, conductor, telefono, tipo_bus, capacidad_asientos, proveedor.'
                                    );
                                }

                                if ($idxId !== false) {
                                    $sql = "INSERT INTO bus (
                                                idbus, placa, conductor, telefono,
                                                tipo_bus, capacidad_asientos,
                                                asientos_ocupados, proveedor
                                            ) VALUES (
                                                :id, :placa, :conductor, :telefono,
                                                :tipo_bus, :capacidad_asientos,
                                                0, :proveedor
                                            )";
                                } else {
                                    $sql = "INSERT INTO bus (
                                                placa, conductor, telefono,
                                                tipo_bus, capacidad_asientos,
                                                asientos_ocupados, proveedor
                                            ) VALUES (
                                                :placa, :conductor, :telefono,
                                                :tipo_bus, :capacidad_asientos,
                                                0, :proveedor
                                            )";
                                }

                                $stmt = $pdo->prepare($sql);

                                while (($row = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
                                    if (count($row) === 1 && trim($row[0]) === '') continue;

                                    $placa = trim($row[$idxPlaca] ?? '');
                                    if ($placa === '') continue;

                                    $conductor = $idxCond !== false ? trim($row[$idxCond] ?? '') : null;
                                    $telefono  = $idxTel  !== false ? trim($row[$idxTel]  ?? '') : null;
                                    $tipoBus   = $idxTipo !== false ? trim($row[$idxTipo] ?? '') : null;
                                    $cap       = $idxCap  !== false ? (int)($row[$idxCap] ?? 0) : null;
                                    $prov      = $idxProv !== false ? trim($row[$idxProv] ?? '') : null;

                                    $params = [
                                        ':placa'             => $placa,
                                        ':conductor'         => $conductor,
                                        ':telefono'          => $telefono,
                                        ':tipo_bus'          => $tipoBus,
                                        ':capacidad_asientos'=> $cap,
                                        ':proveedor'         => $prov,
                                    ];

                                    if ($idxId !== false) {
                                        $id = (int)($row[$idxId] ?? 0);
                                        if ($id <= 0) continue;
                                        $params[':id'] = $id;
                                    }

                                    $stmt->execute($params);
                                    $insertadas++;
                                }

                            } elseif ($tabla === 'ruta') {

                                $idxIdRuta  = array_search('idruta', $header, true);
                                $idxIdEnc   = array_search('idencargado_ruta', $header, true);
                                $idxIdBus   = array_search('idbus', $header, true);
                                $idxNombre  = array_search('nombre', $header, true);
                                $idxIdAgente= array_search('idagente', $header, true);
                                $idxDestino = array_search('destino', $header, true);
                                $idxActiva  = array_search('activa', $header, true);
                                $idxArrival = array_search('flag_arrival', $header, true);

                                if ($idxIdRuta === false || $idxIdEnc === false || $idxIdBus === false || $idxNombre === false) {
                                    throw new RuntimeException(
                                        'El CSV de ruta debe tener columnas: idruta, idencargado_ruta, idbus, nombre. ' .
                                        'Opcionales: idagente, destino, activa, flag_arrival.'
                                    );
                                }

                                $sql = "INSERT INTO ruta (
                                            idruta, idencargado_ruta, idbus, nombre,
                                            idagente, destino, activa, flag_arrival
                                        ) VALUES (
                                            :idruta, :idencargado_ruta, :idbus, :nombre,
                                            :idagente, :destino, :activa, :flag_arrival
                                        )";
                                $stmt = $pdo->prepare($sql);

                                while (($row = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
                                    if (count($row) === 1 && trim($row[0]) === '') continue;

                                    $idruta = (int)($row[$idxIdRuta] ?? 0);
                                    $idenc  = (int)($row[$idxIdEnc] ?? 0);
                                    $idbus  = (int)($row[$idxIdBus] ?? 0);
                                    $nombre = trim($row[$idxNombre] ?? '');

                                    if ($idruta <= 0 || $idenc <= 0 || $idbus <= 0 || $nombre === '') {
                                        continue;
                                    }

                                    $idagente = $idxIdAgente !== false ? (int)($row[$idxIdAgente] ?? 0) : null;
                                    $destino  = $idxDestino  !== false ? trim($row[$idxDestino] ?? '') : null;
                                    $activa   = $idxActiva  !== false ? (int)($row[$idxActiva] ?? 0)  : 0;
                                    $arrival  = $idxArrival !== false ? (int)($row[$idxArrival] ?? 0) : 0;

                                    $stmt->execute([
                                        ':idruta'          => $idruta,
                                        ':idencargado_ruta'=> $idenc,
                                        ':idbus'           => $idbus,
                                        ':nombre'          => $nombre,
                                        ':idagente'        => $idagente,
                                        ':destino'         => $destino,
                                        ':activa'          => $activa,
                                        ':flag_arrival'    => $arrival,
                                    ]);
                                    $insertadas++;
                                }

                            } elseif ($tabla === 'ruta_config_bus') {

                                $idxIdCfg   = array_search('idconfig', $header, true);
                                $idxIdRuta  = array_search('idruta', $header, true);
                                $idxNomMot  = array_search('nombre_motorista', $header, true);
                                $idxTelMot  = array_search('telefono_motorista', $header, true);
                                $idxCapAprox= array_search('capacidad_aprox', $header, true);
                                $idxPlaca   = array_search('placa', $header, true);

                                if ($idxIdRuta === false || $idxNomMot === false || $idxTelMot === false) {
                                    throw new RuntimeException(
                                        'El CSV de ruta_config_bus debe tener columnas: idruta, nombre_motorista, telefono_motorista. ' .
                                        'Opcionales: idconfig, capacidad_aprox, placa.'
                                    );
                                }

                                if ($idxIdCfg !== false) {
                                    $sql = "INSERT INTO ruta_config_bus (
                                                idconfig, idruta, nombre_motorista,
                                                telefono_motorista, capacidad_aprox, placa
                                            ) VALUES (
                                                :idconfig, :idruta, :nombre_motorista,
                                                :telefono_motorista, :capacidad_aprox, :placa
                                            )";
                                } else {
                                    $sql = "INSERT INTO ruta_config_bus (
                                                idruta, nombre_motorista,
                                                telefono_motorista, capacidad_aprox, placa
                                            ) VALUES (
                                                :idruta, :nombre_motorista,
                                                :telefono_motorista, :capacidad_aprox, :placa
                                            )";
                                }

                                $stmt = $pdo->prepare($sql);

                                while (($row = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
                                    if (count($row) === 1 && trim($row[0]) === '') continue;

                                    $idruta = (int)($row[$idxIdRuta] ?? 0);
                                    $nomMot = trim($row[$idxNomMot] ?? '');
                                    $telMot = trim($row[$idxTelMot] ?? '');

                                    if ($idruta <= 0 || $nomMot === '' || $telMot === '') {
                                        continue;
                                    }

                                    $capAprox = $idxCapAprox !== false ? (int)($row[$idxCapAprox] ?? 0) : null;
                                    $placa    = $idxPlaca    !== false ? trim($row[$idxPlaca] ?? '') : null;

                                    $params = [
                                        ':idruta'            => $idruta,
                                        ':nombre_motorista'  => $nomMot,
                                        ':telefono_motorista'=> $telMot,
                                        ':capacidad_aprox'   => $capAprox,
                                        ':placa'             => $placa,
                                    ];

                                    if ($idxIdCfg !== false) {
                                        $idcfg = (int)($row[$idxIdCfg] ?? 0);
                                        if ($idcfg <= 0) continue;
                                        $params[':idconfig'] = $idcfg;
                                    }

                                    $stmt->execute($params);
                                    $insertadas++;
                                }
                            }

                            $pdo->commit();
                            $successMsg = "Registros insertados en $tabla: {$insertadas}.";

                        } catch (Throwable $e) {
                            $pdo->rollBack();
                            $errorMsg = 'Error durante la carga: ' . $e->getMessage();
                        }
                    }

                    fclose($fh);
                }
            }
        }
    }
}

// --------------- Render HTML (AdminLTE simple) -----------------
$pageTitle   = 'Carga masiva de tablas maestras';
$currentPage = 'admin_carga_tablas';

require __DIR__ . '/../templates/header.php';
?>
<div class="content-wrapper">
  <section class="content-header">
    <div class="container-fluid">
      <div class="row mb-2">
        <div class="col-sm-6">
          <h1>Carga masiva de tablas maestras</h1>
          <p class="text-muted mb-0">
            Sube un archivo CSV para cargar datos en
            <code>encargado_ruta</code>, <code>bus</code>, <code>ruta</code> o <code>ruta_config_bus</code>.
          </p>
        </div>
      </div>
    </div>
  </section>

  <section class="content">
    <div class="container-fluid">

      <?php if ($errorMsg): ?>
        <div class="alert alert-danger">
          <?= htmlspecialchars($errorMsg) ?>
        </div>
      <?php endif; ?>

      <?php if ($successMsg): ?>
        <div class="alert alert-success">
          <?= htmlspecialchars($successMsg) ?>
        </div>
      <?php endif; ?>

      <div class="card">
        <div class="card-header">
          <h3 class="card-title">Subir CSV</h3>
        </div>
        <div class="card-body">

          <form method="post" enctype="multipart/form-data">
            <div class="form-group">
              <label for="tabla">Tabla a cargar</label>
              <select name="tabla" id="tabla" class="form-control" required>
                <option value="">-- Seleccione --</option>
                <option value="encargado_ruta">encargado_ruta</option>
                <option value="bus">bus</option>
                <option value="ruta">ruta</option>
                <option value="ruta_config_bus">ruta_config_bus</option>
              </select>
            </div>

            <div class="form-group mt-2">
              <label for="csv_file">Archivo CSV</label>
              <input type="file" name="csv_file" id="csv_file" class="form-control-file" accept=".csv" required>
            </div>

            <div class="form-group form-check mt-2">
              <input type="checkbox" class="form-check-input" id="truncate" name="truncate" value="1">
              <label class="form-check-label" for="truncate">
                Truncar tabla antes de insertar (destruye datos existentes)
              </label>
            </div>

            <button type="submit" class="btn btn-primary mt-3">
              Procesar CSV
            </button>
          </form>

          <hr>

          <h5>Formato de columnas esperado (resumen)</h5>
          <ul>
            <li><strong>encargado_ruta:</strong> idencargado_ruta, nombre, telefono</li>
            <li><strong>bus:</strong> idbus, placa, conductor, telefono, tipo_bus, capacidad_asientos, proveedor</li>
            <li><strong>ruta:</strong> idruta, idencargado_ruta, idbus, nombre, idagente, destino, activa, flag_arrival</li>
            <li><strong>ruta_config_bus:</strong> idconfig, idruta, nombre_motorista, telefono_motorista, capacidad_aprox, placa</li>
          </ul>

        </div>
      </div>

    </div>
  </section>
</div>

<?php
require __DIR__ . '/../templates/footer.php';
