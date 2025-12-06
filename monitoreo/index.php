<?php
// monitoreo/index.php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

// -----------------------------------------------------
// Helpers
// -----------------------------------------------------
$accionesRequierePersonas = [
    'abordaje de personas',
    'salida del punto de inicio',
];

function accionRequierePersonas(?string $nombreAccion, array $lista): bool
{
    if (!$nombreAccion) return false;
    $nombre = mb_strtolower($nombreAccion, 'UTF-8');
    foreach ($lista as $needle) {
        if (mb_strpos($nombre, $needle, 0, 'UTF-8') !== false) {
            return true;
        }
    }
    return false;
}

// -----------------------------------------------------
// AJAX: guardar reporte
// -----------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['ajax'] ?? '') === 'guardar_reporte') {
    header('Content-Type: application/json; charset=utf-8');

    $idruta        = isset($_POST['idruta']) ? (int)$_POST['idruta'] : 0;
    $tipoReporte   = trim($_POST['tipo_reporte'] ?? '');
    $idaccion      = isset($_POST['idaccion']) ? (int)$_POST['idaccion'] : 0;
    $rawTotal      = trim($_POST['total_personas'] ?? '');
    $totalPersonas = ($rawTotal !== '') ? max(0, (int)$rawTotal) : 0;
    $comentario    = trim($_POST['comentario'] ?? '');

    $errors = [];

    if ($idruta <= 0) {
        $errors[] = 'Debe seleccionar una ruta.';
    }
    if ($tipoReporte !== 'normal' && $tipoReporte !== 'incidente') {
        $errors[] = 'Debe seleccionar el tipo de reporte.';
    }
    if ($idaccion <= 0) {
        $errors[] = 'Debe seleccionar el detalle del reporte.';
    }

    // Obtener info de la acción
    $nombreAccion = null;
    try {
        $stmtAcc = $pdo->prepare("SELECT nombre FROM acciones WHERE idaccion = :id LIMIT 1");
        $stmtAcc->execute([':id' => $idaccion]);
        $nombreAccion = $stmtAcc->fetchColumn();
    } catch (Throwable $e) {
        $errors[] = 'No se pudo obtener la acción seleccionada.';
    }

    // Validar si requiere personas
    $requierePersonas = accionRequierePersonas($nombreAccion, $accionesRequierePersonas);

    if ($requierePersonas && $totalPersonas <= 0) {
        $errors[] = 'Debe indicar la cantidad de personas para esta acción.';
    }

    if (!$requierePersonas) {
        $totalPersonas = 0;
    }

    if ($errors) {
        echo json_encode([
            'success' => false,
            'errors'  => $errors,
        ]);
        exit;
    }

    try {
        $pdo->beginTransaction();

        // Insertar reporte
        $stmt = $pdo->prepare("
            INSERT INTO reporte
                (idruta, idagente, idparada, idaccion,
                 total_personas, total_becarios, total_menores12,
                 comentario, fecha_reporte)
            VALUES
                (:idruta, NULL, 0, :idaccion,
                 :total, 0, 0,
                 :comentario, NOW())
        ");
        $stmt->execute([
            ':idruta'     => $idruta,
            ':idaccion'   => $idaccion,
            ':total'      => $totalPersonas,
            ':comentario' => $comentario
        ]);

        // Activar la ruta ante cualquier reporte
        if ($idaccion === 16) {
            // Llegada al Estadio Mágico González
            $stmtUpd = $pdo->prepare("
                UPDATE ruta
                SET activa = 1,
                    flag_arrival = 1
                WHERE idruta = :idruta
            ");
        } else {
            $stmtUpd = $pdo->prepare("
                UPDATE ruta
                SET activa = 1
                WHERE idruta = :idruta
            ");
        }
        $stmtUpd->execute([':idruta' => $idruta]);

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Reporte registrado correctamente.'
        ]);
        exit;
    } catch (Throwable $e) {
        $pdo->rollBack();
        echo json_encode([
            'success' => false,
            'errors'  => ['Error al guardar el reporte: ' . $e->getMessage()]
        ]);
        exit;
    }
}

