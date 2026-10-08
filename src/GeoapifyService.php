<?php

declare(strict_types=1);

/**
 * GeoapifyService.php: proveedor de mapas y rutas de Geoapify.
 *
 * Es un drop-in de los servicios que ya tenía el proyecto (RutaService con
 * OSRM y GeocodeService con Nominatim): devuelve exactamente las mismas
 * estructuras, de modo que el resto de la aplicación no se entera de qué
 * proveedor está detrás.
 *
 * Qué aporta sobre los anteriores, medido contra la API real:
 *
 *   - Routing con instrucciones giro a giro (`lang=es` las devuelve en
 *     español), que OSRM no daba porque se llamaba con steps=false.
 *   - Autocomplete: permite responder mientras el usuario tipea, sin el
 *     throttling de 1 req/s que exige Nominatim.
 *   - Isoline (área de cobertura isócrona). La API la responde, pero en el
 *     plan gratis devuelve un polígono de 5 puntos, inservible como mapa de
 *     cobertura: por eso no está implementado acá.
 *
 * Lo que el plan actual NO tiene (devuelve 404 y por eso no se implementa):
 * Places, Route Matrix y Map Matching.
 *
 * Detalles del contrato con la API, que no son evidentes y costaron pruebas:
 *   - Los waypoints van como `lat,lon|lat,lon`: separador `|`, NO `;`, y en
 *     ese orden. Con `;` la API responde "waypoints[0] does not match any of
 *     the allowed types".
 *   - El modo válido es `drive`, no `car` (los demás: walk, hike, scooter,
 *     motorcycle, truck).
 *   - La respuesta es `results[0]`, no `routes[0]`, y la geometría vive en
 *     `results[0].geometry[0]` como lista de objetos {lon, lat}.
 *   - El país sí se filtra en la API con `filter=countrycode:uy`. Sacarlo
 *     parece más completo pero no lo es: sin filtro, "Italia" devuelve Italia
 *     e Italia de Argentina y "Barrio La Palma" devuelve México, que para un
 *     hospital de Montevideo son resultados directamente inútiles.
 *     Lo que sí estaba recortando la lista era el `limit`: se pedían 8 y con
 *     el hospital de fondo las calles quedaban en 5. Por eso ahora se piden 20
 *     y se muestran los 8 mejores, y el filtro por país se aplica además del
 *     lado del cliente como red de seguridad.
 */
final class GeoapifyService
{
    private const BASE_URL = 'https://api.geoapify.com/v1/';

    private const CACHE_DIR = __DIR__ . '/../storage/geoapify-cache';

    /** Geoapify es un servicio comercial con cuota: la caché la cuida. */
    private const CACHE_TTL = 86400 * 30;
    private const CACHE_TTL_SIN_RESULTADOS = 600;

    private const TIMEOUT_SEGUNDOS = 12;

    /**
     * Círculo de búsqueda para las consultas "cerca de mí": cuántos metros a
     * la redonda se consultan además de la búsqueda global. `bias=proximity`
     * acerca los resultados pero no alcanza (ver sugerencias()).
     */
    private const RADIO_CERCANIA_M = 50000;

    /**
     * País donde se opera. No se usa como filtro de la API (ver la nota de la
     * cabecera): se usa para empujar hacia arriba los resultados de acá.
     */
    private const PAIS = 'uy';

    /** Se piden más resultados de los que se muestran, para tener de dónde elegir. */
    private const LIMITE_PEDIDO = 20;
    private const IDIOMA = 'es';

    /** Modo de viaje: 'car' no existe en la API y devuelve 400. */
    private const MODO = 'drive';

    /**
     * Espera antes de volver a pegarle a la API después de un fallo (cuota
     * agotada, red caída, clave vencida). Sin esto, cada ruta dibujada
     * repetía la llamada fallida y tardaba lo mismo que un timeout real.
     * Cuando la API vuelve,Geoapify se reintenta solo pasado este plazo.
     */
    private const ESPERA_TRAS_FALLO_S = 300;

    /** ¿Hay clave cargada? Sin ella el sistema sigue con OSRM/Nominatim. */
    public static function habilitado(): bool
    {
        return trim((string) ($_ENV['GEOAPIFY_API_KEY'] ?? '')) !== '';
    }

    /**
     * ¿Geoapify es el proveedor preferido (activo)?
     *
     * Con GEOPROVIDER=geoapify se lo intenta primero y se cae a OSRM/Nominatim
     * si algo falla. Sin esa variable (o con cualquier otro valor) queda el
     * comportamiento de siempre, sin tocar una sola llamada externa.
     */
    public static function preferido(): bool
    {
        return self::habilitado()
            && strtolower(trim((string) ($_ENV['GEOPROVIDER'] ?? ''))) === 'geoapify';
    }

