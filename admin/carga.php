<?php
// admin/carga.php — Carga masiva de paradas desde CSV (idruta, url_maps, estimado_personas)
// Ahora usando Google Maps API para:
//  - Reverse Geocoding: departamento, municipio
//  - Directions: tiempos para hora_abordaje y hora_salida (llegada 11:00)

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

$errorMsg   = '';
$successMsg = '';

/**
 * CONFIGURACIÓN GOOGLE MAPS
 * - Puedes definir GOOGLE_MAPS_API_KEY en includes/config.php
 *   define('GOOGLE_MAPS_API_KEY', 'TU_API_KEY_AQUI');
 * - O edita aquí el valor por defecto.
 */
$GOOGLE_API_KEY = defined('GOOGLE_MAPS_API_KEY')
    ? GOOGLE_MAPS_API_KEY
    : 'AIzaSyC2Zm7v7BAdKaA-KAvna0q4y0lQgwvE1V4'; // <-- REEMPLAZA AQUÍ CON TU KEY SI NO USAS CONFIG

// Hora objetivo de llegada al destino (Estadio / Destino de la ruta)
const HORA_LLEGADA_DESTINO = '11:00:00';

/**
 * Realiza un GET a una URL y devuelve array JSON decodificado o null.
 */
function httpGetJson(string $url): ?array
{
    // Preferir cURL si existe
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT        => 10,
        ]);
        $resp = curl_exec($ch);
        if ($resp === false) {
            curl_close($ch);
            return null;
        }
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200) {
            return null;
        }

        $data = json_decode($resp, true);
        return is_array($data) ? $data : null;
    }

    // Fallback a file_get_contents
    $context = stream_context_create([
        'http' => [
            'method'  => 'GET',
            'timeout' => 10,
        ],
        'ssl'  => [
            'verify_peer'      => true,
            'verify_peer_name' => true,
        ],
    ]);

    $resp = @file_get_contents($url, false, $context);
    if ($resp === false) {
        return null;
    }
    $data = json_decode($resp, true);
    return is_array($data) ? $data : null;
}

/**
 * Reverse geocoding: devuelve [departamento, municipio] a partir de lat/lng.
 */
function obtenerDepartamentoMunicipio(float $lat, float $lng, string $apiKey): array
{
    $url = sprintf(
        'https://maps.googleapis.com/maps/api/geocode/json?latlng=%F,%F&key=%s&language=es',
        $lat,
        $lng,
        urlencode($apiKey)
    );

    $json = httpGetJson($url);
    $departamento = '';
    $municipio    = '';

    if (!$json || ($json['status'] ?? '') !== 'OK') {
        return [$departamento, $municipio];
    }

    $results = $json['results'] ?? [];
    if (!$results) {
        return [$departamento, $municipio];
    }

    // Tomamos el primer resultado
    $components = $results[0]['address_components'] ?? [];
    foreach ($components as $comp) {
        $types = $comp['types'] ?? [];
        if (in_array('administrative_area_level_1', $types, true)) {
            $departamento = $comp['long_name'] ?? '';
        }
        if (
            in_array('locality', $types, true) ||
            in_array('administrative_area_level_2', $types, true) ||
            in_array('administrative_area_level_3', $types, true)
        ) {
            if ($municipio === '') {
                $municipio = $comp['long_name'] ?? '';
            }
        }
    }

    return [$departamento, $municipio];
}

/**
 * Llama a Directions API usando la lista de puntos como
 * origin, waypoints y destination.
 *
 * Devuelve un array de duraciones en segundos por cada tramo (legs):
 *  [ leg0_segundos, leg1_segundos, ..., legN-1_segundos ]
 */
function obtenerDuracionesPorTramo(array $points, string $apiKey): array
{
    $n = count($points);
    if ($n < 2) {
        return [];
    }

    $origin      = $points[0]['lat'] . ',' . $points[0]['lng'];
    $destination = $points[$n - 1]['lat'] . ',' . $points[$n - 1]['lng'];

    $waypointsParam = '';
    if ($n > 2) {
        $wps = [];
        for ($i = 1; $i < $n - 1; $i++) {
            $wps[] = 'via:' . $points[$i]['lat'] . ',' . $points[$i]['lng'];
        }
        $waypointsParam = '&waypoints=' . urlencode(implode('|', $wps));
    }

    $url = 'https://maps.googleapis.com/maps/api/directions/json?'
         . 'origin=' . urlencode($origin)
         . '&destination=' . urlencode($destination)
         . '&language=es'
         . '&key=' . urlencode($apiKey)
         . $waypointsParam;

    $json = httpGetJson($url);
    if (!$json || ($json['status'] ?? '') !== 'OK') {
        return [];
    }

    $routes = $json['routes'] ?? [];
    if (!$routes) {
        return [];
    }

    $legs = $routes[0]['legs'] ?? [];
    $durations = [];
    foreach ($legs as $leg) {
        $val = $leg['duration']['value'] ?? null; // segundos
        if (!is_null($val)) {
            $durations[] = (int)$val;
        }
    }

    return $durations;
}

