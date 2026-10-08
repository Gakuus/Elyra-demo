<?php

declare(strict_types=1);

/**
 * GeocodeService: convierte texto en coordenadas y coordenadas en texto.
 *
 * Proveedor: si está configurado GEOPROVIDER=geoapify y hay clave, usa
 * Geoapify (más ágil para autocompletar y con bias de proximidad). Si falla
 * o no está habilitado, cae al geocodificador Nominatim de OpenStreetMap.
 *
 * Lo usa el mapa del dashboard para el buscador de destinos del traslado
 * (autocompletado estilo Google Maps) y para poner nombre a la posición GPS
 * del usuario.
 *
 * El proveedor Nominatim tiene tres reglas de uso que este servicio respeta:
 *   1) Exige un User-Agent identificable en cada petición.
 *   2) Limita a 1 petición por segundo por IP: se marca la última consulta
 *      y, si fue hace muy poco, se espera lo que falte antes de salir a la red.
 *      La espera no bloquea por 1100 ms completos a propósito: si el usuario
 *      deja de escribir, el último turno puede llegar antes y salir de una.
 *   3) Los resultados se cachean en disco (búsqueda 7 días, reverse 30 días).
 *      Una búsqueda sin resultados se cachea solo 10 minutos: OSM indexa
 *      lugares nuevos todo el tiempo y un "no hay resultados" viejo dejaría
 *      un lugar invisible para siempre.
 */
final class GeocodeService
{
    private const BASE_URL = 'https://nominatim.openstreetmap.org/';
    private const USER_AGENT = 'Elyra-Hospital/1.0 (sistema de traslados; https://github.com/Gakuus/Elyra-demo)';

    private const CACHE_DIR = __DIR__ . '/../storage/geocode-cache';
    private const CACHE_TTL_BUSQUEDA = 604800 * 7;   // 7 días
    private const CACHE_TTL_REVERSA = 86400 * 30;   // 30 días
    private const CACHE_TTL_SIN_RESULTADOS = 600;

    private const ESPERA_MINIMA_MS = 1100;

    /** Máximo de resultados que se devuelven al navegador. */
    private const LIMITE = 8;

    /**
     * Una consulta contra Geoapify, con su caché y su cuenta atrás ya puestos.
     *
     * Pelega los dos endpoints (no solo el primero que responda): antes se
     * caía a `/geocode/search` únicamente cuando autocomplete volvía vacío, y
     * eso recortaba la lista a la mitad: para "avenida italia" autocomplete
     * daba 5 y search 6, todos tramos distintos de la misma avenida. El
     * combinado de Geoapify los junta y deduplica, y además agrega la consulta
     * del círculo cercano cuando hay proximidad.
     */
    private static function consultarGeoapify(string $texto, int $limite, ?float $proxLat, ?float $proxLng): array
    {
        return GeoapifyService::sugerencias($texto, $limite, $proxLat, $proxLng);
    }

/**
 * Deja arriba lo de Uruguay y esconde lo de otros países.
 *
 * El filtro por país en la API (`filter=countrycode:uy`) quedaba demasiado "Tres Cruces" devolvía 1 resultado con filtro y 12 sin él,
 * y "Avenida Italia" 10 contra 11. Vaciaba justamente las calles y los barrios,
 * que es lo que se escribe al elegir un destino. Así que ahora la API no
 * filtra: se piden más resultados y el país se resuelve acá.
 *
 * Se muestran solo los uruguayos cuando hay alguno. Si no hay ninguno se
 * dejan los de afuera, porque una lista vacía le sirve menos a quien está
 * buscando una dirección.
 */
private static function priorizarUruguay(array $sugerencias, int $limite): array
{
    $uy = [];
    $resto = [];
    foreach ($sugerencias as $s) {
        $dentro = strtolower(trim((string) ($s['pais'] ?? ''))) === 'uy'
            || strpos(strtolower(trim((string) ($s['pais_nombre'] ?? ''))), 'uruguay') !== false;
        if ($dentro) {
            $uy[] = $s;
        } else {
            $resto[] = $s;
        }
    }

    return array_slice($uy !== [] ? $uy : $resto, 0, $limite);
}

/**
 * Quita el rótulo que acompaña al nombre de una localidad para quedarse
     * con lo que la identifica: "ciudad de Paysandú" -> "Paysandú",
     * "barrio Tres Cruces" -> "Tres Cruces".
     *
     * @return string El texto vacío si no hay nada distintivo que quedarse.
     */
    private static function nombreDistintivo(string $texto): string
    {
        $limpio = trim(preg_replace('/\s+/u', ' ', $texto) ?? $texto);

        // "ciudad de X", "localidad de X", "villa X", "barrio X", "municipio de X"
        $patron = '/^(?:ciudad|localidad|pueblo|villa|barrio|zona|municipio|comunidad|distrito|departamento|provincia|estado|ciudad|pueblo)\s+(?:de\s+|del\s+|de\s+la\s+)?(.+)$/iu';
        if (preg_match($patron, $limpio, $m)) {
            $resto = trim($m[1]);

            return mb_strlen($resto) >= 3 ? $resto : '';
        }

        return '';
    }