    // ------------------------------------------------------------------
    // Rutas
    // ------------------------------------------------------------------

    /**
     * Ruta por carretera entre dos puntos.
     *
     * Devuelve la misma estructura que RutaService y, además, `pasos` con las
     * instrucciones giro a giro para mostrarle al chofer qué hacer en la
     * próxima esquina.
     *
     * @return array{coordinates: array<int, array{0: float, 1: float}>,
     *               distance_km: float, duration_min: float,
     *               pasos?: array<int, array{texto: string, distancia_m: float}>}|null
     */
    public static function ruta(float $origenLat, float $origenLng, float $destinoLat, float $destinoLng): ?array
    {
        $clave = self::claveCache('ruta', [$origenLat, $origenLng, $destinoLat, $destinoLng]);

        $cache = self::leerCache($clave);
        if ($cache !== null) {
            return $cache;
        }

        $waypoints = sprintf(
            '%s,%s|%s,%s',
            $origenLat,
            $origenLng,
            $destinoLat,
            $destinoLng
        );

        $datos = self::consultar('routing', [
            'waypoints' => $waypoints,
            'mode'      => self::MODO,
            'lang'      => self::IDIOMA,
            'geometry'  => 'geojson',
        ]);

        // Con geometry=geojson la respuesta es una FeatureCollection y la ruta
        // vive en features[0]: properties (distance/time/legs) y geometry
        // (MultiLineString). Se verificó contra la API real.
        $feature = is_array($datos) ? ($datos['features'][0] ?? null) : null;
        if (!is_array($feature)) {
            return null;
        }

        $props = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
        $coordenadas = self::aCoordenadas($feature['geometry'] ?? null);

        if (count($coordenadas) < 2) {
            return null;
        }

        $ruta = [
            'coordinates'  => $coordenadas,
            'distance_km'  => round(self::aFloat($props['distance'] ?? null) / 1000, 2),
            'duration_min' => round(self::aFloat($props['time'] ?? null) / 60, 1),
        ];

        $pasos = self::leerPasos($props);
        if ($pasos !== []) {
            $ruta['pasos'] = $pasos;
        }

        self::escribirCache($clave, $ruta);

        return $ruta;
    }

    /**
     * Normaliza la geometría GeoJSON a una lista [lat, lng].
     *
     * La API responde MultiLineString (coordinates[0] es el trazado) pero
     * admite LineString y Point suelto, así que se baja un nivel solo cuando
     * hace falta y se aceptan puntos como [lon, lat] o {lon, lat}.
     */
    private static function aCoordenadas(mixed $geometry): array
    {
        $coords = is_array($geometry) ? ($geometry['coordinates'] ?? null) : null;
        if (!is_array($coords) || $coords === []) {
            return [];
        }

        // Si el primer elemento es otra lista de puntos, bajamos un nivel.
        if (is_array($coords[0] ?? null) && is_array($coords[0][0] ?? null)) {
            $coords = $coords[0];
        }

        $coordenadas = [];
        foreach ($coords as $punto) {
            $p = (array) $punto;
            $lat = self::aFloat($p['lat'] ?? ($p[1] ?? null));
            $lng = self::aFloat($p['lon'] ?? ($p[0] ?? null));
            if ($lat === null || $lng === null) {
                continue;
            }
            $coordenadas[] = [$lat, $lng];
        }

        return $coordenadas;
    }

    /**
     * Instrucciones giro a giro. Geoapify las reparte por tramo (legs) y cada
     * paso trae el texto ya traducido por `lang`.
     *
     * @return array<int, array{texto: string, distancia_m: float}>
     */
    private static function leerPasos(array $props): array
    {
        $pasos = [];
        foreach ((array)($props['legs'] ?? []) as $tramo) {
            if (!is_array($tramo)) continue;
            foreach ((array)($tramo['steps'] ?? []) as $paso) {
                if (!is_array($paso)) continue;
                $texto = trim((string) ($paso['instruction']['text'] ?? ($paso['instruction'] ?? '')));
                if ($texto === '') continue;
                $pasos[] = [
                    'texto'       => $texto,
                    'distancia_m' => (float) self::aFloat($paso['distance'] ?? null),
                ];
            }
        }
        return $pasos;
    }

    // ------------------------------------------------------------------
    // Geocodificación
    // ------------------------------------------------------------------

