<?php

declare(strict_types=1);

/**
 * RutaService: calcula la ruta real por calles entre dos puntos y la guarda en
 * caché para no repetir la consulta por cada par de coordenadas.
 *
 * Proveedor: Geoapify si GEOPROVIDER=geoapify (agrega las instrucciones giro
 * a giro en `pasos`); si no está habilitado o falla, cae al router OSRM
 * (Open Source Routing Machine).
 *
 * Devuelve null si ningún proveedor responde; el controlador decide usar la
 * línea recta como respaldo.
 */
final class RutaService
{
    private const OSRM_URL = 'https://router.project-osrm.org/route/v1/driving/';
    private const CACHE_DIR = __DIR__ . '/../storage/route-cache';
    private const CACHE_TTL = 86400 * 30;
    // Tope de archivos de caché: si se supera, se borran los más viejos.
    private const CACHE_MAX_ARCHIVOS = 2000;

    /**
     * Ruta real entre dos puntos: coordenadas (lat, lng) por calles, distancia
     * en km y duración estimada en minutos. Null si no hay ruta disponible.
     */
    public static function obtenerRutaReal(float $origenLat, float $origenLng, float $destinoLat, float $destinoLng): ?array
    {
        $geoapifyActivo = GeoapifyService::preferido();

        $ruta = null;
        if ($geoapifyActivo) {
            $ruta = self::leerCache(self::clave('geoapify', $origenLat, $origenLng, $destinoLat, $destinoLng));
        }

        if ($ruta === null) {
            $ruta = self::leerCache(self::clave('osm', $origenLat, $origenLng, $destinoLat, $destinoLng));
        }

        // Hay una ruta cacheada de OSRM (entramos acá cuando Geoapify está
        // activo pero no hay respuesta suya guardada). Geoapify sólo suma las
        // instrucciones giro a giro, así que se reintenta una vez: si la API
        // sigue caída se devuelve la de OSRM y no se vuelve a preguntar en
        // esta misma llamada. Sin esto, un fallo puntual de Geoapify dejaba al
        // chofer sin `pasos` durante los 30 días del TTL de la caché.
        if ($ruta !== null && $geoapifyActivo && empty($ruta['pasos'])) {
            $conPasos = GeoapifyService::ruta($origenLat, $origenLng, $destinoLat, $destinoLng);
            if ($conPasos !== null) {
                self::escribirCache(self::clave('geoapify', $origenLat, $origenLng, $destinoLat, $destinoLng), $conPasos);

                return $conPasos;
            }
        }

        if ($ruta !== null) {
            return $ruta;
        }

        // Geoapify primero si está configurado así: además de la traza,
        // devuelve las instrucciones giro a giro para el chofer. Si la clave
        // faltara, la cuota se hubiera agotado o la API cayera, se sigue con
        // OSRM y el mapa funciona igual.
        $ruta = $geoapifyActivo
            ? GeoapifyService::ruta($origenLat, $origenLng, $destinoLat, $destinoLng)
            : null;

        // La clave refleja el proveedor que RESPONDIÓ, no el preferido. Si
        // Geoapify falla y entra OSRM, guardar bajo la clave "geoapify"
        // envenenaba la caché: esa ruta sin `pasos` se servía 30 días y
        // Geoapify no se volvía a intentar aunque la cuota se repusiera.
        $proveedor = $ruta !== null ? 'geoapify' : 'osm';
        $ruta = $ruta ?? self::consultarOsrm($origenLat, $origenLng, $destinoLat, $destinoLng);

        if ($ruta !== null) {
            self::escribirCache(self::clave($proveedor, $origenLat, $origenLng, $destinoLat, $destinoLng), $ruta);
        }

        return $ruta;
    }

    /** Consulta a OSRM (geometría GeoJSON: lista de coordenadas [lat, lng]). */
    private static function consultarOsrm(float $oLat, float $oLng, float $dLat, float $dLng): ?array
    {
        $url = self::OSRM_URL
            . rawurlencode($oLng . ',' . $oLat)
            . ';'
            . rawurlencode($dLng . ',' . $dLat)
            . '?overview=full&geometries=geojson&steps=false';

        $ctx = stream_context_create([
            'http' => [
                'timeout' => 12,
                'method' => 'GET',
                'header' => "User-Agent: Elyra-Hospital/1.0\r\n",
            ],
        ]);

        $respuesta = @file_get_contents($url, false, $ctx);
        if ($respuesta === false) {
            return null;
        }

        $datos = json_decode($respuesta, true);
        if (!is_array($datos) || ($datos['code'] ?? '') !== 'Ok') {
            return null;
        }

        $rutas = $datos['routes'] ?? [];
        if (!is_array($rutas) || count($rutas) === 0) {
            return null;
        }

        $ruta = $rutas[0] ?? null;
        $geometria = is_array($ruta) ? ($ruta['geometry'] ?? null) : null;
        $puntos = is_array($geometria) ? ($geometria['coordinates'] ?? []) : [];

        if (!is_array($puntos) || count($puntos) < 2) {
            return null;
        }

        $coordenadas = [];
        foreach ($puntos as $punto) {
            if (is_array($punto) && count($punto) >= 2 && (is_int($punto[0]) || is_float($punto[0]))) {
                // OSRM entrega [lng, lat]; acá se guarda [lat, lng].
                $coordenadas[] = [(float) $punto[1], (float) $punto[0]];
            }
        }

        if (count($coordenadas) < 2) {
            return null;
        }

        $distancia = is_numeric($ruta['distance'] ?? null) ? (float) $ruta['distance'] : 0.0;
        $duracion = is_numeric($ruta['duration'] ?? null) ? (float) $ruta['duration'] : 0.0;

        return [
            'coordinates' => $coordenadas,
            'distance_km' => round($distancia / 1000, 2),
            'duration_min' => round($duracion / 60, 1),
        ];
    }