/**
 * Convierte "HH:MM:SS" a segundos desde 00:00:00.
 */
function timeToSeconds(string $time): int
{
    $parts = explode(':', $time);
    $h = isset($parts[0]) ? (int)$parts[0] : 0;
    $m = isset($parts[1]) ? (int)$parts[1] : 0;
    $s = isset($parts[2]) ? (int)$parts[2] : 0;
    return $h * 3600 + $m * 60 + $s;
}

/**
 * Convierte segundos (0..86399) a "HH:MM:SS".
 */
function secondsToTime(int $seconds): string
{
    // Normalizar dentro del rango del día
    $seconds = $seconds % 86400;
    if ($seconds < 0) {
        $seconds += 86400;
    }

    $h = intdiv($seconds, 3600);
    $seconds %= 3600;
    $m = intdiv($seconds, 60);
    $s = $seconds % 60;

    return sprintf('%02d:%02d:%02d', $h, $m, $s);
}

/**
 * Construye horario de cada parada:
 * - todas deben llegar al destino a HORA_LLEGADA_DESTINO.
 * - hora_salida(parada_i) = HORA_LLEGADA_DESTINO - tiempo_desde_parada_i_hasta_destino
 * - hora_abordaje = hora_salida - 10 minutos.
 *
 * @return array indexado por posición del punto:
 *   [
 *     0 => ['hora_abordaje' => 'HH:MM:SS', 'hora_salida' => 'HH:MM:SS'],
 *     1 => ...
 *   ]
 */
function construirHorarioParadas(array $points, array $legsDurations): array
{
    $n = count($points);
    if ($n === 0) {
        return [];
    }

    $arrivalSeconds = timeToSeconds(HORA_LLEGADA_DESTINO);
    $horario = [];

    // segundos desde cada parada hasta el destino
    $secondsToDest = array_fill(0, $n, 0);

    if (!empty($legsDurations) && count($legsDurations) === $n - 1) {
        // Recorremos de atrás hacia adelante, acumulando duración
        $accum = 0;
        for ($i = $n - 2; $i >= 0; $i--) {
            $accum += (int)$legsDurations[$i];
            $secondsToDest[$i] = $accum;
        }
        $secondsToDest[$n - 1] = 0; // último punto está "pegado" al destino
    } else {
        // Fallback: si no hay datos de Directions o no cuadran,
        // asumimos intervalos de 20 min entre paradas
        $step = 20 * 60; // 20 minutos
        for ($i = 0; $i < $n; $i++) {
            $secondsToDest[$i] = $step * ($n - 1 - $i);
        }
    }

    foreach ($points as $idx => $pt) {
        $secsToDest   = $secondsToDest[$idx] ?? 0;
        $salidaSecs   = max(0, $arrivalSeconds - $secsToDest);
        $abordajeSecs = max(0, $salidaSecs - 600); // 10 minutos antes

        $horario[$idx] = [
            'hora_salida'   => secondsToTime($salidaSecs),
            'hora_abordaje' => secondsToTime($abordajeSecs),
        ];
    }

    return $horario;
}

/**
 * Distribuye el estimado de personas entre N paradas.
 * Ej: total=50, n=3 → [17,17,16]
 */
function distribuirEstimadoPersonas(?int $total, int $n): array
{
    $total = $total ?? 0;
    if ($n <= 0 || $total <= 0) {
        return array_fill(0, $n, 0);
    }

    $base = intdiv($total, $n);
    $rest = $total % $n;

    $result = [];
    for ($i = 0; $i < $n; $i++) {
        $result[$i] = $base + ($i < $rest ? 1 : 0);
    }
    return $result;
}

