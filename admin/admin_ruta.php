<?php
require_once __DIR__ . '/../includes/db.php';
$idruta = intval($_GET['id'] ?? 0);

$encargados = $pdo->query('SELECT idencargado_ruta, nombre FROM encargado_ruta')->fetchAll();
$buses = $pdo->query('SELECT idbus, placa, proveedor FROM bus')->fetchAll();
$agentes = $pdo->query('SELECT idagente, nombre FROM agente')->fetchAll();
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Admin Ruta</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"/>
<link rel="stylesheet" href="https://code.jquery.com/ui/1.13.2/themes/base/jquery-ui.css">
<style>#mapa{width:100%;height:380px;margin-bottom:10px;} .parada-row{cursor:grab}</style>
</head>
<body class="container py-3">
<h2>Administrar Ruta</h2>
<a href="admin_rutas_listado.php">← Volver al listado</a>

<div class="row mt-3">
  <div class="col-md-5">
    <form id="formRuta">
      <input type="hidden" id="idruta" value="<?= $idruta ?>">
      <div class="mb-2">
        <label>Nombre</label>
        <input type="text" id="nombreRuta" class="form-control" required>
      </div>
      <div class="mb-2">
        <label>Destino</label>
        <input type="text" id="destino" class="form-control" required>
      </div>
      <div class="mb-2">
        <label>Encargado</label>
        <select id="encargadoRuta" class="form-select" required>
          <option value="">Seleccione</option>
          <?php foreach($encargados as $e): ?>
            <option value="<?= $e['idencargado_ruta'] ?>"><?= htmlspecialchars($e['nombre']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="mb-2">
        <label>Bus</label>
        <select id="bus" class="form-select" required>
          <option value="">Seleccione</option>
          <?php foreach($buses as $b): ?>
            <option value="<?= $b['idbus'] ?>"><?= htmlspecialchars($b['placa'].' '.($b['proveedor']?:'')) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="mb-2">
        <label>Agente</label>
        <select id="agente" class="form-select" required>
          <option value="">Seleccione</option>
          <?php foreach($agentes as $a): ?>
            <option value="<?= $a['idagente'] ?>"><?= htmlspecialchars($a['nombre']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="d-flex gap-2 mt-2">
        <button id="btnGuardarRuta" class="btn btn-success" type="button">Guardar ruta</button>
        <button id="btnNuevo" class="btn btn-secondary" type="button">Nuevo</button>
      </div>
    </form>

    <hr>

    <div id="panelParadas" style="display:none;">
      <h5>Agregar / Editar parada</h5>
      <form id="formParada">
        <input type="hidden" id="idparada" value="0">
        <div class="mb-2"><label>Punto de abordaje</label><input type="text" id="punto" class="form-control" required></div>
        <div class="mb-2"><label>Hora abordaje</label><input type="time" id="horaAbordaje" class="form-control"></div>
        <div class="mb-2"><label>Hora salida</label><input type="time" id="horaSalida" class="form-control"></div>
        <div class="mb-2"><label>Departamento</label><input type="text" id="depto" class="form-control"></div>
        <div class="mb-2"><label>Municipio</label><input type="text" id="mun" class="form-control"></div>
        <div class="mb-2"><label>Estimado personas</label><input type="number" id="estimado" class="form-control" min="0"></div>
        <div class="mb-2"><label>Latitud</label><input type="text" id="lat" class="form-control" required></div>
        <div class="mb-2"><label>Longitud</label><input type="text" id="lng" class="form-control" required></div>
        <div id="mapa"></div>
        <div class="d-flex gap-2"><button id="btnAgregarParada" class="btn btn-primary" type="button">Agregar/Guardar parada</button>
        <button id="btnLimpiarParada" class="btn btn-secondary" type="button">Limpiar</button></div>
      </form>
    </div>

  </div>
  <div class="col-md-7">
    <div id="panelListadoParadas" style="display:none;">
      <h5>Paradas (arrastrar para reordenar)</h5>
      <table class="table table-sm"><thead><tr><th>Punto</th><th>Hora A</th><th>Hora S</th><th>Municipio</th><th>Estimado</th><th>Acc</th></tr></thead>
      <tbody id="tablaParadas"></tbody></table>
    </div>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://code.jquery.com/ui/1.13.2/jquery-ui.min.js"></script>
<!-- Google Maps API: reemplaza TU_API_KEY -->
<script src="https://maps.googleapis.com/maps/api/js?key=AIzaSyC2Zm7v7BAdKaA-KAvna0q4y0lQgwvE1V4&callback=inicializarMapa&libraries=marker" async defer></script>

<script>
let mapa;
let marcador = null;

// Inicializar mapa
function inicializarMapa() {
    const centro = { lat: 13.6929, lng: -89.2182 };

    mapa = new google.maps.Map(document.getElementById('mapa'), {
        zoom: 12,
        center: centro,
        mapId: 'TU_MAP_ID' // si no tienes, elimina esta línea
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
            .append("<td><button class='btn btn-sm btn-info btn-editar-parada' data-id='"+p.idparada+"'>Editar</button> <button class='btn btn-sm btn-danger btn-eliminar-parada' data-id='"+p.idparada+"'>Eliminar</button></td>");
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
        const idruta = $('#idruta').val(); if(!idruta||idruta==0){ alert('Guarde la ruta primero'); return; }
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
            if(res.success){ renderParadas(res.paradas); limpiarFormParada(); }
            else alert('Error: '+(res.error||''));
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
        if(!confirm('Eliminar?')) return;
        const id = $(this).data('id');
        $.post('ajax/delete_parada.php',{ idparada:id }, function(res){
            if(res.success){ $('tr[data-idparada="'+id+'"]').remove(); }
            else alert('Error al eliminar parada');
        }, 'json');
    });
});
</script>



</body>
</html>