    /**
     * Búsqueda de lugares por texto. Mismo formato que GeocodeService::buscar.
     *
     * @return array<int, array{label: string, lat: float, lng: float, categoria: string}>
     */
    public static function buscar(string $texto, int $limite = 8, ?float $proxLat = null, ?float $proxLng = null): array
    {
        $datos = self::consultar('geocode/search', self::paramsConsulta($texto, $proxLat, $proxLng));

        return self::aSugerencias($datos, $limite, $texto);
    }

    /**
     * Autocomplete: a diferencia de buscar(), acepta textos de 1 carácter y
     * ordena por lo que el usuario está escribiendo. Es lo que permite que el
     * desplegable de destinos responda mientras se tipea.
     *
     * @return array<int, array{label: string, lat: float, lng: float, categoria: string}>
     */
    public static function autocompletar(string $texto, int $limite = 8, ?float $proxLat = null, ?float $proxLng = null): array
    {
        $datos = self::consultar('geocode/autocomplete', self::paramsConsulta($texto, $proxLat, $proxLng));

        return self::aSugerencias($datos, $limite, $texto);
    }

    /**
     * Consulta combinada para el desplegable de destinos: pega autocomplete y
     * search y, si hay proximidad, agrega una tercera consulta restringida al
     * círculo cercano.
     *
     * Sin el círculo, "Hospital" cerca de Paysandú devolvía los veinte
     * hospitales más importantes del país y el de Paysandú (a 655 m) no
     * aparecía: `bias=proximity` acerca pero no alcanza a cambiar el ranking.
     *
     * La API manda `distance` desde el punto de bias, pero en la consulta con
     * círculo lo manda desde otro origen y no sirve. Acá se recalcula siempre
     * con haversine cuando hay proximidad, para que el orden y la distancia
     * que ve el usuario digan la verdad.
     *
     * @return array<int, array{label: string, lat: float, lng: float, categoria: string}>
     */
    public static function sugerencias(string $texto, int $limite = 8, ?float $proxLat = null, ?float $proxLng = null): array
    {
        $resultados = [];

        // Las dos consultas globales (con su caché y su cuenta atrás) y, con
        // proximidad, la del círculo cercano. Autocomplete y search trayendo
        // listas distintas de tramos y calles: se combinan como antes.
        foreach (['geocode/autocomplete', 'geocode/search'] as $ruta) {
            $datos = self::consultar($ruta, self::paramsConsulta($texto, $proxLat, $proxLng));
            if (is_array($datos)) {
                $resultados = array_merge($resultados, (array) ($datos['results'] ?? []));
            }
        }

        if ($proxLat !== null && $proxLng !== null) {
            $datos = self::consultar(
                'geocode/autocomplete',
                self::paramsConsulta($texto, $proxLat, $proxLng, self::RADIO_CERCANIA_M)
            );
            if (is_array($datos)) {
                $resultados = array_merge($resultados, (array) ($datos['results'] ?? []));
            }
        }

        if ($proxLat !== null && $proxLng !== null) {
            foreach ($resultados as &$fila) {
                $lat = self::aFloat($fila['lat'] ?? null);
                $lng = self::aFloat($fila['lon'] ?? null);
                if ($lat !== null && $lng !== null) {
                    $fila['distance'] = self::distanciaMetros($proxLat, $proxLng, $lat, $lng);
                }
            }
            unset($fila);
        }

        return self::aSugerencias(['results' => $resultados], $limite, $texto);
    }

    /**
     * Parámetros comunes de una consulta de geocodificación.
     *
     * Con un radio (en metros) la búsqueda se restringe a un círculo
     * alrededor de la proximidad y se deja de lado el filtro por país: la API
     * no combina `circle` con `countrycode` (devuelve vacío). El Uruguay se
     * resuelve después, en el orden de resultados y en GeocodeService.
     *
     * Sin radio: filtro por país y bias de proximidad si vino.
     *
     * @return array<string, string|int>
     */
    private static function paramsConsulta(string $texto, ?float $proxLat, ?float $proxLng, ?int $radio = null): array
    {
        $params = [
            'text'   => $texto,
            'lang'   => self::IDIOMA,
            'limit'  => self::LIMITE_PEDIDO,
            'format' => 'json',
        ];

        if ($radio !== null && $proxLat !== null && $proxLng !== null) {
            $params['filter'] = 'circle:' . $proxLng . ',' . $proxLat . ',' . $radio;
        } else {
            $params['filter'] = 'countrycode:' . self::PAIS;
            if ($proxLat !== null && $proxLng !== null) {
                $params['bias'] = 'proximity:' . $proxLng . ',' . $proxLat;
            }
        }

        return $params;
    }

    /**
     * Distancia entre dos coordenadas en metros, para ordenar y mostrar
     * cuán lejos queda cada resultado de la ubicación actual.
     */
    private static function distanciaMetros(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $radioTierra = 6371000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * $radioTierra * asin(sqrt($a));
    }

