<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';

if (!isset($_GET['idruta']) || empty($_GET['idruta'])) {
    die("Ruta no válida.");
}

$idruta = (int)$_GET['idruta'];

/* Obtener nombre de la ruta */
$sql_ruta = "SELECT nombre FROM ruta WHERE idruta = ?";
$stmt = $pdo->prepare($sql_ruta);
$stmt->execute([$idruta]);
$ruta = $stmt->fetchColumn();

if (!$ruta) {
    die("Ruta no encontrada.");
}

/* Obtener los reportes */
$sql = "
    SELECT 
        r.idreporte,
        r.fecha_reporte AS fecha,
        r.total_personas,
        r.comentario,
        a.nombre AS accion,
        p.punto_abordaje AS parada
    FROM reporte r
    LEFT JOIN acciones a ON a.idaccion = r.idaccion
    LEFT JOIN paradas p ON p.idparada = r.idparada
    WHERE r.idruta = ?
    ORDER BY r.fecha_reporte DESC, r.idreporte DESC
";
$stmt = $pdo->prepare($sql);
$stmt->execute([$idruta]);
$reportes = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* Datos para el layout */
$pageTitle   = "Reportes de ruta";
$currentPage = "monitoreo_listado"; // Para resaltar en el sidebar

require __DIR__ . '/../templates/header.php';
?>

<!-- Content Header -->
<section class="content-header">
  <div class="container-fluid">
    <div class="row mb-2">
      <div class="col-sm-8">
        <h1>Reportes para la Ruta: <strong><?= htmlspecialchars($ruta) ?></strong></h1>
      </div>
      <div class="col-sm-4 text-right">
        <a href="<?= BASE_URL ?>/monitoreo/index.php?idruta=<?= $idruta ?>" class="btn btn-sm btn-primary mt-2">
          <i class="fas fa-plus-circle mr-1"></i> Crear nuevo reporte
        </a>
        <button id="btnUbicacion" class="btn btn-sm btn-success mt-2">
          <i class="fas fa-map-marker-alt mr-1"></i> Guardar mi ubicación
        </button>
      </div>
    </div>
  </div>
</section>

<!-- Main content -->
<section class="content">
  <div class="container-fluid">

    <div class="row">
      <div class="col-12">
        <div class="card">

          <div class="card-header">
            <h3 class="card-title">Listado de reportes de la ruta</h3>
          </div>

          <div class="card-body table-responsive p-0">
            <?php if (empty($reportes)): ?>
              <div class="p-3">
                <em>No hay reportes registrados para esta ruta.</em>
              </div>
            <?php else: ?>
              <table class="table table-striped table-hover table-sm mb-0">
                <thead class="thead-light">
                  <tr>
                    <th>ID</th>
                    <th>Fecha</th>
                    <th>Parada</th>
                    <th>Acción</th>
                    <th>Personas</th>
                    <th>Comentario</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($reportes as $r): ?>
                    <tr>
                      <td><?= (int)$r['idreporte'] ?></td>
                      <td><?= htmlspecialchars($r['fecha']) ?></td>
                      <td><?= htmlspecialchars($r['parada'] ?? '') ?></td>
                      <td><?= htmlspecialchars($r['accion'] ?? '') ?></td>
                      <td><?= (int)$r['total_personas'] ?></td>
                      <td><?= htmlspecialchars($r['comentario'] ?? '') ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            <?php endif; ?>
          </div>

        </div>
      </div>
    </div>

  </div>
</section>

<script>
// Botón "Guardar mi ubicación"
document.addEventListener('DOMContentLoaded', function () {
  const btn = document.getElementById("btnUbicacion");
  if (!btn) return;

  btn.addEventListener("click", () => {
    if (!navigator.geolocation) {
      alert("La geolocalización no es soportada por este navegador.");
      return;
    }

    navigator.geolocation.getCurrentPosition(
      (pos) => {
        const lat = pos.coords.latitude;
        const lng = pos.coords.longitude;
        const idruta = <?= $idruta ?>;

        fetch("guardar_ubicacion.php", {
          method: "POST",
          headers: { "Content-Type": "application/x-www-form-urlencoded" },
          body: `lat=${encodeURIComponent(lat)}&lng=${encodeURIComponent(lng)}&idruta=${encodeURIComponent(idruta)}`
        })
        .then(r => r.json())
        .then(res => {
          if (res.success) {
            alert("Ubicación guardada correctamente.");
          } else {
            alert("Error al guardar ubicación: " + (res.msg || ''));
          }
        })
        .catch(() => alert("Error de comunicación con el servidor."));
      },
      (error) => {
        alert("No se pudo obtener la ubicación: " + error.message);
      }
    );
  });
});
</script>

<?php
require __DIR__ . '/../templates/footer.php';
?>