// ------------------------------------------------------------------
// Procesamiento del formulario (POST)
// ------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (
        !isset($_FILES['csv_file']) ||
        $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK
    ) {
        $errorMsg = 'No se recibió el archivo CSV correctamente.';
    } elseif ($GOOGLE_API_KEY === 'TU_API_KEY_AQUI') {
        $errorMsg = 'Configura tu Google Maps API Key en carga.php o en includes/config.php.';
    } else {

        $tmpName = $_FILES['csv_file']['tmp_name'];

        if (!is_uploaded_file($tmpName)) {
            $errorMsg = 'Error al subir el archivo (no es un upload válido).';
        } else {
            $fh = fopen($tmpName, 'r');
            if (!$fh) {
                $errorMsg = 'No se pudo abrir el CSV en el servidor.';
            } else {

                // ---------------------------
                // 1) Leer encabezado
                // ---------------------------
                // PHP 8: hay que pasar también el parámetro $escape
                $header = fgetcsv($fh, 0, ',', '"', '\\');
                if (!$header) {
                    $errorMsg = 'El CSV está vacío o no tiene encabezados.';
                } else {
                    // Normalizar encabezados: quitar BOM, espacios, minúsculas
                    foreach ($header as $i => $col) {
                        if ($i === 0) {
                            // quitar BOM si existe
                            $col = preg_replace('/^\xEF\xBB\xBF/', '', $col);
                        }
                        $header[$i] = strtolower(trim($col));
                    }

                    // Buscar índices
                    $idxIdruta   = array_search('idruta', $header, true);
                    $idxUrlMaps  = array_search('url_maps', $header, true);
                    $idxEstimado = array_search('estimado_personas', $header, true);

                    if ($idxIdruta === false || $idxUrlMaps === false) {
                        $errorMsg = 'El CSV debe tener columnas: idruta, url_maps, estimado_personas (al menos idruta y url_maps).';
                    } else {

                        $pdo->beginTransaction();
                        $insertadas = 0;

                        try {

                            // Insert preparado con todos los campos de paradas
                            $sqlInsert = "
                                INSERT INTO paradas (
                                    idruta,
                                    punto_abordaje,
                                    hora_abordaje,
                                    hora_salida,
                                    url_coordenadas_maps,
                                    longitud,
                                    latitud,
                                    departamento,
                                    municipio,
                                    estimado_personas,
                                    atendido,
                                    orden
                                ) VALUES (
                                    :idruta,
                                    :punto_abordaje,
                                    :hora_abordaje,
                                    :hora_salida,
                                    :url_maps,
                                    :longitud,
                                    :latitud,
                                    :departamento,
                                    :municipio,
                                    :estimado_personas,
                                    0,
                                    :orden
                                )
                            ";
                            $stmtIns = $pdo->prepare($sqlInsert);

                            // Opcional: obtener destino de la ruta (por si lo quieres usar luego)
                            $stmtRuta = $pdo->prepare("SELECT destino FROM ruta WHERE idruta = :idruta");

                            $linea = 1;
                            while (($row = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
                                $linea++;

                                // Saltar filas totalmente vacías
                                if (count($row) === 1 && trim($row[0]) === '') {
                                    continue;
                                }

                                $idruta = isset($row[$idxIdruta]) ? (int)$row[$idxIdruta] : 0;
                                $url    = isset($row[$idxUrlMaps]) ? trim($row[$idxUrlMaps]) : '';
                                $estimadoTotal = null;

                                if ($idxEstimado !== false && isset($row[$idxEstimado]) && $row[$idxEstimado] !== '') {
                                    $estimadoTotal = (int)$row[$idxEstimado];
                                }

                                if ($idruta <= 0 || $url === '') {
                                    // puedes loguear en archivo si quieres
                                    continue;
                                }

                                // Obtener todos los puntos (lat,lng) desde el URL de Google Maps
                                // Se asume formato:
                                // https://www.google.com/maps/dir/lat1,lng1/lat2,lng2/.../@...
                                $points = [];
                                $posDir = strpos($url, '/dir/');
                                if ($posDir !== false) {
                                    $sub = substr($url, $posDir + 5); // después de "/dir/"
                                    $cutPos = strpos($sub, '/@');
                                    if ($cutPos === false) {
                                        $cutPos = strpos($sub, '?');
                                    }
                                    if ($cutPos !== false) {
                                        $sub = substr($sub, 0, $cutPos);
                                    }
                                    // $sub: "lat1,lng1/lat2,lng2/..."
                                    $segments = explode('/', $sub);
                                    foreach ($segments as $seg) {
                                        $seg = trim($seg);
                                        if (preg_match('/^-?\d+(\.\d+)?,-?\d+(\.\d+)?$/', $seg)) {
                                            list($lat, $lng) = explode(',', $seg);
                                            $points[] = [
                                                'lat' => (float)$lat,
                                                'lng' => (float)$lng,
                                            ];
                                        }
                                    }
                                }

                                if (empty($points)) {
                                    // No se logró parsear la URL en puntos válidos
                                    continue;
                                }

                                $nPuntos = count($points);

                                // Duraciones por tramo (Directions API)
                                $legsDur   = obtenerDuracionesPorTramo($points, $GOOGLE_API_KEY);
                                $horario   = construirHorarioParadas($points, $legsDur);
                                $estimados = distribuirEstimadoPersonas($estimadoTotal, $nPuntos);

                                // Opcional: buscar nombre del destino (no indispensable para los cálculos)
                                $destino = '';
                                $stmtRuta->execute([':idruta' => $idruta]);
                                if ($rowRuta = $stmtRuta->fetch(PDO::FETCH_ASSOC)) {
                                    $destino = $rowRuta['destino'] ?? '';
                                }

                                // Insertar una parada por cada punto
                                $orden = 1;
                                foreach ($points as $idx => $pt) {

                                    // Reverse Geocoding para depto/municipio
                                    list($departamento, $municipio) = obtenerDepartamentoMunicipio(
                                        $pt['lat'],
                                        $pt['lng'],
                                        $GOOGLE_API_KEY
                                    );

                                    // Horarios calculados
                                    $hora_abordaje = $horario[$idx]['hora_abordaje'] ?? null;
                                    $hora_salida   = $horario[$idx]['hora_salida']   ?? null;

                                    // Estimado de personas para esta parada
                                    $estimParada = $estimados[$idx] ?? 0;

                                    $stmtIns->execute([
                                        ':idruta'            => $idruta,
                                        ':punto_abordaje'    => 'Parada ' . $orden,
                                        ':hora_abordaje'     => $hora_abordaje,
                                        ':hora_salida'       => $hora_salida,
                                        ':url_maps'          => $url,
                                        ':longitud'          => $pt['lng'],
                                        ':latitud'           => $pt['lat'],
                                        ':departamento'      => $departamento,
                                        ':municipio'         => $municipio,
                                        ':estimado_personas' => $estimParada,
                                        ':orden'             => $orden,
                                    ]);

                                    $orden++;
                                    $insertadas++;
                                }
                            }

                            $pdo->commit();
                            $successMsg = "Paradas generadas correctamente. Registros insertados: {$insertadas}.";

                        } catch (Throwable $e) {
                            $pdo->rollBack();
                            $errorMsg = 'Error durante la carga: ' . $e->getMessage();
                        }

                    } // fin columnas válidas
                }     // fin header ok

                fclose($fh);
            }
        }
    }
}

