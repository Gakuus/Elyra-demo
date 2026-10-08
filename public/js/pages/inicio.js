/**
 * pages/inicio.js — la portada "Central de operaciones".
 *
 * La página ya llega pintada por el servidor (DashboardController::datosInicio),
 * así que se ve completa sin JavaScript. Este archivo la mantiene en vivo: pone
 * el reloj y, cada pocos segundos, vuelve a consultar /api/mapa —el mismo
 * endpoint que usa el mapa— para refrescar los conteos, el tablero por estado,
 * la flota y el mini-mapa. No hay lógica de negocio acá: solo se pinta lo que
 * el servidor ya calculó.
 */
(function () {
    'use strict';

    var raiz = document.querySelector('.inicio');
    if (!raiz) return;

    var base = window.BASE_PATH || '';
    var urlMapa = base + '/api/mapa';

    var COLORES = {
        pendiente: '#8A94A3', en_curso: '#3FA34D',
        en_destino: '#E08A00', en_retorno: '#2F80D0'
    };

    function esc(txt) {
        var d = document.createElement('div');
        d.textContent = txt == null ? '' : String(txt);
        return d.innerHTML;
    }

    function aTiempo(ts) {
        if (!ts) return null;
        var t = Date.parse(String(ts).replace(' ', 'T'));
        return isNaN(t) ? null : t;
    }

    function haceCuanto(ts) {
        var t = aTiempo(ts);
        if (t === null) return '';
        var s = Math.max(0, Math.floor((Date.now() - t) / 1000));
        if (s < 60) return 'recién';
        var m = Math.floor(s / 60);
        if (m < 60) return 'hace ' + m + ' min';
        var h = Math.floor(m / 60);
        if (h < 24) return 'hace ' + h + ' h';
        return 'hace ' + Math.floor(h / 24) + ' d';
    }

    // Igual que DashboardController::marcaDeEtapa(): de cuándo data la etapa
    // en la que está el traslado ahora.
    function marcaDeEtapa(t) {
        switch (t.estado) {
            case 'en_curso':   return t.hora_salida_efectiva || t.created_at;
            case 'en_destino': return t.hora_llegada_estimada || t.created_at;
            case 'en_retorno': return t.hora_inicio_retorno || t.created_at;
            default:           return t.created_at;
        }
    }

    function iniciales(nombre) {
        var partes = String(nombre || '').trim().split(/\s+/).slice(0, 2);
        var ini = partes.map(function (p) { return p ? p.charAt(0).toUpperCase() : ''; }).join('');
        return ini || '?';
    }

    // ---------- Reloj ----------
    var reloj = document.getElementById('inicio-reloj');
    function tic() {
        if (!reloj) return;
        var d = new Date();
        reloj.textContent = ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2);
    }
    tic();
    setInterval(tic, 15000);

    // ---------- Mini-mapa ----------
    var mapa = null;
    var capa = null;
    var primeraVez = true;
    var contMini = document.getElementById('mini-mapa');

    if (contMini && typeof L !== 'undefined') {
        mapa = L.map(contMini, {
            zoomControl: false, attributionControl: false, scrollWheelZoom: false
        }).setView([-34.9011, -56.1645], 12);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19 }).addTo(mapa);
        L.control.attribution({ prefix: false }).addTo(mapa);
        capa = L.layerGroup().addTo(mapa);
        setTimeout(function () { mapa.invalidateSize(); }, 200);
    }

    function punto(lat, lng, color, etiqueta) {
        var m = L.circleMarker([lat, lng], {
            radius: 7, color: '#fff', weight: 2, fillColor: color, fillOpacity: 1
        });
        if (etiqueta) m.bindTooltip(etiqueta);
        m.addTo(capa);
        return [lat, lng];
    }

    function pintarMapa(datos) {
        if (!capa) return;
        capa.clearLayers();
        var puntos = [];

        (datos.conductores || []).forEach(function (c) {
            var color = c.sin_senal ? '#90A4AE' : (COLORES[c.traslado_estado] || '#3B5998');
            puntos.push(punto(c.latitud, c.longitud, color, c.conductor_nombre));
        });
        (datos.traslados || []).forEach(function (t) {
            if (t.destino_lat && t.destino_lng) {
                punto(t.destino_lat, t.destino_lng, '#E91E63', 'Destino ' + t.codigo);
            }
        });

        if (primeraVez && puntos.length) {
            mapa.fitBounds(puntos, { padding: [30, 30], maxZoom: 14 });
            primeraVez = false;
        }
    }

    // ---------- Tablero por estado ----------
    function tarjetaMini(t) {
        return '<article class="tarjeta-mini">' +
            '<div class="mini-cabecera">' +
                '<span class="tarjeta-codigo">' + esc(t.codigo) + '</span>' +
                '<span class="mini-tiempo">' + esc(haceCuanto(marcaDeEtapa(t))) + '</span>' +
            '</div>' +
            '<div class="mini-ruta">' + esc(t.origen) +
                ' <i class="bi bi-arrow-right"></i> ' + esc(t.destino) + '</div>' +
            '<div class="mini-pie">' +
                '<span><i class="bi bi-person"></i> ' + esc(t.conductor_nombre || 'Sin asignar') + '</span>' +
                '<span class="mini-vehiculo">' + esc(t.vehiculo_patente || '') + '</span>' +
            '</div>' +
        '</article>';
    }

    function pintarTablero(datos) {
        var grupos = { pendiente: [], en_curso: [], en_destino: [], en_retorno: [] };
        (datos.traslados || []).forEach(function (t) {
            if (grupos[t.estado]) grupos[t.estado].push(t);
        });

        Object.keys(grupos).forEach(function (estado) {
            var col = document.getElementById('col-' + estado);
            if (col) {
                col.innerHTML = grupos[estado].length
                    ? grupos[estado].map(tarjetaMini).join('')
                    : '<p class="columna-vacia">Sin traslados</p>';
            }
            var contador = document.querySelector('[data-contador="' + estado + '"]');
            if (contador) contador.textContent = grupos[estado].length;
        });
    }

    // ---------- Flota ----------
    function pintarFlota(datos) {
        var cont = document.getElementById('inicio-flota');
        if (!cont) return;

        var lista = datos.conductores || [];
        var enLinea = lista.filter(function (c) { return !c.sin_senal; }).length;
        var badge = document.getElementById('inicio-en-linea');
        if (badge) {
            var total = badge.textContent.split('/')[1] || '0';
            badge.textContent = enLinea + '/' + total;
        }

        if (!lista.length) {
            cont.innerHTML = '<p class="columna-vacia">Ningún conductor está reportando posición.</p>';
            return;
        }

        cont.innerHTML = lista.map(function (c) {
            var codigo = c.traslado_codigo || '';
            var detalle = c.sin_senal
                ? 'Sin señal · ' + haceCuanto(c.updated_at)
                : (codigo !== '' ? 'Llevando ' + codigo : 'Disponible · ' + haceCuanto(c.updated_at));

            return '<button type="button" class="flota-fila ' +
                    (c.sin_senal ? 'flota-sin-senal' : 'flota-en-linea') + '"' +
                    ' data-lat="' + c.latitud + '" data-lng="' + c.longitud + '">' +
                '<span class="flota-avatar">' + esc(iniciales(c.conductor_nombre)) + '</span>' +
                '<span class="flota-datos">' +
                    '<span class="flota-nombre">' + esc(c.conductor_nombre) + '</span>' +
                    '<span class="flota-detalle">' + esc(detalle) + '</span>' +
                '</span>' +
            '</button>';
        }).join('');
    }

    function refrescar() {
        fetch(urlMapa, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (datos) {
                if (!datos) return;
                pintarMapa(datos);
                pintarTablero(datos);
                pintarFlota(datos);
            })
            .catch(function () {
                // Sin conexión: se deja en pantalla lo último que llegó.
            });
    }

    refrescar();
    var timer = setInterval(refrescar, 10000);

    // En segundo plano no tiene sentido seguir consultando: se corta el poll y
    // se retoma (con un refresco inmediato) al volver a la pestaña.
    document.addEventListener('visibilitychange', function () {
        clearInterval(timer);
        if (!document.hidden) {
            refrescar();
        }
        timer = setInterval(refrescar, 10000);
    });

    // Clic en la flota: acerca el mini-mapa a ese conductor.
    var flotaCont = document.getElementById('inicio-flota');
    if (flotaCont) {
        flotaCont.addEventListener('click', function (e) {
            var fila = e.target.closest('.flota-fila');
            if (!fila || !mapa) return;
            var lat = parseFloat(fila.getAttribute('data-lat'));
            var lng = parseFloat(fila.getAttribute('data-lng'));
            if (!isNaN(lat) && !isNaN(lng)) {
                mapa.setView([lat, lng], 15);
            }
        });
    }
})();