    /**
     * Dirección de un punto. Mismo formato que GeocodeService::direccionDe.
     *
     * @return array{label: string, lat: float, lng: float}|null
     */
    public static function direccionDe(float $lat, float $lng): ?array
    {
        $datos = self::consultar('geocode/reverse', [
            'lat'    => $lat,
            'lon'    => $lng,
            'lang'   => self::IDIOMA,
            'format' => 'json',
        ]);

        $sugerencias = self::aSugerencias($datos, 1, '');

        return $sugerencias[0] ?? null;
    }

    /**
     * La caja del resultado tal como la manda Geoapify
     * ([sLng, sLat, nLng, nLat]), o null si no vino o está incompleta.
     *
     * @return array<int, float>|null
     */
    private static function cajaDe(array $fila): ?array
    {
        $bbox = $fila['bbox'] ?? null;
        if (!is_array($bbox) || count($bbox) !== 4) {
            return null;
        }

        $caja = [];
        foreach ($bbox as $v) {
            $n = self::aFloat($v);
            if ($n === null) {
                return null;
            }
            $caja[] = $n;
        }

        return $caja;
    }

    /**
     * Normaliza los resultados de los tres endpoints de geocodificación, que
     * comparten forma (`results[]` con `lat`, `lon`, `formatted`).
     *
     * @return array<int, array{label: string, lat: float, lng: float, categoria: string}>
     */
    private static function aSugerencias(mixed $datos, int $limite, string $consulta = ''): array
    {
        if (!is_array($datos)) {
            return [];
        }

        $vistas = [];
        $consultaEsLugar = self::consultaEsLugar($consulta);
        $candidatas = [];

        foreach ((array) ($datos['results'] ?? []) as $fila) {
            if (!is_array($fila)) {
                continue;
            }
            $lat = self::aFloat($fila['lat'] ?? null);
            $lng = self::aFloat($fila['lon'] ?? null);
            $label = trim((string) ($fila['formatted'] ?? ''));
            if ($lat === null || $lng === null || $label === '') {
                continue;
            }

            // Un mismo lugar puede venir repetido con distinto postcode o
            // casing ("Hospital de Clinicas" vs "Hospital de Clínicas"), y en
            // el desplegable aparece dos veces. Se descarta el duplicado por
            // place_id cuando existe y, si no, por coordenada redondeada.
            $clave = trim((string) ($fila['place_id'] ?? ''));
            if ($clave === '') {
                $clave = sprintf('%.4f:%.4f', $lat, $lng) . '|' . self::claveLabel($label);
            }
            if (isset($vistas[$clave])) {
                continue;
            }
            $vistas[$clave] = true;

            // `category` es la etiqueta fina de OSM (healthcare.hospital) y
            // `result_type` la gruesa (street, city, building). Sirve más la
            // fina para el tipo, pero la gruesa es la que dice si esto es una
            // localidad o una calle, y eso decide el orden.
            $categoria = trim((string) ($fila['category'] ?? ''));
            $tipoResultado = trim((string) ($fila['result_type'] ?? ''));
            if ($categoria === '') {
                $categoria = $tipoResultado;
            }

            $esLugar = isset(self::TIPOS_LUGAR[$tipoResultado]);
            $esUruguay = self::esUruguay($fila, $lat, $lng);

            $relevancia = self::relevancia($label, $consulta);

            // Se prioriza estar en Uruguay, pero NO se excluye lo demás: el
            // filtro por país en la API vaciaba el desplegable de calles y
            // barrios, que es lo que más se escribe.
            if ($esUruguay) {
                $relevancia += 12;
            }

            if ($consultaEsLugar && $esLugar) {
                // Solo cuando la persona escribió "ciudad de X" o "barrio X".
                // No se hunde a las calles: la localidad sube, y las calles que
                // se llamen igual quedan abajo pero a la vista.
                $relevancia += 35;
            }

            // Al elegir un destino casi siempre se busca una DIRECCIÓN, no un
            // comercio. Escribiendo "italia" salían "Estacionamiento Disco
            // Av. Italia" y "Embajada de Italia" por encima de la avenida
            // misma. Las calles suben apenas; los comercios quedan igual, que
            // para el hospital "Clínica Suizo Americana" tiene que servir.
            if (!$consultaEsLugar && in_array($tipoResultado, ['street', 'place'], true)) {
                $relevancia += 8;
            }

            // Empuje extra cuando hay bias de proximidad: lo que está más cerca
            // debería aparecer antes cuando no hay otro criterio mucho más fuerte.
            $dist = self::aFloat($fila['distance'] ?? null);
            if ($dist !== null && $dist < 2000) {
                $relevancia += 5;
            } elseif ($dist !== null && $dist < 5000) {
                $relevancia += 2;
            }

            $candidatas[] = [
                // La API manda `distance` en metros cuando se le pasó bias.
                'distancia' => $dist,
                'relevancia' => max(0, $relevancia),
                'sugerencia' => [
                    'label'     => $label,
                    'lat'       => $lat,
                    'lng'       => $lng,
                    'categoria' => $categoria,
                    'tipo'      => self::tipoLegible($categoria) ?: ($esLugar ? 'Ciudad' : ''),
                    'es_lugar'  => $esLugar,
                    'distancia' => $dist,
                    'place_id'  => trim((string) ($fila['place_id'] ?? '')),
                    // Se viajan para que el filtro de país se aplique del lado
                    // del cliente: la API ya no lo hace.
                    'pais'        => strtolower(trim((string) ($fila['country_code'] ?? ''))),
                    'pais_nombre' => trim((string) ($fila['country'] ?? '')),
                    // `category` y `result_type` no son lo mismo: Salto, por
                    // ejemplo, viene como category=administrative y
                    // result_type=city. Quien necesite saber si esto es una
                    // localidad y no una calle tiene que mirar el segundo.
                    'result_type' => $tipoResultado,
                    // Geoapify manda la caja del resultado como
                    // [sLng, sLat, nLng, nLat]. La necesita
                    // InterseccionService para buscar las dos calles de un
                    // cruce dentro de la ciudad, así que viaja intacta.
                    'bbox'        => self::cajaDe($fila),
                ],
            ];
        }

        // Orden: primero lo que realmente coincide con lo escrito, después la
        // cercanía. Antes era solo por distancia, y daba para elegir un lugar
        // con otro nombre pero más cerca que el que el usuario escribió.
        $index = 0;
        foreach ($candidatas as $i => $c) {
            $candidatas[$i]['_i'] = $index++;
        }
        usort($candidatas, static function (array $a, array $b): int {
            if ($a['relevancia'] !== $b['relevancia']) {
                return $b['relevancia'] <=> $a['relevancia'];
            }

            $da = $a['distancia'] ?? PHP_FLOAT_MAX;
            $db = $b['distancia'] ?? PHP_FLOAT_MAX;

            return $da === $db ? $a['_i'] <=> $b['_i'] : $da <=> $db;
        });

        return array_slice(array_column($candidatas, 'sugerencia'), 0, $limite);
    }