// -----------------------------------------------------------
// Render HTML (AdminLTE)
// -----------------------------------------------------------
$pageTitle   = 'Carga masiva de paradas';
$currentPage = 'admin_carga_paradas';

require __DIR__ . '/../templates/header.php';
?>

<div class="content-wrapper">
  <section class="content-header">
    <div class="container-fluid">
      <div class="row mb-2">
        <div class="col-sm-6">
          <h1>Carga masiva de paradas</h1>
          <p class="text-muted mb-0">
            Sube un archivo CSV con <code>idruta</code>, <code>url_maps</code>, <code>estimado_personas</code>.
            Se usarán las coordenadas del URL de Google Maps y la API para calcular
            departamento, municipio y horarios (hora de abordaje / salida) para que
            la ruta llegue al destino a las <strong>11:00 AM</strong>.
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
          <h3 class="card-title">Subir CSV de rutas</h3>
        </div>
        <div class="card-body">

          <form method="post" enctype="multipart/form-data">
            <div class="form-group">
              <label for="csv_file">Archivo CSV</label>
              <input type="file" name="csv_file" id="csv_file" class="form-control-file" accept=".csv" required>
              <small class="form-text text-muted">
                Estructura esperada:
                <code>idruta,url_maps,estimado_personas</code>.<br>
                Ejemplo de URL:
                <code>https://www.google.com/maps/dir/lat1,lng1/lat2,lng2/.../@...</code><br>
                Todas las rutas se calcularán para llegar al destino a las <strong>11:00 AM</strong>.
              </small>
            </div>

            <button type="submit" class="btn btn-primary">
              Procesar CSV
            </button>
          </form>

        </div>
      </div>

    </div>
  </section>
</div>

<?php require __DIR__ . '/../templates/footer.php'; ?>