    /**
     * Pone las localidades adelante, conservando el orden relativo dentro de
     * cada grupo. Es lo que hace falta cuando la persona escribió "ciudad de
     * X": lo que quiere es una ciudad, aunque la calle con el mismo nombre
     * quede a cuatro kilómetros y la ciudad a trescientos.
     */
    /**
     * Cuando se pidió una localidad, las localidades van arriba.
     *
     * Entre las que son localidad se pone arriba la que lleva el nombre que se
     * escribió. "Ciudad de la Costa" es una calle de Montevideo que el catálogo
     * clasifica como localidad, y a veinte kilómetros le ganaba a Paysandú,
     * que está a trescientos treinta y que era lo que se había pedido de verdad.
     */
    private static function localidadesPrimero(array $sugerencias, string $distintivo = ''): array
    {
        $coincide = [];
        $otras = [];
        $resto = [];
        $buscado = $distintivo === '' ? '' : self::clave($distintivo);

        foreach ($sugerencias as $s) {
            if (empty($s['es_lugar'])) {
                $resto[] = $s;
                continue;
            }
            if ($buscado !== '' && strpos(self::clave((string) ($s['label'] ?? '')), $buscado) !== false) {
                $coincide[] = $s;
            } else {
                $otras[] = $s;
            }
        }

        return array_merge($coincide, $otras, $resto);
    }

