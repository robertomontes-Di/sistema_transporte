<?php
// admin/admin_ruta.php

ini_set('display_errors', 0);
error_reporting(0);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';

$idruta = intval($_GET['id'] ?? 0);

$encargados = $pdo->query('SELECT idencargado_ruta, nombre FROM encargado_ruta')->fetchAll();
$buses      = $pdo->query('SELECT idbus, placa, proveedor FROM bus')->fetchAll();
$agentes    = $pdo->query('SELECT idagente, nombre FROM agente')->fetchAll();

$pageTitle   = "Administrar Ruta";
$currentPage = "admin_rutas";

require __DIR__ . '/../templates/header.php';
?>

<!-- Estilos específicos de esta página -->
<style>
  #mapa {
    width: 100%;
    height: 380px;
    margin-bottom: 10px;
    border-radius: 8px;
  }
  .parada-row {
    cursor: grab;
  }
</style>

<!-- Content Wrapper -->
<div class="content-wrapper">

  <!-- Encabezado -->
  <section class="content-header">
    <div class="container-fluid">
      <div class="row mb-2">
        <div class="col-sm-6">
          <h1><?= $idruta ? 'Editar Ruta' : 'Crear Ruta' ?></h1>
          <p class="text-muted mb-0">
            Configura la información general de la ruta y sus paradas.
          </p>
        </div>
        <div class="col-sm-6 text-right">
          <a href="admin_rutas_listado.php" class="btn btn-secondary">
            <i class="fas fa-arrow-left mr-1"></i> Volver al listado
          </a>
        </div>
      </div>
    </div>
  </section>

  <!-- Contenido principal -->
  <section class="content">
    <div class="container-fluid">

      <div class="row">

        <!-- Columna izquierda: Datos de la ruta + formulario de parada -->
        <div class="col-md-5">

          <!-- Card Ruta -->
          <div class="card card-info">
            <div class="card-header">
              <h3 class="card-title">Datos generales de la ruta</h3>
            </div>
           <form id="formRuta" autocomplete="off">
              <input type="hidden" id="idruta" value="<?= (int)$idruta ?>">
              <div class="card-body">
              <!-- Datos básicos de la ruta -->
              <div class="mb-2">
                <label for="nombreRuta">Nombre de la ruta</label>
                <input
                  type="text"
                  class="form-control"
                  id="nombreRuta"
                  name="nombre"
                  placeholder="Ej. Ruta 01 San Salvador"
                  required
                >
              </div>

              <div class="mb-2">
                <label for="destinoRuta">Destino</label>
                <input
                  type="text"
                  class="form-control"
                  id="destinoRuta"
                  name="destino"
                  placeholder="Ej. Estadio Mágico González"
                  required
                >
              </div>

              <!-- Líder de ruta (encargado) -->
              <div class="mb-2">
                <label for="encargadoRuta">Encargado de ruta (Líder)</label>
                <select
                  class="form-control"
                  id="encargadoRuta"
                  name="idencargado_ruta"
                >
                  <option value="">— Sin líder asignado —</option>
                  <?php foreach ($encargados as $e): ?>
                    <option
                      value="<?= (int)$e['idencargado_ruta'] ?>"
                      data-telefono="<?= htmlspecialchars($e['telefono'] ?? '') ?>"
                    >
                      <?= htmlspecialchars($e['nombre']) ?>
                      <?php if (!empty($e['telefono'])): ?>
                        (<?= htmlspecialchars($e['telefono']) ?>)
                      <?php endif; ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <small class="form-text text-muted" id="ayudaClaveLider">
                  Selecciona un líder de ruta.  
                  La clave inicial para ingresar al módulo de reportes será su número de teléfono.
                  Luego podrás cambiarla en “Listado de rutas”.
                </small>
              </div>

              <!-- NUEVO: Líderes de grupo -->
              <div class="mb-2">
                <label for="lideresGrupo">Líder(es) de grupo</label>
                <input
                  type="text"
                  class="form-control"
                  id="lideresGrupo"
                  name="lideres_grupo"
                  placeholder="Ej. Juan Pérez, Ana López"
                >
                <small class="form-text text-muted">
                  Puedes ingresar uno o varios nombres de líderes de grupo separados por coma.
                </small>
              </div>

              <!-- NUEVO: Centro escolar -->
              <div class="mb-2">
                <label for="centroEscolar">Centro escolar al que pertenece</label>
                <input
                  type="text"
                  class="form-control"
                  id="centroEscolar"
                  name="centro_escolar"
                  placeholder="Nombre del centro escolar"
                >
              </div>

              <!-- NUEVO: Ubicación administrativa -->
              <div class="mb-2">
                <label for="departamento">Departamento (El Salvador)</label>
                <select
                  class="form-control"
                  id="departamento"
                  name="departamento"
                >
                  <option value="">— Seleccione —</option>
                  <option value="Ahuachapán">Ahuachapán</option>
                  <option value="Santa Ana">Santa Ana</option>
                  <option value="Sonsonate">Sonsonate</option>
                  <option value="Chalatenango">Chalatenango</option>
                  <option value="La Libertad">La Libertad</option>
                  <option value="San Salvador">San Salvador</option>
                  <option value="Cuscatlán">Cuscatlán</option>
                  <option value="La Paz">La Paz</option>
                  <option value="Cabañas">Cabañas</option>
                  <option value="San Vicente">San Vicente</option>
                  <option value="Usulután">Usulután</option>
                  <option value="San Miguel">San Miguel</option>
                  <option value="Morazán">Morazán</option>
                  <option value="La Unión">La Unión</option>
                </select>
              </div>

              <div class="mb-2">
                <label for="municipio">Municipio</label>
                <input
                  type="text"
                  class="form-control"
                  id="municipio"
                  name="municipio"
                  placeholder="Municipio"
                >
              </div>

              <!-- NUEVO: Link de Google Maps de la ruta -->
              <div class="mb-2">
                <label for="linkMapaRuta">Link de Google Maps de la ruta a seguir</label>
                <input
                  type="url"
                  class="form-control"
                  id="linkMapaRuta"
                  name="link_mapa_ruta"
                  placeholder="Pega aquí el enlace de Google Maps"
                >
                <small class="form-text text-muted">
                  Usa el enlace compartido de la ruta sugerida en Google Maps.
                </small>
              </div>

              <!-- NUEVO: Punto de abordaje y horarios -->
              <div class="mb-2">
                <label for="puntoAbordaje">Punto de abordaje</label>
                <input
                  type="text"
                  class="form-control"
                  id="puntoAbordaje"
                  name="punto_abordaje"
                  placeholder="Ej. Parque central del municipio"
                >
              </div>

              <div class="mb-2">
                <label for="horaAbordaje">Hora de abordaje</label>
                <input
                  type="time"
                  class="form-control"
                  id="horaAbordaje"
                  name="hora_abordaje"
                >
              </div>

              <div class="mb-2">
                <label for="horaSalidaEstadio">Hora de salida hacia el estadio</label>
                <input
                  type="time"
                  class="form-control"
                  id="horaSalidaEstadio"
                  name="hora_salida_estadio"
                >
              </div>

              <!-- NUEVO: Estimado de personas -->
              <div class="mb-2">
                <label for="estimadoPersonas">Estimado de personas</label>
                <input
                  type="number"
                  class="form-control"
                  id="estimadoPersonas"
                  name="estimado_personas"
                  min="0"
                  placeholder="Ej. 45"
                >
              </div>

              <!-- NUEVO: Ubicación geográfica (lat / lng) -->
              <div class="mb-2">
                <label>Ubicación geográfica del punto de abordaje</label>
                <div class="form-row">
                  <div class="col">
                    <input
                      type="text"
                      class="form-control"
                      id="latitud"
                      name="latitud"
                      placeholder="Latitud"
                    >
                  </div>
                  <div class="col">
                    <input
                      type="text"
                      class="form-control"
                      id="longitud"
                      name="longitud"
                      placeholder="Longitud"
                    >
                  </div>
                </div>
                <small class="form-text text-muted">
                  Puedes obtener estos datos desde Google Maps (clic derecho &gt; “¿Qué hay aquí?”).
                </small>
              </div>

              <!-- Bus y agente (igual que antes) -->
              <div class="mb-2">
                <label for="bus">Bus asignado (placa)</label>
                <select
                  class="form-control"
                  id="bus"
                  name="idbus"
                >
                  <option value="">— Sin bus asignado —</option>
                  <?php foreach ($buses as $b): ?>
                    <option value="<?= (int)$b['idbus'] ?>">
                      <?= htmlspecialchars($b['placa']) ?>
                      <?php if (!empty($b['proveedor'])): ?>
                        - <?= htmlspecialchars($b['proveedor']) ?>
                      <?php endif; ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="mb-3">
                <label for="agente">Agente DI responsable</label>
                <select
                  class="form-control"
                  id="agente"
                  name="idagente"
                >
                  <option value="">— Sin agente asignado —</option>
                  <?php foreach ($agentes as $a): ?>
                    <option value="<?= (int)$a['idagente'] ?>">
                      <?= htmlspecialchars($a['nombre']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <button type="button" class="btn btn-primary btn-block" id="btnGuardarRuta">
                Guardar ruta
              </button>
              </div>
            </form>
          </div>

          <!-- Card Paradas -->
          <div class="card card-outline card-primary" id="panelParadas" style="display:none;">
            <div class="card-header">
              <h3 class="card-title">Agregar / Editar parada</h3>
            </div>
            <form id="formParada">
              <div class="card-body">
                <input type="hidden" id="idparada" value="0">

                <div class="form-group">
                  <label for="punto">Punto de abordaje</label>
                  <input type="text" id="punto" class="form-control" required>
                </div>

                <div class="form-group">
                  <label for="horaAbordaje">Hora abordaje</label>
                  <input type="time" id="horaAbordaje" class="form-control">
                </div>

                <div class="form-group">
                  <label for="horaSalida">Hora salida</label>
                  <input type="time" id="horaSalida" class="form-control">
                </div>

                <div class="form-group">
                  <label for="depto">Departamento</label>
                  <input type="text" id="depto" class="form-control">
                </div>

                <div class="form-group">
                  <label for="mun">Municipio</label>
                  <input type="text" id="mun" class="form-control">
                </div>

                <div class="form-group">
                  <label for="estimado">Estimado personas</label>
                  <input type="number" id="estimado" class="form-control" min="0">
                </div>

                <div class="form-group">
                  <label>Ubicación geográfica</label>
                  <div class="row">
                    <div class="col-md-6 mb-2">
                      <input type="text" id="lat" class="form-control" placeholder="Latitud" required>
                    </div>
                    <div class="col-md-6 mb-2">
                      <input type="text" id="lng" class="form-control" placeholder="Longitud" required>
                    </div>
                  </div>
                  <small class="text-muted">
                    Haz clic en el mapa para seleccionar la ubicación de la parada.
                  </small>
                  <div id="mapa" class="mt-2"></div>
                </div>

              </div>
              <div class="card-footer d-flex justify-content-between">
                <div>
                  <button id="btnAgregarParada" class="btn btn-primary" type="button">
                    <i class="fas fa-map-marker-alt mr-1"></i> Agregar / Guardar parada
                  </button>
                  <button id="btnLimpiarParada" class="btn btn-secondary" type="button">
                    Limpiar
                  </button>
                </div>
              </div>
            </form>
          </div>

        </div><!-- /.col-md-5 -->

        <!-- Columna derecha: listado de paradas -->
        <div class="col-md-7">
          <div class="card" id="panelListadoParadas" style="display:none;">
            <div class="card-header">
              <h3 class="card-title">Paradas de la ruta</h3><br>
              <p class="mb-0 text-muted" style="font-size: 0.9rem;">
                Arrastre las filas para reordenar el recorrido.
              </p>
            </div>
            <div class="card-body p-0">
              <div class="table-responsive">
                <table class="table table-sm table-striped mb-0">
                  <thead class="thead-light">
                    <tr>
                      <th>Punto</th>
                      <th>Hora A</th>
                      <th>Hora S</th>
                      <th>Municipio</th>
                      <th>Estimado</th>
                      <th style="width:150px;">Acciones</th>
                    </tr>
                  </thead>
                  <tbody id="tablaParadas"></tbody>
                </table>
              </div>
            </div>
          </div>
        </div><!-- /.col-md-7 -->

      </div><!-- /.row -->

    </div><!-- /.container-fluid -->
  </section>

</div><!-- /.content-wrapper -->

<!-- Librerías específicas de esta página -->
<link rel="stylesheet" href="https://code.jquery.com/ui/1.13.2/themes/base/jquery-ui.css">

<!-- jQuery (si ya lo cargas en footer.php puedes quitar esta línea) -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

<script src="https://code.jquery.com/ui/1.13.2/jquery-ui.min.js"></script>

<!-- Google Maps API -->
<script src="https://maps.googleapis.com/maps/api/js?key=AIzaSyC2Zm7v7BAdKaA-KAvna0q4y0lQgwvE1V4&callback=inicializarMapa&libraries=marker" async defer></script>

<script>
let mapa;
let marcador = null;

// Inicializar mapa
function inicializarMapa() {
    const centro = { lat: 13.6929, lng: -89.2182 };

    mapa = new google.maps.Map(document.getElementById('mapa'), {
        zoom: 12,
        center: centro
        // mapId opcional, eliminar si no tienes uno configurado
        // mapId: 'TU_MAP_ID'
    });

    mapa.addListener('click', (e) => colocarMarcador(e.latLng));

    if ($('#idruta').val()) {
        cargarRutaSiAplica();
    }
}

// Colocar marcador y actualizar lat/lng/depto/mun
function colocarMarcador(location) {
    const lat = location.lat();
    const lng = location.lng();

    if (!marcador) {
        marcador = new google.maps.marker.AdvancedMarkerElement({
            map: mapa,
            position: location,
            draggable: true
        });
        marcador.addListener('dragend', () => {
            const pos = marcador.position;
            $('#lat').val(pos.lat());
            $('#lng').val(pos.lng());
            obtenerDeptoMun(pos.lat(), pos.lng());
        });
    } else {
        marcador.position = location;
    }

    $('#lat').val(lat);
    $('#lng').val(lng);
    obtenerDeptoMun(lat, lng);
}

// Obtener departamento y municipio usando Geocoder
function obtenerDeptoMun(lat, lng) {
    const geocoder = new google.maps.Geocoder();
    geocoder.geocode({ location: { lat: lat, lng: lng } }, (results, status) => {
        if (status === 'OK' && results[0]) {
            let depto = '', mun = '';
            results[0].address_components.forEach(comp => {
                if (comp.types.includes('administrative_area_level_1')) depto = comp.long_name;
                if (comp.types.includes('locality')) mun = comp.long_name;
            });
            $('#depto').val(depto);
            $('#mun').val(mun);
        }
    });
}

// Limpiar formulario de parada
function limpiarFormParada() {
    $('#formParada')[0].reset();
    $('#idparada').val(0);
    if (marcador) {
        marcador.setMap(null);
        marcador = null;
    }
}

// Renderizar la tabla de paradas
function renderParadas(paradas) {
    $('#tablaParadas').empty();
    paradas.forEach(p => {
        const tr = $("<tr class='parada-row' data-idparada='"+p.idparada+"'></tr>")
            .append('<td>'+p.punto_abordaje+'</td>')
            .append('<td>'+(p.hora_abordaje||'')+'</td>')
            .append('<td>'+(p.hora_salida||'')+'</td>')
            .append('<td>'+(p.municipio||'')+'</td>')
            .append('<td>'+(p.estimado_personas||'')+'</td>')
            .append(
              "<td>" +
              "<button class='btn btn-sm btn-info btn-editar-parada' data-id='"+p.idparada+"'><i class='fas fa-edit'></i></button> " +
              "<button class='btn btn-sm btn-danger btn-eliminar-parada' data-id='"+p.idparada+"'><i class='fas fa-trash'></i></button>" +
              "</td>"
            );
        $('#tablaParadas').append(tr);
    });

    $("#tablaParadas").sortable({
        items: 'tr',
        update: function() {
            let orden = [];
            $("#tablaParadas tr").each(function(){ orden.push($(this).data('idparada')); });
            $.post('ajax/ordenar_paradas.php', { orden: JSON.stringify(orden) }, function(resp){ }, 'json');
        }
    });
}

// Cargar ruta y paradas
function cargarRutaSiAplica() {
    const id = parseInt($('#idruta').val()) || 0;
    if (!id) return;

    $.getJSON('ajax/get_ruta.php', { idruta: id }, function(res) {
        if(res.success){
            $('#nombreRuta').val(res.ruta.nombre);
            $('#destino').val(res.ruta.destino);
            $('#encargadoRuta').val(res.ruta.idencargado_ruta);
            $('#bus').val(res.ruta.idbus);
            $('#agente').val(res.ruta.idagente);

            $('#panelParadas').show();
            $('#panelListadoParadas').show();

            renderParadas(res.paradas);

            if(res.paradas.length && res.paradas[0].latitud && res.paradas[0].longitud){
                const lat = parseFloat(res.paradas[0].latitud);
                const lng = parseFloat(res.paradas[0].longitud);
                mapa.setCenter({lat: lat, lng: lng});

                if(marcador){
                    marcador.position = {lat: lat, lng: lng};
                } else {
                    marcador = new google.maps.marker.AdvancedMarkerElement({
                        map: mapa,
                        position: {lat: lat, lng: lng},
                        draggable: true
                    });
                    marcador.addListener('dragend', () => {
                        const pos = marcador.position;
                        $('#lat').val(pos.lat());
                        $('#lng').val(pos.lng());
                        obtenerDeptoMun(pos.lat(), pos.lng());
                    });
                }
            }
        }
    });
}

// Document ready
$(function() {
    $('#btnLimpiarParada').on('click', limpiarFormParada);

    $('#btnAgregarParada').on('click', function(){
        const idruta = $('#idruta').val();
        if(!idruta || idruta == 0){
            alert('Guarde la ruta primero');
            return;
        }
        const payload = {
            idparada: $('#idparada').val()||0,
            idruta: idruta,
            punto: $('#punto').val().trim(),
            horaAbordaje: $('#horaAbordaje').val()||null,
            horaSalida: $('#horaSalida').val()||null,
            lat: $('#lat').val().trim(),
            lng: $('#lng').val().trim(),
            depto: $('#depto').val().trim(),
            mun: $('#mun').val().trim(),
            estimado: $('#estimado').val()||0
        };
        if(!payload.punto){ alert('Ingrese punto'); return; }
        if(!payload.lat || !payload.lng){ alert('Seleccione ubicación en el mapa'); return; }

        $.post('ajax/save_parada.php', payload, function(res){
            if(res.success){
                renderParadas(res.paradas);
                limpiarFormParada();
            } else {
                alert('Error: '+(res.error||''));
            }
        }, 'json');
    });

    // Editar y eliminar
    $(document).on('click', '.btn-editar-parada', function(){
        const id = $(this).data('id');
        $.getJSON('ajax/get_parada.php', { idparada: id }, function(res){
            if(res.success){
                const p = res.parada;
                $('#idparada').val(p.idparada);
                $('#punto').val(p.punto_abordaje);
                $('#horaAbordaje').val(p.hora_abordaje);
                $('#horaSalida').val(p.hora_salida);
                $('#depto').val(p.departamento);
                $('#mun').val(p.municipio);
                $('#estimado').val(p.estimado_personas);
                $('#lat').val(p.latitud);
                $('#lng').val(p.longitud);

                $('#panelParadas').show();
                $('#panelListadoParadas').show();

                if(p.latitud && p.longitud){
                    const lat = parseFloat(p.latitud);
                    const lng = parseFloat(p.longitud);
                    mapa.setCenter({lat:lat, lng:lng});
                    if(marcador) marcador.position = {lat:lat, lng:lng};
                    else colocarMarcador({lat: lat, lng: lng});
                }
            }
        });
    });

    $(document).on('click', '.btn-eliminar-parada', function(){
        if(!confirm('¿Eliminar esta parada?')) return;
        const id = $(this).data('id');
        $.post('ajax/delete_parada.php',{ idparada:id }, function(res){
            if(res.success){
                $('tr[data-idparada="'+id+'"]').remove();
            } else {
                alert('Error al eliminar parada');
            }
        }, 'json');
    });

    // Si viene idruta en la URL, cargar datos
    if (<?= $idruta ?> > 0) {
        $('#panelParadas').show();
        $('#panelListadoParadas').show();
    }
});
document.addEventListener('DOMContentLoaded', function () {
  const selEncargado = document.getElementById('encargadoRuta');
  const ayudaClave   = document.getElementById('ayudaClaveLider');

  if (!selEncargado || !ayudaClave) return;

  function actualizarTextoClave() {
    const opt = selEncargado.options[selEncargado.selectedIndex];
    const tel = opt ? (opt.getAttribute('data-telefono') || '') : '';

    if (tel) {
      ayudaClave.textContent =
        'La clave actual del líder será su número de teléfono: ' +
        tel +
        '. Esta clave se puede cambiar desde “Listado de rutas”.';
    } else {
      ayudaClave.textContent =
        'Selecciona un líder de ruta. La clave inicial para ingresar al módulo de reportes será su número de teléfono. Luego podrás cambiarla en “Listado de rutas”.';
    }
  }

  selEncargado.addEventListener('change', actualizarTextoClave);
  actualizarTextoClave();
});
</script>

<?php
require __DIR__ . '/../templates/footer.php';