// -----------------------------------------------------
// AJAX: actualizar encargado de ruta
// -----------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['ajax'] ?? '') === 'update_encargado') {
    header('Content-Type: application/json; charset=utf-8');

    $idruta   = isset($_POST['idruta_encargado']) ? (int)$_POST['idruta_encargado'] : 0;
    $nombre   = trim($_POST['nombre_encargado'] ?? '');
    $telefono = trim($_POST['telefono_encargado'] ?? '');

    $errors = [];

    if ($idruta <= 0) {
        $errors[] = 'Debe seleccionar una ruta.';
    }
    if ($nombre === '') {
        $errors[] = 'Debe ingresar el nombre del encargado.';
    }
    if ($telefono === '') {
        $errors[] = 'Debe ingresar el teléfono del encargado.';
    }

    if ($errors) {
        echo json_encode([
            'success' => false,
            'errors'  => $errors
        ]);
        exit;
    }

    try {
        // Obtener idencargado_ruta de la ruta
        $stmt = $pdo->prepare("SELECT idencargado_ruta FROM ruta WHERE idruta = :idruta LIMIT 1");
        $stmt->execute([':idruta' => $idruta]);
        $idEnc = $stmt->fetchColumn();

        if (!$idEnc) {
            echo json_encode([
                'success' => false,
                'errors'  => ['No se encontró el encargado de esta ruta.']
            ]);
            exit;
        }

        // Actualizar datos del encargado
        $stmtUp = $pdo->prepare("
            UPDATE encargado_ruta
            SET nombre = :nombre,
                telefono = :tel
            WHERE idencargado_ruta = :idenc
        ");
        $stmtUp->execute([
            ':nombre' => $nombre,
            ':tel'    => $telefono,
            ':idenc'  => $idEnc
        ]);

        echo json_encode([
            'success' => true,
            'message' => 'Encargado actualizado correctamente.'
        ]);
        exit;
    } catch (Throwable $e) {
        echo json_encode([
            'success' => false,
            'errors'  => ['Error al actualizar el encargado: ' . $e->getMessage()]
        ]);
        exit;
    }
}

// -----------------------------------------------------
// Si no es AJAX, construir la página HTML
// -----------------------------------------------------

// Acciones para llenar selects dinámicos
$acciones = [];
try {
    $stmtAcc = $pdo->query("SELECT idaccion, nombre, tipo_accion FROM acciones ORDER BY nombre");
    $acciones = $stmtAcc->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $acciones = [];
}

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
$accionesIncidente = array_merge($accionesCritico, $accionesInconveniente);

// Rutas + encargado
$rutas = [];
try {
    $sqlRutas = "
        SELECT
            r.idruta,
            r.nombre AS ruta_nombre,
            er.idencargado_ruta,
            er.nombre AS encargado_nombre,
            er.telefono
        FROM ruta r
        LEFT JOIN encargado_ruta er
          ON er.idencargado_ruta = r.idencargado_ruta
        ORDER BY r.nombre
    ";
    $stmtR = $pdo->query($sqlRutas);
    $rutas = $stmtR->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $rutas = [];
}
$title = "Crear Registro";
include "../templates/header.php";
include "../templates/sidebar.php";
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>Monitoreo - Reportes de Ruta</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- Bootstrap 4 -->
    <link rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">

    <!-- Select2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />

    <style>
        body {
            background: #f4f6f9;
        }

        .page-title {
            margin-top: 20px;
            margin-bottom: 20px;
        }

        .card {
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.08);
            margin-bottom: 20px;
        }

        .card-header {
            font-weight: 600;
        }

        .select2-container .select2-selection--single {
            height: 38px !important;
            padding: 4px !important;
        }

        .select2-selection__rendered {
            line-height: 28px !important;
        }

        .select2-selection__arrow {
            height: 34px !important;
        }
    </style>
</head>