    /**
     * Si un resultado está en Uruguay.
     *
     * Antes esto era `filter=countrycode:uy` en la API, que se comía las calles
     * y los barrios: "Tres Cruces" daba 1 resultado con filtro y 12 sin él.
     * Así que ahora el país no filtra nada, se piden más resultados y el
     * Uruguay se prioriza acá en el orden.
     *
     * Primero se mira `country_code`, que es lo que manda la API. Si no viene
     * (pasa con algunos puntos), se cae a una caja aproximada del país: es
     * bastante más ancha que Uruguay real, así que el empujón al Uruguay puede
     * ser para Punta del Este, pero esos lugares son los que alguien quiere
     * igual si los escribió.
     */
    private static function esUruguay(array $fila, ?float $lat, ?float $lng): bool
    {
        $codigo = strtolower(trim((string) ($fila['country_code'] ?? '')));
        if ($codigo !== '') {
            return $codigo === self::PAIS;
        }

        $pais = strtolower(trim((string) ($fila['country'] ?? '')));
        if ($pais !== '') {
            return strpos($pais, 'uruguay') !== false;
        }

        if ($lat === null || $lng === null) {
            return false;
        }

        return $lat >= -35.5 && $lat <= -30.0 && $lng >= -58.8 && $lng <= -53.0;
    }