    /** ¿Alguna de las sugerencias es una localidad y no una calle? */
    /**
     * Si entre los resultados está LA localidad que se pidió, y no cualquier
     * cosa que sea una localidad.
     *
     * Antes alcanzaba con que uno solo tenga tipo localidad, y eso rompía
     * "ciudad de paysandu": entre las calles de Montevideo hay varias llamadas
     * "Ciudad de la Costa", "Ciudad de Azul" y "Ciudad de Bahía Blanca", que
     * cuentan como localidad, así que el reintento con el nombre desnudo
     * ("paysandu") no se disparaba nunca y la ciudad pedida no llegaba jamás a
     * la lista. Ahora se exige que el resultado sea una localidad Y que su
     * nombre contenga el que se escribió.
     */
    private static function traeLocalidadPedida(array $sugerencias, string $distintivo): bool
    {
        if ($distintivo === '') {
            return false;
        }

        $buscado = self::clave($distintivo);

        foreach ($sugerencias as $s) {
            if (empty($s['es_lugar'])) {
                continue;
            }
            if (strpos(self::clave((string) ($s['label'] ?? '')), $buscado) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Junta dos listas de sugerencias sin repetir, conservando el orden.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function combinar(array $a, array $b, ?int $limite = null): array
    {
        $vistas = [];
        $salida = [];

        foreach ([$a, $b] as $lista) {
            foreach ($lista as $s) {
                // El place_id identifica el lugar aunque cambie el formato del
                // label; si no viene, coordenada más label reachan.
                $clave = !empty($s['place_id'])
                    ? 'id:' . $s['place_id']
                    : sprintf('xy:%.5f:%.5f|%s', (float) $s['lat'], (float) $s['lng'], self::clave($s['label']));

                if (isset($vistas[$clave])) {
                    continue;
                }
                $vistas[$clave] = true;
                $salida[] = $s;
            }
        }

        return $limite === null ? $salida : array_slice($salida, 0, $limite);
    }

    /** Normalización para comparar textos sin acentos ni mayúsculas. */
    private static function clave(string $texto): string
    {
        $normal = GeoapifyService::sinAcentos($texto);

        return (string) preg_replace('/[^\p{L}\p{N}]+/u', '', $normal);
    }

    /**
     * ¿El texto trae algo más que letras ASCII?
     *
     * No se usa `\p{Mn}` porque solo detecta acentos descompuestos
     * (a + U+0301) y no las letras precompuestas que trae el texto de la API
     * ("paysandú" con la ú ya pegada), que es justamente el caso que importa.
     */
    private static function tieneAcentos(string $texto): bool
    {
        return (bool) preg_match('/[^\x20-\x7E]/u', $texto);
    }

    /**
     * Formas acentuadas del texto escrito, tomadas de los resultados que
     * devolvió la consulta sin acentos.
     *
     * Es la única fuente fiable: nadie sabe de antemano si el usuario escribe
     * "paysandu" queriendo "Paysandú" o "Paysandu" (que no existe), y los
     * resultados traen el nombre bien escrito.
     *
     * @return array<int, string>
     */
    private static function formasAcentuadas(array $sugerencias, string $texto): array
    {
        $objetivo = self::clave($texto);
        if ($objetivo === '') {
            return [];
        }

        $formas = [];
        foreach ($sugerencias as $s) {
            foreach (preg_split('/[,\s]+/u', (string) ($s['label'] ?? '')) ?: [] as $token) {
                $token = trim($token, " \t\n\r\0\x0B.,;");
                if ($token === '' || mb_strlen($token) < 4) {
                    continue;
                }
                // Solo sirve un token que sea el texto buscado pero escrito
                // con acento.
                if (self::clave($token) === $objetivo && self::tieneAcentos($token) && !in_array($token, $formas, true)) {
                    $formas[] = $token;
                }
            }
            if ($formas !== []) {
                break;
            }
        }

        return array_slice($formas, 0, 2);
    }

    /**
     * Busca lugares por texto libre ("hospital de clínicas", "avenida italia
     * 2345"). Devuelve una lista normalizada, vacía si no hay resultados o si
     * Nominatim no responde:
     *
     *   [['label' => 'Hospital de Clínicas, Av. Italia, Montevideo', 'lat' => -34.9, 'lng' => -56.16, 'categoria' => 'amenity'], ...]
     */
    public static function buscar(string $texto, int $limite = self::LIMITE, ?float $proxLat = null, ?float $proxLng = null): array
    {
        $texto = trim($texto);
        if (mb_strlen($texto) < 3) {
            return [];
        }

        $limite = max(1, min($limite, self::LIMITE));

        // "Treinta y Tres Orientales y Andrésito" es un cruce de calles, no
        // un texto suelto. Se resuelve primero y va arriba de todo: si lo que
        // la persona escribió fue un cruce, el cruce es lo que quiere ver
        // primero. Si no hay cruce (o el texto no es un cruce) esto devuelve
        // null y sigue todo igual que antes.
        $interseccion = InterseccionService::buscar($texto, $proxLat, $proxLng);
        if ($interseccion !== null) {
            $resto = self::restoDeLaBusqueda($texto, $limite, $proxLat, $proxLng);
            array_pop($resto);

            return array_merge([$interseccion], $resto);
        }

        return self::restoDeLaBusqueda($texto, $limite, $proxLat, $proxLng);
    }

    /**
     * La búsqueda de siempre, sin la parte de intersección. Así el cruce se
     * resuelve una vez sola y el resto del flujo no se duplica.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function restoDeLaBusqueda(string $texto, int $limite, ?float $proxLat, ?float $proxLng): array
    {
        // Geoapify primero si está configurado: autocomplete suele responder
        // mejor mientras se tipea y sin el throttling de Nominatim.
        if (GeoapifyService::preferido()) {
            $sugerencias = self::consultarGeoapify($texto, $limite, $proxLat, $proxLng);

            // "Ciudad de Paysandú" no lo resuelve como ciudad: Geoapify lo
            // devuelve como la calle "Paysandú" de Montevideo, que está a
            // cuatro kilómetros. Buscando solo el nombre distintivo sí
            // aparece. Se reintenta solo con ese nombre, y únicamente cuando
            // la primera pasada no trajo ninguna localidad, para no gastar
            // una llamada de más en cada tecla.
            $distintivo = self::nombreDistintivo($texto);
            if ($distintivo !== '' && $distintivo !== $texto && !self::traeLocalidadPedida($sugerencias, $distintivo)) {
                $sugerencias = self::combinar(
                    $sugerencias,
                    self::consultarGeoapify($distintivo, $limite, $proxLat, $proxLng)
                );
                // El orden se reacomoda dentro de cada respuesta por separado,
                // así que al pegarlas las dos hay que volver a ordenar: si no,
                // las calles de Montevideo quedan arriba y la ciudad que se
                // pidió queda al final de la lista.
                if (GeoapifyService::consultaEsLugar($texto)) {
                    $sugerencias = self::localidadesPrimero($sugerencias, $distintivo);
                }
            }

            // Escribir "paysandu" sin tilde recupera menos resultados que
            // escribir "paysandú": la API normaliza a los dos lados, pero el
            // ranking mejora con el acento. Cuando el texto vino sin acentos
            // se reintentan las formas acentuadas que aparecen en los propios
            // resultados, que es la única fuente que dice cómo se escribe.
            if (!self::tieneAcentos($texto) && count($sugerencias) < 3) {
                foreach (self::formasAcentuadas($sugerencias, $texto) as $forma) {
                    $sugerencias = self::combinar(
                        $sugerencias,
                        self::consultarGeoapify($forma, $limite, $proxLat, $proxLng),
                        $limite
                    );
                    if (count($sugerencias) >= 3) {
                        break;
                    }
                }
            }

            if ($sugerencias !== []) {
                return self::priorizarUruguay($sugerencias, $limite);
            }
        }

        $clave = 'q_' . mb_strtolower(preg_replace('/\s+/u', ' ', $texto) ?? $texto);
        $cache = self::leerCache($clave, self::CACHE_TTL_BUSQUEDA);
        if ($cache !== null) {
            return array_slice($cache, 0, $limite);
        }

        self::esperarTurno();

        $datos = self::consultar(
            'search?' . http_build_query([
                'q'                => $texto,
                'format'           => 'jsonv2',
                'limit'            => $limite,
                'addressdetails'   => 0,
                'accept-language'  => 'es',
                // Restringe la búsqueda a Uruguay: el sistema es del Hospital de
                // Clínicas y así los resultados no se llenan de ciudades de
                // otros países que arrancan con el mismo nombre.
                'countrycodes'     => 'uy',
            ])
        );

        if ($datos === null || !is_array($datos)) {
            return [];
        }

        $resultados = [];
        foreach ($datos as $fila) {
            if (!is_array($fila)) {
                continue;
            }
            $lat = self::aFloat($fila['lat'] ?? null);
            $lng = self::aFloat($fila['lon'] ?? null);
            $label = trim((string) ($fila['display_name'] ?? ''));
            if ($lat === null || $lng === null || $label === '') {
                continue;
            }
            $resultados[] = [
                'label'     => $label,
                'lat'       => $lat,
                'lng'       => $lng,
                'categoria' => trim((string) ($fila['category'] ?? '')),
            ];
        }

        if ($resultados !== []) {
            self::escribirCache($clave, $resultados, self::CACHE_TTL_BUSQUEDA);
        } else {
            self::escribirCache($clave, [], self::CACHE_TTL_SIN_RESULTADOS);
        }

        return array_slice($resultados, 0, $limite);
    }

    /**
     * Dirección de un punto: al revés de buscar(). Se usa cuando el usuario
     * toca "Usar mi ubicación", para guardar el origen con un nombre legible
     * en vez de "35.123456, -56.789012". Null si el proveedor no responde.
     *
     *   ['label' => 'Av. Italia 2455, Montevideo', 'lat' => -34.9, 'lng' => -56.16]
     */
    public static function direccionDe(float $lat, float $lng): ?array
    {
        if (!self::coordenadaValida($lat, $lng)) {
            return null;
        }

        // Geoapify primero si está configurado; Nominatim si no.
        if (GeoapifyService::preferido()) {
            $direccion = GeoapifyService::direccionDe($lat, $lng);
            if ($direccion !== null) {
                return $direccion;
            }
        }

        $clave = sprintf('r_%.6f_%.6f', $lat, $lng);
        $cache = self::leerCache($clave, self::CACHE_TTL_REVERSA);
        if ($cache !== null) {
            return $cache;
        }

        $datos = self::consultar(
            'reverse?' . http_build_query([
                'lat'             => $lat,
                'lon'             => $lng,
                'format'          => 'jsonv2',
                'zoom'            => 18,
                'addressdetails'  => 1,
                'accept-language' => 'es',
            ])
        );

        if (!is_array($datos)) {
            return null;
        }

        $lat = self::aFloat($datos['lat'] ?? null) ?? $lat;
        $lng = self::aFloat($datos['lon'] ?? null) ?? $lng;
        $label = trim((string) ($datos['display_name'] ?? ''));
        if ($label === '') {
            return null;
        }

        $direccion = ['label' => $label, 'lat' => $lat, 'lng' => $lng];
        self::escribirCache($clave, $direccion, self::CACHE_TTL_REVERSA);

        return $direccion;
    }

    /**
     * Comprueba que un par de coordenadas esté en rango. Lo usan el controlador
     * y el guardado del traslado para rechazar valores sueltos del formulario.
     */
    public static function coordenadaValida(?float $lat, ?float $lng): bool
    {
        return $lat !== null && $lng !== null
            && $lat >= -90.0 && $lat <= 90.0
            && $lng >= -180.0 && $lng <= 180.0;
    }

    // ------------------------------------------------------------------
    // Consulta HTTP
    // ------------------------------------------------------------------

    /** GET a Nominatim y devuelve el JSON ya decodificado, o null si falla. */
    private static function consultar(string $ruta): mixed
    {
        $url = self::BASE_URL . $ruta;

        $ctx = stream_context_create([
            'http' => [
                'timeout' => 10,
                'method' => 'GET',
                'header' => 'User-Agent: ' . self::USER_AGENT . "\r\nAccept: application/json\r\n",
                // Sin esto, un 429 o 503 de Nominatim llega como cuerpo de
                // error y no como false: hay que mirar el código HTTP.
                'ignore_errors' => true,
            ],
        ]);

        $cuerpo = @file_get_contents($url, false, $ctx);

        // La marca se escribe DESPUÉS de la respuesta, no antes: si la consulta
        // falla, no tiene sentido congelar el siguiente turno.
        @file_put_contents(self::marcaTurno(), (string) (int) (microtime(true) * 1000));

        if ($cuerpo === false) {
            return null;
        }

        $codigoHttp = self::codigoHttpDe($http_response_header ?? []);
        if ($codigoHttp !== null && ($codigoHttp < 200 || $codigoHttp >= 300)) {
            return null;
        }

        return json_decode($cuerpo, true);
    }

    /**
     * Espera a que se cumpla el intervalo mínimo entre consultas a Nominatim.
     * El archivo de marca se bloquea durante la espera para que dos peticiones
     * simultáneas no venderían la misma espera y salgan las dos a la vez.
     */
    private static function esperarTurno(): void
    {
        $marca = self::marcaTurno();
        $manejador = @fopen($marca, 'c+');
        if ($manejador === false) {
            return; // Sin marca se pierde la espera, pero el buscador anda igual.
        }

        try {
            if (flock($manejador, LOCK_EX)) {
                $contenido = stream_get_contents($manejador);
                $ultima = is_numeric(trim((string) $contenido))
                    ? (int) trim((string) $contenido)
                    : 0;

                $desde = (int) (microtime(true) * 1000) - $ultima;
                if ($desde < self::ESPERA_MINIMA_MS) {
                    usleep((self::ESPERA_MINIMA_MS - $desde) * 1000);
                }
                flock($manejador, LOCK_UN);
            }
        } finally {
            fclose($manejador);
        }
    }

    private static function marcaTurno(): string
    {
        return self::CACHE_DIR . '/.ultima-consulta';
    }

    /** Última línea "HTTP/1.1 200 OK" de las cabeceras de la respuesta. */
    private static function codigoHttpDe(array $cabeceras): ?int
    {
        for ($i = count($cabeceras) - 1; $i >= 0; $i--) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $cabeceras[$i], $m) === 1) {
                return (int) $m[1];
            }
        }
        return null;
    }