<body>

    <div class="container page-title">
        <h3>Centro de Monitoreo - Registro de reportes</h3>
        <p class="text-muted mb-4">
            Desde aquí puedes registrar reportes manuales para cualquier ruta y actualizar los datos del encargado.
        </p>
    </div>

    <div class="container">
        <div class="row">

            <!-- =============================== -->
            <!-- CARD 1: REPORTE DE RUTA         -->
            <!-- =============================== -->
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        Registrar reporte de ruta
                    </div>
                    <div class="card-body">
                        <form id="formReporteMonitoreo" autocomplete="off">
                            <input type="hidden" name="form_step" value="nuevo_reporte">
                            <input type="hidden" name="idaccion" id="idaccion_real" value="">

                            <!-- Ruta -->
                            <div class="form-group">
                                <label for="ruta_reporte">Ruta</label>
                                <select name="idruta" id="ruta_reporte" class="form-control" required>
                                    <option value="">Seleccione una ruta…</option>
                                    <?php foreach ($rutas as $r): ?>
                                        <option value="<?= (int)$r['idruta'] ?>">
                                            <?= htmlspecialchars($r['ruta_nombre']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Tipo de reporte -->
                            <div class="form-group">
                                <label for="tipo_reporte">Tipo de reporte</label>
                                <select name="tipo_reporte" id="tipo_reporte" class="form-control" required>
                                    <option value="">Seleccione…</option>
                                    <option value="normal">Normal</option>
                                    <option value="incidente">Incidente</option>
                                </select>
                            </div>

                            <!-- Detalle NORMAL -->
                            <div class="form-group" id="grupo_normal" style="display:none;">
                                <label for="idaccion_normal">Detalle del reporte (normal)</label>
                                <select id="idaccion_normal" class="form-control">
                                    <option value="">Seleccione…</option>
                                    <?php foreach ($accionesNormal as $a): ?>
                                        <?php
                                        $requiere = accionRequierePersonas($a['nombre'], $accionesRequierePersonas) ? '1' : '0';
                                        ?>
                                        <option value="<?= (int)$a['idaccion'] ?>"
                                            data-requiere-personas="<?= $requiere ?>">
                                            <?= htmlspecialchars($a['nombre']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Detalle INCIDENTE -->
                            <div class="form-group" id="grupo_incidente" style="display:none;">
                                <label for="idaccion_incidente">Detalle del incidente</label>
                                <select id="idaccion_incidente" class="form-control">
                                    <option value="">Seleccione…</option>
                                    <?php foreach ($accionesIncidente as $a): ?>
                                        <?php
                                        $requiere = accionRequierePersonas($a['nombre'], $accionesRequierePersonas) ? '1' : '0';
                                        ?>
                                        <option value="<?= (int)$a['idaccion'] ?>"
                                            data-requiere-personas="<?= $requiere ?>">
                                            <?= htmlspecialchars($a['nombre']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Total personas -->
                            <div class="form-group">
                                <label for="total_personas">Total de personas (solo cuando aplique)</label>
                                <input type="number"
                                    name="total_personas"
                                    id="total_personas"
                                    class="form-control"
                                    min="0"
                                    disabled>
                                <small class="form-text text-muted">
                                    Solo se habilita para acciones como “Salida hacia el estadio” o “Salida de parada”.
                                </small>
                            </div>

                            <!-- Comentario -->
                            <div class="form-group">
                                <label for="comentario">Comentario (opcional)</label>
                                <input type="text"
                                    name="comentario"
                                    id="comentario"
                                    class="form-control">
                            </div>

                            <button type="submit" id="btnGuardarReporte" class="btn btn-primary btn-block">
                                Enviar reporte
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- =============================== -->
            <!-- CARD 2: ENCARGADO DE RUTA       -->
            <!-- =============================== -->
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-info text-white">
                        Modificar encargado de ruta
                    </div>
                    <div class="card-body">
                        <form id="formEncargado" autocomplete="off">
                            <!-- Ruta -->
                            <div class="form-group">
                                <label for="ruta_encargado">Ruta</label>
                                <select name="idruta_encargado" id="ruta_encargado" class="form-control" required>
                                    <option value="">Seleccione una ruta…</option>
                                    <?php foreach ($rutas as $r): ?>
                                        <option value="<?= (int)$r['idruta'] ?>"
                                            data-encargado="<?= htmlspecialchars($r['encargado_nombre'] ?? '') ?>"
                                            data-telefono="<?= htmlspecialchars($r['telefono'] ?? '') ?>">
                                            <?= htmlspecialchars($r['ruta_nombre']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="nombre_encargado">Nombre del encargado</label>
                                <input type="text"
                                    name="nombre_encargado"
                                    id="nombre_encargado"
                                    class="form-control"
                                    required>
                            </div>

                            <div class="form-group">
                                <label for="telefono_encargado">Teléfono del encargado</label>
                                <input type="text"
                                    name="telefono_encargado"
                                    id="telefono_encargado"
                                    class="form-control"
                                    maxlength="9"
                                    required>
                            </div>

                            <button type="submit" id="btnGuardarEncargado" class="btn btn-info btn-block">
                                Guardar cambios
                            </button>
                        </form>
                    </div>
                </div>
            </div>

        </div>
    </div>
    <?php
    require __DIR__ . '/../templates/footer.php';
    ?>
    <!-- JS -->
    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Select2 JS -->
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

    <script>
        $(document).ready(function() {
    $('#ruta_reporte').select2({
        placeholder: "Escriba para buscar su ruta…",
        allowClear: true,
        width: '100%',
        language: {
            noResults: () => "No se encontraron rutas",
            searching: () => "Buscando…"
        }
    });

        $('#ruta_encargado').select2({
        placeholder: "Escriba para buscar su ruta…",
        allowClear: true,
        width: '100%',
        language: {
            noResults: () => "No se encontraron rutas",
            searching: () => "Buscando…"
        }
    });
});
        document.addEventListener('DOMContentLoaded', function() {
            // -------------------------------
            // Lógica selects dinámicos reporte
            // -------------------------------
            const tipoReporte = document.getElementById('tipo_reporte');
            const grupoNormal = document.getElementById('grupo_normal');
            const grupoIncidente = document.getElementById('grupo_incidente');
            const selNormal = document.getElementById('idaccion_normal');
            const selIncidente = document.getElementById('idaccion_incidente');
            const hiddenAccion = document.getElementById('idaccion_real');
            const inputPersonas = document.getElementById('total_personas');

            function resetDetalle() {
                if (selNormal) selNormal.value = '';
                if (selIncidente) selIncidente.value = '';
                hiddenAccion.value = '';
                inputPersonas.value = '';
                inputPersonas.disabled = true;
                inputPersonas.required = false;
            }

            function aplicarDesdeSelect(select) {
                const opt = select.options[select.selectedIndex];
                if (!opt || !opt.value) {
                    hiddenAccion.value = '';
                    inputPersonas.value = '';
                    inputPersonas.disabled = true;
                    inputPersonas.required = false;
                    return;
                }
                hiddenAccion.value = opt.value;
                const requiere = opt.dataset.requierePersonas === '1';
                if (requiere) {
                    inputPersonas.disabled = false;
                    inputPersonas.required = true;
                } else {
                    inputPersonas.disabled = true;
                    inputPersonas.required = false;
                    inputPersonas.value = '';
                }
            }

            if (tipoReporte) {
                tipoReporte.addEventListener('change', function() {
                    resetDetalle();
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
            }

            if (selNormal) {
                selNormal.addEventListener('change', function() {
                    aplicarDesdeSelect(this);
                });
            }
            if (selIncidente) {
                selIncidente.addEventListener('change', function() {
                    aplicarDesdeSelect(this);
                });
            }

            // -------------------------------
            // Envío AJAX reporte
            // -------------------------------
            $('#formReporteMonitoreo').on('submit', function(e) {
                e.preventDefault();

                const $btn = $('#btnGuardarReporte');
                $btn.prop('disabled', true).text('Guardando...');

                $.ajax({
                    url: 'index.php',
                    method: 'POST',
                    data: $(this).serialize() + '&ajax=guardar_reporte',
                    dataType: 'json'
                }).done(function(resp) {
                    $btn.prop('disabled', false).text('Enviar reporte');
                    if (resp.success) {
                        alert(resp.message || 'Reporte registrado correctamente.');
                        $('#formReporteMonitoreo')[0].reset();
                        resetDetalle();
                    } else {
                        alert((resp.errors && resp.errors.join('\\n')) || 'Error al guardar el reporte.');
                    }
                }).fail(function() {
                    $btn.prop('disabled', false).text('Enviar reporte');
                    alert('Error del servidor al guardar el reporte.');
                });
            });

            // -------------------------------
            // Encargado de ruta
            // -------------------------------
            $('#ruta_encargado').on('change', function() {
                const opt = this.options[this.selectedIndex];
                if (!opt || !opt.value) {
                    $('#nombre_encargado').val('');
                    $('#telefono_encargado').val('');
                    return;
                }
                $('#nombre_encargado').val(opt.dataset.encargado || '');
                $('#telefono_encargado').val(opt.dataset.telefono || '');
            });

            $('#formEncargado').on('submit', function(e) {
                e.preventDefault();

                const $btn = $('#btnGuardarEncargado');
                $btn.prop('disabled', true).text('Guardando...');

                $.ajax({
                    url: 'index.php',
                    method: 'POST',
                    data: $(this).serialize() + '&ajax=update_encargado',
                    dataType: 'json'
                }).done(function(resp) {
                    $btn.prop('disabled', false).text('Guardar cambios');
                    if (resp.success) {
                        alert(resp.message || 'Encargado actualizado correctamente.');
                    } else {
                        alert((resp.errors && resp.errors.join('\\n')) || 'Error al actualizar el encargado.');
                    }
                }).fail(function() {
                    $btn.prop('disabled', false).text('Guardar cambios');
                    alert('Error del servidor al actualizar el encargado.');
                });
            });
        });
    </script>

</body>

</html>