    private static function clave(string $proveedor, float $oLat, float $oLng, float $dLat, float $dLng): string
    {
        // El proveedor va en la clave: sin esto, una ruta que ya quedó cacheada
        // de OSRM se seguiría sirviendo después de activar Geoapify, y el
        // cambio parecería no tener efecto.
        return $proveedor . '_' . sprintf('%.6f_%.6f_%.6f_%.6f', $oLat, $oLng, $dLat, $dLng);
    }

    private static function leerCache(string $clave): ?array
    {
        $archivo = self::CACHE_DIR . '/' . md5($clave) . '.json';
        if (!is_file($archivo)) {
            return null;
        }

        $mtime = @filemtime($archivo);
        if ($mtime === false || (time() - $mtime) > self::CACHE_TTL) {
            @unlink($archivo);
            return null;
        }

        $contenido = @file_get_contents($archivo);
        $datos = $contenido !== false ? json_decode($contenido, true) : null;
        if (!is_array($datos) || !is_array($datos['coordinates'] ?? null)) {
            return null;
        }

        $coordenadas = [];
        foreach ($datos['coordinates'] as $punto) {
            if (is_array($punto) && count($punto) >= 2
                && (is_int($punto[0]) || is_float($punto[0]))
                && (is_int($punto[1]) || is_float($punto[1]))
            ) {
                $coordenadas[] = [(float) $punto[0], (float) $punto[1]];
            }
        }

        if (count($coordenadas) < 2) {
            return null;
        }

        $ruta = [
            'coordinates' => $coordenadas,
            'distance_km' => is_numeric($datos['distance_km'] ?? null) ? (float) $datos['distance_km'] : 0.0,
            'duration_min' => is_numeric($datos['duration_min'] ?? null) ? (float) $datos['duration_min'] : 0.0,
        ];

        // `pasos` solo viene con Geoapify. Hay que revalidarlo como se
        // revalida la geometría: si el archivo quedó corrupto o lo escribió
        // una versión vieja, se ignora en vez de romper el render.
        if (is_array($datos['pasos'] ?? null)) {
            $pasos = [];
            foreach ($datos['pasos'] as $paso) {
                if (!is_array($paso)) {
                    continue;
                }
                $texto = trim((string) ($paso['texto'] ?? ''));
                if ($texto === '') {
                    continue;
                }
                $pasos[] = [
                    'texto'       => $texto,
                    'distancia_m' => is_numeric($paso['distancia_m'] ?? null) ? (float) $paso['distancia_m'] : 0.0,
                ];
            }
            if ($pasos !== []) {
                $ruta['pasos'] = $pasos;
            }
        }

        return $ruta;
    }

    private static function escribirCache(string $clave, array $ruta): void
    {
        if (!is_dir(self::CACHE_DIR)) {
            @mkdir(self::CACHE_DIR, 0750, true);
        }
        $archivo = self::CACHE_DIR . '/' . md5($clave) . '.json';
        @file_put_contents($archivo, json_encode($ruta, JSON_UNESCAPED_UNICODE));
        self::podarCache();
    }

    /** Mantiene la caché acotada: si supera el tope, borra los archivos más viejos. */
    private static function podarCache(): void
    {
        $archivos = @glob(self::CACHE_DIR . '/*.json');
        if ($archivos === false || count($archivos) <= self::CACHE_MAX_ARCHIVOS) {
            return;
        }
        usort($archivos, static function (string $a, string $b): int {
            return (@filemtime($a) ?: 0) <=> (@filemtime($b) ?: 0);
        });
        $sobran = count($archivos) - self::CACHE_MAX_ARCHIVOS;
        for ($i = 0; $i < $sobran; $i++) {
            @unlink($archivos[$i]);
        }
    }
}