    /**
     * Cuánto coincide el nombre del lugar con lo que el usuario escribió.
     *
     * La cercanía no alcanza para ordenar: en un hospital todos los destinos
     * están a metros y lo que se está eligiendo es *qué* lugar, no cuál está
     * más cerca. Un lugar que arranca exactamente con el texto va primero; uno
     * que solo lo contiene, después; uno que no lo contiene, al final.
     *
     * El texto se separa en palabras conservando los espacios: colapsarlos
     * ("ciudad de paysandu" -> "ciudaddepaysandu") dejaba toda consulta de dos o
     * más palabras con puntaje 0 y el orden terminaba siendo pura cercanía,
     * con el resultado más cercano ganando siempre.
     */
    private static function relevancia(string $label, string $consulta, bool $consultaEsLugar = false): int
    {
        $etiqueta = self::claveLabel($label);
        $palabras = self::palabras($consulta);
        if ($palabras === []) {
            return $etiqueta === '' ? 0 : 1;
        }
        if ($etiqueta === '') {
            return 0;
        }

        // Para el arranque y el "contiene" hace falta el texto CON espacios,
        // porque los límites de palabra son los que dicen si "italia" está en
        // "Avenida Italia" o solamente metido dentro de "Casa degli Italiani".
        $etiquetaEspaciada = mb_strtolower(self::sinAcentos($label));
        $etiquetaEspaciada = (string) preg_replace('/\s+/u', ' ', trim($etiquetaEspaciada));
        $consultaEspaciada = mb_strtolower(self::sinAcentos($consulta));
        $consultaEspaciada = (string) preg_replace('/\s+/u', ' ', trim($consultaEspaciada));

        if ($consultaEspaciada !== '' && $etiquetaEspaciada !== '') {
            if ($etiquetaEspaciada === $consultaEspaciada) {
                return 100;
            }

            $limite = preg_quote($consultaEspaciada, '/');

            // Empieza con la consulta, y el corte cae justo en una palabra: "italia"
            // arranca en "Avenida Italia" pero no en "Italiani".
            if (preg_match('/^' . $limite . '(?![\p{L}\p{N}])/u', $etiquetaEspaciada) === 1) {
                return 80;
            }

            // Aparece completa en algún punto del texto, también con cortes por
            // palabra: "Casa degli Italiani" deja de ganarle a una calle.
            if (preg_match('/(?<![\p{L}\p{N}])' . $limite . '(?![\p{L}\p{N}])/u', $etiquetaEspaciada) === 1) {
                return 60;
            }
        }

        // Puntaje por palabras acertadas. Las palabras de a dos letras se
        // ignoran ("de", "la"): aparecen en todos lados y no distinguen nada.
        //
        // La comparación es por palabra completa, no por pedazo de texto. Con
        // `str_contains`, buscar "italia" le daba 40 puntos a "Casa degli
        // Italiani" porque "italiani" contiene "italia", y esa casa le ganaba
        // a la Avenida Italia de verdad. Son palabras distintas.
        $palabrasEtiqueta = self::palabras($label);
        $acertadas = [];
        foreach ($palabras as $palabra) {
            $normal = self::claveLabel($palabra);
            if ($normal === '') {
                continue;
            }
            if (in_array($normal, $palabrasEtiqueta, true)) {
                $acertadas[$palabra] = true;
            }
        }

        $puntaje = 40 * count($acertadas) / count($palabras);

        // Todas las palabras del texto están en el lugar: "Paysandú, Uruguay"
        // tiene que ganarle a "Paysandú, 11100 Montevideo, Uruguay" para
        // "ciudad de paysandu".
        if (count($acertadas) === count($palabras)) {
            $puntaje += 25;
        }

        return (int) round($puntaje);
    }

    /**
     /**
     * Texto partido en palabras Significantemente, sin acentos ni puntuación
     * y descartando las de menos de tres letras.
     *
     * @return array<int, string>
     */
    private static function palabras(string $texto): array
    {
        $normal = self::sinAcentos($texto);
        $normal = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $normal);
        $partes = preg_split('/\s+/u', trim($normal)) ?: [];