    // ------------------------------------------------------------------
    // Caché en disco (una fila por consulta, igual que RutaService)
    // ------------------------------------------------------------------

    private static function leerCache(string $clave, int $ttl): mixed
    {
        $archivo = self::rutaCache($clave);
        if (!is_file($archivo)) {
            return null;
        }

        $mtime = @filemtime($archivo);
        if ($mtime === false || (time() - $mtime) > $ttl) {
            @unlink($archivo);
            return null;
        }

        $contenido = @file_get_contents($archivo);
        if ($contenido === false) {
            return null;
        }

        $datos = json_decode($contenido, true);

        return is_array($datos) ? $datos : null;
    }

    private static function escribirCache(string $clave, array $valor, int $ttl): void
    {
        $ruta = self::rutaCache($clave);
        if (!is_dir(self::CACHE_DIR)) {
            @mkdir(self::CACHE_DIR, 0750, true);
        }
        if (@file_put_contents($ruta, json_encode($valor, JSON_UNESCAPED_UNICODE)) !== false) {
            // El TTL no está en el nombre del archivo (así se comparte entre
            // consultas idénticas), se fuerza con la fecha de modificación.
            @touch($ruta, time() - (self::CACHE_TTL_BUSQUEDA - $ttl));
        }
    }

    private static function rutaCache(string $clave): string
    {
        // La clave viene de texto tipeado por el usuario, así que se pasa por
        // md5 antes de tocar el disco: evita separadores de ruta y nombres largos.
        return self::CACHE_DIR . '/' . md5($clave) . '.json';
    }

    // ------------------------------------------------------------------

    private static function aFloat(mixed $valor): ?float
    {
        return is_numeric($valor) ? (float) $valor : null;
    }
}