        return array_values(array_filter($partes, static function (string $p): bool {
            return mb_strlen($p) >= 3;
        }));
    }


    /**
     * Si lo que se está buscando es una localidad y no una calle.
     *
     * Sin esto, "ciudad de paysandu" devuelve las calles que se llaman
     * "Paysandú" en Montevideo, que están a cuatro kilómetros, y esconde la
     * ciudad que a 330 km la persona realmente está pidiendo.
     */
    public static function consultaEsLugar(string $consulta): bool
    {
        foreach (self::palabras($consulta) as $palabra) {
            if (in_array($palabra, self::PALABRAS_LUGAR, true)) {
                return true;
            }
        }

        return false;
    }

    /** `result_type` de Geoapify que son localidades, no Calles ni edificios. */
    private const TIPOS_LUGAR = [
        'city'    => true,
        'town'    => true,
        'village' => true,
        'state'   => true,
        'county'  => true,
        'country' => true,
        'hamlet'  => true,
        'municipality' => true,
        'locality' => true,
    ];

    /** Palabras que delatan que se busca una localidad. */
    private const PALABRAS_LUGAR = [
        'ciudad', 'localidad', 'pueblo', 'villa', 'barrio', 'zona', 'municipio',
        'departamento', 'estado', 'provincia', 'pais', 'town', 'city',
    ];

    /**
     * Nombre corto y legible de la categoría de OSM, para mostrarlo en la
     * sugerencia. Geoapify devuelve la etiqueta técnica cruda
     * ("healthcare.hospital"), que no le dice nada a quien busca una dirección.
     */
    private static function tipoLegible(string $categoria): string
    {
        if ($categoria === '') {
            return '';
        }

        $finos = [
            'healthcare.hospital'     => 'Hospital',
            'healthcare.clinic'       => 'Clínica',
            'healthcare.doctors'      => 'Consultorio',
            'healthcare.pharmacy'     => 'Farmacia',
            'healthcare'              => 'Salud',
            'amenity.hospital'        => 'Hospital',
            'amenity.clinic'          => 'Clínica',
            'amenity.pharmacy'        => 'Farmacia',
            'amenity.restaurant'      => 'Restaurante',
            'amenity'                 => 'Lugar',
            'building'                => 'Edificio',
            'shop'                    => 'Comercio',
            'office'                  => 'Oficina',
            'road'                    => 'Calle',
            'street'                  => 'Calle',
            'residential'             => 'Barrio',
            'neighbourhood'           => 'Barrio',
            'suburb'                  => 'Barrio',
            'city'                    => 'Ciudad',
            'town'                    => 'Ciudad',
            'village'                 => 'Pueblo',
            'service'                 => 'Servicio',
            'pet'                     => 'Veterinaria',
        ];

        // La categoría puede venir múltiple y separada por punto y coma
        // ("commercial.health_and_beauty.pharmacy;healthcare.pharmacy"), así
        // que hay que probar cada valor por separado: quedarse solo con el
        // primero deja sin tipo a media clínica, que en OSM están mapeadas
        // como farmacia.
        $valores = preg_split('/[;,|]/', $categoria) ?: [];

        $mejor = null;
        foreach ($valores as $valor) {
            $valor = trim($valor);
            if ($valor === '') {
                continue;
            }
            foreach ($finos as $prefijo => $nombre) {
                if (str_starts_with($valor, $prefijo)) {
                    if ($mejor === null || strlen($prefijo) > strlen($mejor[0])) {
                        $mejor = [$prefijo, $nombre];
                    }
                }
            }
        }

        return $mejor[1] ?? '';
    }

    /**
     * Texto en minúsculas sin acentos ni puntuación, para comparar.
     *
     * No alcanza con quitar `\p{Mn}`: esa clase solo cubre los acentos
     * descompuestos (a + U+0301) y no las letras precompuestas que trae la API
     * ("Clínica" con la í ya pegada). Con `\p{Mn}` solamente, comparar
     * "clinica" contra "Clínica" fallaba siempre, que es justo el caso que
     * motiva el buscador. Se usa un mapa explícito de las letras que aparecen
     * en español, portugués y francés.
     */
    public static function sinAcentos(string $texto): string
    {
        $texto = mb_strtolower(trim($texto), 'UTF-8');

        return strtr($texto, [
            'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'ã' => 'a', 'å' => 'a',
            'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
            'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o', 'õ' => 'o',
            'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
            'ñ' => 'n', 'ç' => 'c', 'ý' => 'y', 'ÿ' => 'y',
        ]);
    }

    /** Normaliza un label para comparar duplicados sin acentos ni mayúsculas. */
    private static function claveLabel(string $label): string
    {
        $normal = self::sinAcentos($label);

        return (string) preg_replace('/[^\p{L}\p{N}]+/u', '', $normal);
    }

    // ------------------------------------------------------------------
    // HTTP
    // ------------------------------------------------------------------

    /**
     * @param array<string, string> $params
     */
    private static function consultar(string $ruta, array $params): mixed
    {
        $clave = self::claveCache('api', [$ruta, $params]);
        $cache = self::leerCache($clave);
        if ($cache !== null) {
            return $cache;
        }

        $params['apiKey'] = trim((string) ($_ENV['GEOAPIFY_API_KEY'] ?? ''));
        if ($params['apiKey'] === '') {
            return null;
        }

        // Si la API acaba de fallar, no se vuelve a preguntar hasta que pase
        // la espera: cada ruta dibujada repetía el fallo y encima tardaba lo
        // mismo que un timeout. Se responde null y el llamador cae a OSRM.
        if (self::enEspera()) {
            return null;
        }

        $url = self::BASE_URL . $ruta . '?' . http_build_query($params);

        $ctx = stream_context_create([
            'http' => [
                'timeout' => self::TIMEOUT_SEGUNDOS,
                'method'  => 'GET',
                'header'  => "Accept: application/json\r\n",
            ],
        ]);

        $cuerpo = @file_get_contents($url, false, $ctx);
        if ($cuerpo === false) {
            self::marcarFallo();
            return null;
        }

        $datos = json_decode($cuerpo, true);
        if (!is_array($datos)) {
            self::marcarFallo();
            return null;
        }

        // La API contesta 200 con un cuerpo de error en algunos casos
        // (cuota excedida, plan sin ese endpoint). Se trata como fallo para
        // que el llamador caiga al proveedor anterior.
        if (isset($datos['statusCode']) && (int) $datos['statusCode'] >= 400) {
            error_log('Geoapify ' . $ruta . ': ' . (string) ($datos['message'] ?? 'error'));
            self::marcarFallo();
            return null;
        }

        // Contestó bien: se levanta la espera y se sigue normalmente.
        self::levantarEspera();

        // Un 200 con `results: []` sí se cachea, pero 10 minutos: es una respuesta
        // válida y no vale la pena re-preguntarlo en cada tecla.
        self::escribirCache(
            $clave,
            $datos,
            ($datos['results'] ?? null) === [] ? self::CACHE_TTL_SIN_RESULTADOS : null
        );

        return $datos;
    }

    // ------------------------------------------------------------------
    // Caché en disco
    // ------------------------------------------------------------------

    /** @param array<int|string, mixed> $partes */
    private static function claveCache(string $prefijo, array $partes): string
    {
        return $prefijo . '_' . substr(sha1((string) json_encode($partes)), 0, 32);
    }

    private static function leerCache(string $clave): mixed
    {
        $ruta = self::rutaCache($clave);
        if (!is_file($ruta)) {
            return null;
        }

        $contenido = @file_get_contents($ruta);
        $datos = $contenido === false ? null : json_decode($contenido, true);

        if ($datos === null) {
            @unlink($ruta);
            return null;
        }

        // Formato actual: {exp: <unix>, val: <datos>}. El formato viejo (el valor
        // directo, sin envolver) se sigue aceptando por mtime para no tirar
        // abajo de golpe la caché ya escrita; se va solo al expirar.
        if (is_array($datos) && array_key_exists('exp', $datos) && array_key_exists('val', $datos)) {
            if ((int) $datos['exp'] < time()) {
                @unlink($ruta);
                return null;
            }

            return $datos['val'];
        }

        if (time() - (int) filemtime($ruta) > self::CACHE_TTL) {
            @unlink($ruta);
            return null;
        }

        return $datos;
    }

    /**
     * @param int|null $ttl Segundos de validez. Null usa CACHE_TTL.
     *        Para una búsqueda sin resultados se pasa CACHE_TTL_SIN_RESULTADOS:
     *        OSM indexa lugares nuevos todo el tiempo y un "no hay
     *        resultados" cacheado 30 días dejaría el lugar invisible.
     */
    private static function escribirCache(string $clave, mixed $valor, ?int $ttl = null): void
    {
        $ruta = self::rutaCache($clave);
        $dir = dirname($ruta);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }

        $envoltorio = [
            'exp' => time() + ($ttl ?? self::CACHE_TTL),
            'val' => $valor,
        ];
        @file_put_contents($ruta, (string) json_encode($envoltorio), LOCK_EX);
    }

    private static function rutaCache(string $clave): string
    {
        return self::CACHE_DIR . '/' . $clave . '.json';
    }

    /**
     * Backoff tras un fallo. Un archivo con la hora en que se puede volver a
     * preguntar; no usa la caché de respuestas porque acá se cachea la
     * INDISPONIBILIDAD del proveedor, que es otra cosa.
     */
    private static function archivoEspera(): string
    {
        return self::CACHE_DIR . '/proveedor-caido.json';
    }

    private static function marcarFallo(): void
    {
        $archivo = self::archivoEspera();
        $dir = dirname($archivo);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }
        @file_put_contents($archivo, (string) (time() + self::ESPERA_TRAS_FALLO_S), LOCK_EX);
    }

    private static function enEspera(): bool
    {
        $archivo = self::archivoEspera();
        if (!is_file($archivo)) {
            return false;
        }

        $contenido = @file_get_contents($archivo);
        if ($contenido === false || !is_numeric(trim($contenido))) {
            @unlink($archivo);
            return false;
        }

        if ((int) trim($contenido) > time()) {
            return true;
        }

        // Ya pasó la espera: se limpia y se vuelve a preguntar.
        @unlink($archivo);

        return false;
    }

    private static function levantarEspera(): void
    {
        @unlink(self::archivoEspera());
    }

    private static function aFloat(mixed $valor): ?float
    {
        if (is_int($valor) || is_float($valor)) {
            return (float) $valor;
        }
        if (is_string($valor) && is_numeric(trim($valor))) {
            return (float) trim($valor);
        }

        return null;
    }
}
