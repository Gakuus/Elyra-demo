<?php

declare(strict_types=1);

/**
 * InterseccionService: resuelve "calle A y calle B" como el punto donde se
 * cortan, y no como dos palabras sueltas.
 *
 * El problema que viene a resolver:
 *
 *   "Paysandu, Treinta y Tres Orientales y Andresito"
 *
 * Geocodificadores como Geoapify reciben eso como un solo texto y no lo
 * entienden: devuelven las calles que se llaman "Paysandú" en todo el país
 * (Montevideo, Las Piedras, Toledo...) y se ignora la parte de la intersección.
 *
 * La forma en que se resuelve acá:
 *
 *   1. Se detectan los candidatos a cruce, partiendo el texto en los
 *      conectores ("y", "con", "esquina"...). Como los nombres de calle
 *      llevan "y" adentro ("Treinta y Tres Orientales"), NO se parte a lo
 *      bruto: se generan todos los cortes posibles y cada uno se valida
 *      contra los datos reales antes de aceptar ninguno.
 *   2. Se resuelve el bbox de la ciudad (Geoapify) o se usa el punto de
 *      referencia si la ciudad no se escribió.
 *   3. Una sola consulta a Overpass (OpenStreetMap) trae la geometría de
 *      todas las calles candidatas. Overpass cumple dos trabajos: validar que
 *      el nombre existe de verdad y traer los puntos.
 *   4. De las polilíneas de ambas calles se calcula el punto más cercano. Si
 *      las calles efectivamente se cortan, ese punto es la intersección.
 *
 * Geoapify no sirve para el paso 3: aunque encuentra las calles por nombre,
 * no devuelve su geometría (probado: ni con `geometry=geojson` ni forzando
 * `type=street`). Overpass sí la devuelve.
 *
 * Todo lo que no se puede resolver con certeza cae al comportamiento previo
 * (búsqueda normal). Nunca se inventa un punto: si las calles no se cruzan o
 * el nombre no existe, no se devuelve intersección.
 */
final class InterseccionService
{
    /**
     * Instancias de Overpass, en orden de preferencia, con el tiempo máximo
     * que se les concede.
     *
     * Solo la primera es la principal: las otras son respaldo para cuando la
     * principal está caída o saturada (Overpass devuelve 504 si se le pegan
     * muchas consultas seguidas). Se midieron antes de elegir: kumi.systems
     * y private.coffee no respondían, así que quedaron afuera.
     *
     * @var array<int, array{0: string, 1: int}>
     */
    private const ENDPOINTS = [
        ['https://overpass-api.de/api/interpreter', 6],
        ['https://maps.mail.ru/osm/tools/overpass/api/interpreter', 8],
    ];

    /**
     * Presupuesto total, en segundos, para resolver un cruce. La búsqueda de
     * direcciones no puede quedar esperando: si Overpass no responde a tiempo,
     * se sigue con los resultados normales y listo. Por eso el espejo solo se
     * prueba cuando el principal falló rápido (conexión caída), no cuando se
     * quedó pensando hasta el timeout.
     */
    private const PRESUPUESTO_S = 8;

    /** Si la principal tardó más que esto, no se pierde tiempo en el espejo. */
    private const ESPEJO_SI_FALLO_RAPIDO_S = 2.5;

    /**
     * Segundos mínimos entre dos consultas a Overpass. La instancia pública
     * tolera pocas seguidas y después devuelve 504. Este freno es lo que
     * impide que el buscador se sabotee a sí mismo.
     */
    private const FRENO_S = 9;

    /**
     * Cuánto se recuerda que Overpass falló. Sin esto cada tecla escrita
     * reintenta la misma consulta y la saturación nunca baja.
     */
    private const CACHE_TTL_FALLO = 180;

    /** Dónde se guardan las respuestas de Overpass. */
    private const CACHE_DIR = __DIR__ . '/../storage/overpass-cache';

    /**
     * Un mes. La red vial cambia muy poco y esto evita pegarle a Overpass en
     * cada tecla que el usuario escribe.
     */
    private const CACHE_TTL = 86400 * 30;

    /**
     * Las dos calles tienen que estar a menos de esto para considerarlas un
     * cruce. 25 m tolera el error de datos de OSM sin inventar un cruce donde
     * las calles en realidad no se tocan.
     */
    private const TOLERANCIA_METROS = 60.0;

    /** Radio alrededor del punto de referencia cuando no se escribe ciudad. */
    private const RADIO_SIN_CIUDAD = 80000.0;

    /** Holgura al comparar cajas de polilíneas, en grados (~33 m). */
    private const TOLERANCIAS_LAT = 0.0003;

    /**
     * Conectores que en español significan "intersección". La clave es la
     * etiqueta que se muestra al usuario.
     */
    private const CONECTORES = [
        ' y ' => ' y ',
        ' & ' => ' y ',
        ' con ' => ' con ',
        ' esquina ' => ' esquina ',
        ' esquina con ' => ' esquina ',
        ' cruce de ' => ' y ',
        ' interseccion de ' => ' y ',
        ' interseccion ' => ' y ',
    ];

    /**
     * Tipos de resultado de Geoapify que cuentan como localidad. Se mira
     * `result_type` y no `category`: Salto viene como category=administrative
     * con result_type=city, y filtrando por `category` se cuela una calle
     * llamada "Salto" de Montevideo con la caja equivocada.
     */
    private const TIPOS_CIUDAD = [
        'city', 'town', 'village', 'hamlet', 'municipality', 'district',
        'county', 'state', 'administrative', 'suburb', 'neighbourhood',
    ];

    // ------------------------------------------------------------------
    // API pública
    // ------------------------------------------------------------------

    /**
     * Devuelve una sugerencia de intersección, o null si el texto no describe
     * un cruce o el cruce no se puede resolver con certeza.
     *
     * @return array{label: string, lat: float, lng: float, categoria: string, tipo: string, es_lugar: bool}|null
     */
    public static function buscar(string $texto, ?float $proxLat = null, ?float $proxLng = null): ?array
    {
        $candidatos = self::candidatos($texto);
        if ($candidatos === []) {
            return null;
        }

        $ciudad = self::primerNombreDistintivo($candidatos);
        $bbox = self::bboxDe($ciudad, $proxLat, $proxLng);
        if ($bbox === null) {
            return null;
        }

        // Filtro barato antes de pegarle a Overpass: si al menos una de las dos
        // calles no existe como calle, no hay nada que cruzar. Geoapify es
        // rápida y está cacheada; Overpass es la que se cae y cuesta segundos
        // de más. Así "xyz abc y def" se descarta sin tocar Overpass.
        if (!self::ambasCallesExisten($candidatos, $ciudad)) {
            return null;
        }

        $nombres = [];
        foreach ($candidatos as $c) {
            // Van las dos claves, la literal y la pelada. Si solo se pidiera la
            // pelada, "La Paz" se buscaría como "Paz" y Overpass no devolvería
            // la calle real; y si solo se pidiera la literal, "la esquina
            // Treinta y Tres Orientales" no existiría nunca.
            foreach ([self::claveDeCalle($c['calle_a']), self::claveDeConsulta($c['calle_a'])] as $clave) {
                $nombres[$clave] = true;
            }
            foreach ([self::claveDeCalle($c['calle_b']), self::claveDeConsulta($c['calle_b'])] as $clave) {
                $nombres[$clave] = true;
            }
        }
        $nombres = array_keys(array_filter($nombres, static fn(string $n): bool => $n !== ''));

        $vias = self::viasDe($nombres, $bbox);
        if ($vias === []) {
            return null;
        }

        // Se prueban los candidatos en orden y gana el primero cuyos dos
        // nombres existen de verdad y se cortan.
        foreach ($candidatos as $c) {
            // Se busca con el nombre tal cual y con el pelado de relleno:
            // el pedido a Overpass va limpio, pero el candidato puede venir
            // escrito como "la esquina Treinta y Tres Orientales".
            $a = $vias[self::claveDeCalle($c['calle_a'])]
                ?? $vias[self::claveDeConsulta($c['calle_a'])]
                ?? [];
            $b = $vias[self::claveDeCalle($c['calle_b'])]
                ?? $vias[self::claveDeConsulta($c['calle_b'])]
                ?? [];
            if ($a === [] || $b === []) {
                continue;
            }

            $punto = self::puntoDeCruce($a, $b);
            if ($punto === null) {
                continue;
            }

            $label = self::etiqueta($c, $ciudad);

            return [
                'label'    => $label,
                'lat'      => $punto['lat'],
                'lng'      => $punto['lng'],
                'categoria'=> 'intersection',
                'tipo'     => 'Intersección',
                'es_lugar' => false,
                'es_interseccion' => true,
                'distancia'=> (int) round($punto['distancia']),
            ];
        }

        return null;
    }

    // ------------------------------------------------------------------
    // Parseo de la consulta
    // ------------------------------------------------------------------

    /**
     * Genera todos los pares de calles que pueden ser un cruce.
     *
     * "Treinta y Tres Orientales y Andresito" produce:
     *   - [Treinta] x [Tres Orientales y Andresito]
     *   - [Treinta y Tres Orientales] x [Andresito]
     * El segundo es el bueno, pero el primero tiene que existir como
     * candidato para que la decisión se tome con datos y no con una regla.
     *
     * @return array<int, array{calle_a: string, calle_b: string, etiqueta: string, ciudad: string}>
     */
    public static function candidatos(string $texto): array
    {
        $limpio = trim(preg_replace('/\s+/u', ' ', $texto) ?? $texto);
        if ($limpio === '') {
            return [];
        }

        $salida = [];
        foreach (self::posiblesCortes($limpio) as $corte) {
            // Cada fragmento se prueba tal cual y también sin las palabras de
            // relleno ("y la esquina", "esquina con", "de la"). Se conservan
            // las dos versiones porque quitar palabras rompe calles que se
            // llaman así: en OSM existe "La Paz", no "Paz".
            foreach (self::variantesDe(trim($corte['izq'])) as $izq) {
                foreach (self::variantesDe(trim($corte['der'])) as $der) {
                    self::armarCandidato($salida, $izq, $der, (string) $corte['etiqueta']);
                }
            }
        }

        return array_values($salida);
    }

    /**
     * Variantes legibles de un fragmento: el original y el pelado de relleno.
     * El original primero, porque si existe en OSM con esas palabras es la
     * lectura correcta.
     *
     * @return array<int, string>
     */
    private static function variantesDe(string $fragmento): array
    {
        $variantes = [];

        if (self::pareceCalle($fragmento)) {
            $variantes[] = $fragmento;
        }

        $pelado = self::limpiarRelleno($fragmento);
        if ($pelado !== $fragmento && self::pareceCalle($pelado)) {
            $variantes[] = $pelado;
        }

        return $variantes;
    }

    /**
     * Saca palabras de relleno de los dos extremos: artículos, preposiciones
     * y la propia palabra conectora, que quedó pegada al corte.
     *
     * "la esquina Treinta y Tres" -> "Treinta y Tres"
     * "Andresito y la"            -> "Andresito"
     *
     * El corte es por tokens enteros, nunca por prefijo de cadena: "Almirante
     * Brown" no puede quedar en "mirante Brown", y "De Solis" conserva su
     * variante con "De" como primera opción.
     */
    private static function limpiarRelleno(string $texto): string
    {
        $tokens = preg_split('/\s+/u', trim($texto)) ?: [];
        $relleno = [
            'la', 'el', 'los', 'las', 'un', 'una', 'unos', 'unas',
            'de', 'del', 'en', 'a', 'al', 'y', 'e', 'o', 'u', 'con',
            'esquina', 'esquina de', 'cruce', 'cruces', 'interseccion',
            'intersecciones', 'calle', 'calles', 'altura', 'alt', 'entre',
        ];

        $i = 0;
        $j = count($tokens) - 1;
        while ($i <= $j && in_array(mb_strtolower($tokens[$i], 'UTF-8'), $relleno, true)) {
            $i++;
        }
        while ($j >= $i && in_array(mb_strtolower($tokens[$j], 'UTF-8'), $relleno, true)) {
            $j--;
        }

        $queda = array_slice($tokens, $i, $j - $i + 1);

        return trim(implode(' ', $queda));
    }

    /**
     * Arma un candidato a partir de dos fragmentos, separando la ciudad si
     * viene antes o después de las calles.
     *
     * @param array<int, array{calle_a: string, calle_b: string, etiqueta: string, ciudad: string}> $salida
     */
    private static function armarCandidato(array &$salida, string $izq, string $der, string $etiqueta): void
    {
        if (!self::pareceCalle($izq) || !self::pareceCalle($der)) {
            return;
        }

        $ciudad = '';

            // "Paysandu, Treinta y Tres Orientales y Andresito": la ciudad
        // viene antes de la primera calle.
        if (str_contains($izq, ',')) {
            $partes = array_map('trim', explode(',', $izq));
            $posibleCiudad = (string) array_shift($partes);
            $resto = trim(implode(',', $partes));
            if ($resto !== '' && self::pareceCalle($resto)) {
                $ciudad = $posibleCiudad;
                $izq = $resto;
            }
        }

        // "Andresito y Treinta y Tres Orientales, Paysandu": la ciudad
        // viene después de la segunda calle, que es como lo escribe
        // medio mundo.
        if ($ciudad === '' && str_contains($der, ',')) {
            $partes = array_map('trim', explode(',', $der));
            $ultima = (string) array_pop($partes);
            $resto = trim(implode(',', $partes));
            if ($resto !== '' && self::pareceCalle($resto)) {
                $ciudad = $ultima;
                $der = $resto;
            }
        }

        // La ciudad puede venir arrastrada en el fragmento pelado, como en
        // "la esquina Treinta y Tres, Paysandu": se vuelve a separar.
        if (str_contains($izq, ',')) {
            $partes = array_map('trim', explode(',', $izq));
            $posibleCiudad = (string) array_shift($partes);
            $resto = trim(implode(',', $partes));
            if ($resto !== '' && self::pareceCalle($resto)) {
                $ciudad = $ciudad !== '' ? $ciudad : $posibleCiudad;
                $izq = $resto;
            }
        }
        if ($ciudad === '' && str_contains($der, ',')) {
            $partes = array_map('trim', explode(',', $der));
            $ultima = (string) array_pop($partes);
            $resto = trim(implode(',', $partes));
            if ($resto !== '' && self::pareceCalle($resto)) {
                $ciudad = $ultima;
                $der = $resto;
            }
        }

        // Si todavía queda una coma es que el fragmento era "Paysandu, la", o
        // sea un corte mal tomado: no es el nombre de una calle y ensuciaría
        // el pedido a Overpass.
        if (str_contains($izq, ',') || str_contains($der, ',')) {
            return;
        }

        self::agregarSiSirve($salida, $izq, $der, $etiqueta, $ciudad);

        // Recién acá, con la ciudad ya afuera, se pelan las palabras de
        // relleno. Antes no servía: en "Paysandu, la esquina Treinta y Tres"
        // la coma inicial bloqueaba el pelado, y al separar la ciudad quedaba
        // un "la esquina" que nadie se comía. Sin esto, "la esquina Treinta y
        // Tres Orientales" iba a Overpass tal cual y nunca encontraba la calle.
        $izqPelada = self::limpiarRelleno($izq);
        $derPelada = self::limpiarRelleno($der);
        if ($izqPelada !== $izq || $derPelada !== $der) {
            self::agregarSiSirve($salida, $izqPelada, $derPelada, $etiqueta, $ciudad);
        }
    }

    /**
     * Agrega el par a la lista si los dos lados pueden ser nombres de calle.
     *
     * @param array<int, array{calle_a: string, calle_b: string, etiqueta: string, ciudad: string}> $salida
     */
    private static function agregarSiSirve(array &$salida, string $izq, string $der, string $etiqueta, string $ciudad): void
    {
        if (!self::pareceCalle($izq) || !self::pareceCalle($der)) {
            return;
        }

        foreach ($salida as $ya) {
            if ($ya['calle_a'] === $izq && $ya['calle_b'] === $der) {
                return;
            }
        }

        $salida[] = [
            'calle_a' => $izq,
            'calle_b' => $der,
            'etiqueta' => $etiqueta,
            'ciudad'   => $ciudad,
        ];
    }

    /**
     * ¿Existen las dos calles de verdad?
     *
     * Sirve de filtro previo: si el usuario escribió "xyz abc y def",
     * Geoapify no conoce ninguna de las dos y nos ahorramos la consulta a
     * Overpass, que es la parte lenta y la que se cae.
     *
     * Se exigen las DOS, no una sola. Geoapify devuelve basura difusa para
     * cualquier texto con que ver ("xyz abc" trae dos resultados que no son
     * una calle), así que con exigir una el filtro no filtraba nada. En
     * cambio un cruce real siempre tiene las dos calles registradas.
     *
     * No se exige que el resultado sea de tipo calle: "Andresito" solo
     * aparece como localidad, pero la calle existe igual y el cruce es real.
     */
    private static function ambasCallesExisten(array $candidatos, ?string $ciudad): bool
    {
        foreach (array_slice($candidatos, 0, 3) as $c) {
            $ok = true;
            foreach ([$c['calle_a'], $c['calle_b']] as $calle) {
                if (!self::geocodificaAlgo($calle, $ciudad)) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                return true;
            }
        }

        return false;
    }

    /** ¿Geoapify reconoce esta calle, con o sin palabras de relleno? */
    private static function geocodificaAlgo(string $calle, ?string $ciudad): bool
    {
        $pelada = self::calleDe(self::limpiarRelleno($calle));
        $literal = self::calleDe($calle);
        $probadas = $pelada !== '' && $pelada !== $literal
            ? [$pelada, $literal]
            : [$literal];

        foreach ($probadas as $nombre) {
            if ($nombre === '') {
                continue;
            }
            $consulta = $ciudad !== null && $ciudad !== ''
                ? $nombre . ', ' . $ciudad
                : $nombre;

            if (GeoapifyService::buscar($consulta, 5) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * Todas las ways con esos nombres dentro del bbox, agrupadas por nombre
     * normalizado. Cada elemento es una polilínea: [[lat, lng], ...].
     *
     * @return array<string, array<int, array<int, array{0: float, 1: float}>>>
     */
    private static function viasDe(array $nombres, array $bbox): array
    {
        if ($nombres === []) {
            return [];
        }

        $consulta = self::consultaOverpass($nombres, $bbox);
        $respuesta = self::consultarOverpass($consulta);
        if ($respuesta === null) {
            return [];
        }

        $vias = [];
        $utiles = [];
        foreach ((array) ($respuesta['elements'] ?? []) as $el) {
            if (!is_array($el) || ($el['type'] ?? '') !== 'way') {
                continue;
            }
            $nombre = (string) ($el['tags']['name'] ?? '');
            if ($nombre === '') {
                continue;
            }
            $clave = self::normalizar($nombre);
            if ($clave === '' || !in_array($clave, $nombres, true)) {
                continue;
            }

            $pts = [];
            foreach ((array) ($el['geometry'] ?? []) as $p) {
                if (is_array($p) && isset($p['lat'], $p['lon'])) {
                    $pts[] = [(float) $p['lat'], (float) $p['lon']];
                }
            }
            if (count($pts) >= 2) {
                $vias[$clave][] = $pts;
                $utiles[] = $el;
            }
        }

        // Se reescribe la caché con lo que realmente interestsó. Sin esto,
        // una consulta que Overpass responde bien pero sin cruce ("Avenida
        // Italia y Avenida Brasil", que en OSM no se tocan) se volvía a
        // pagar los segundos de la consulta cada vez que se escribía.
        self::escribirCache(self::claveDeConsultaOverpass($consulta), ['elements' => $utiles], self::CACHE_TTL);

        return $vias;
    }

    private static function claveDeConsultaOverpass(string $consulta): string
    {
        return 'ov_' . substr(hash('sha256', $consulta), 0, 40);
    }

    /**
     * Punto de cruce entre dos conjuntos de polilíneas: el par de puntos más
     * cercano entre ambas. Si están separadas por más que la tolerancia, no
     * hay cruce y se devuelve null.
     *
     * @return array{lat: float, lng: float, distancia: float}|null
     */
    private static function puntoDeCruce(array $a, array $b): ?array
    {
        $mejor = null;

        foreach ($a as $poliA) {
            foreach ($b as $poliB) {
                // Descarta el par si las cajas no se tocan ni de cerca: en una
                // ciudad hay muchas calles y esto evita la mayoría de las
                // comparaciones de segmentos.
                if (!self::cajasCerca($poliA, $poliB)) {
                    continue;
                }
                $r = self::cercania($poliA, $poliB);
                if ($r !== null && ($mejor === null || $r['distancia'] < $mejor['distancia'])) {
                    $mejor = $r;
                }
            }
        }

        if ($mejor === null || $mejor['distancia'] > self::TOLERANCIA_METROS) {
            return null;
        }

        return $mejor;
    }

    // ------------------------------------------------------------------
    // Bbox
    // ------------------------------------------------------------------

    /**
     * Caja donde buscar las calles. Si el usuario escribió ciudad se usa la
     * suya; si no, se usa el punto de referencia con un radio fijo.
     *
     * @return array{0: float, 1: float, 2: float, 3: float}|null [sLat, oLng, nLat, eLng]
     */
    private static function bboxDe(?string $ciudad, ?float $proxLat, ?float $proxLng): ?array
    {
        if ($ciudad !== null && $ciudad !== '') {
            $sug = GeoapifyService::buscar($ciudad . ', Uruguay', 8);
            foreach ($sug as $s) {
                // Importante: la caja tiene que ser de una LOCALIDAD. Si se
                // acepta la primera, "Salto" resuelve a la calle Salto de
                // Montevideo y se termina buscando el cruce en el barrio
                // equivocado. Se recorre la lista hasta dar con una ciudad.
                if (!in_array((string) ($s['result_type'] ?? $s['categoria'] ?? ''), self::TIPOS_CIUDAD, true)) {
                    continue;
                }
                $bbox = $s['bbox'] ?? null;
                if (is_array($bbox) && count($bbox) === 4) {
                    // Geoapify devuelve [sLng, sLat, nLng, nLat]
                    return [(float) $bbox[1], (float) $bbox[0], (float) $bbox[3], (float) $bbox[2]];
                }
            }
        }

        if ($proxLat === null || $proxLng === null) {
            return null;
        }

        $dLat = self::RADIO_SIN_CIUDAD / 111320.0;
        $dLng = $dLat / max(0.2, cos(deg2rad($proxLat)));

        return [
            $proxLat - $dLat,
            $proxLng - $dLng,
            $proxLat + $dLat,
            $proxLng + $dLng,
        ];
    }

    // ------------------------------------------------------------------
    // Overpass
    // ------------------------------------------------------------------

    private static function consultaOverpass(array $nombres, array $bbox): string
    {
        $partes = [];
        // Se ordenan para que "A y B" y "B y A" produzcan la misma consulta y
        // por lo tanto la misma clave de caché.
        $nombres = array_values($nombres);
        sort($nombres, SORT_STRING);
        foreach ($nombres as $n) {
            $partes[] = self::regexDeNombre($n);
        }

        [$sLat, $oLng, $nLat, $eLng] = $bbox;

        return '[out:json][timeout:8];('
            . 'way["highway"~"^(residential|tertiary|secondary|primary|unclassified|living_street|pedestrian|service|motorway|trunk)$"]'
            . '["name"~"^(' . implode('|', $partes) . ')$",i]('
            . $sLat . ',' . $oLng . ',' . $nLat . ',' . $eLng . ');'
            . ');out geom;';
    }

    /**
     * Convierte un nombre de calle en una expresión regular que Tolera la
     * falta de tilde.
     *
     * Hace falta porque el usuario escribe "Andresito" y en OSM la calle se
     * llama "Andrésito", o al revés. Cada vocal se convierte en una clase
     * que acepta las dos formas, así que el mismo patrón sirve para las dos.
     * El filtro exacto se hace después en PHP con normalizar().
     */
    private static function regexDeNombre(string $nombre): string
    {
        $out = '';
        foreach (preg_split('//u', mb_strtolower(trim($nombre), 'UTF-8'), -1, PREG_SPLIT_NO_EMPTY) as $c) {
            $out .= match ($c) {
                'a'     => '[aá]',
                'e'     => '[eé]',
                'i'     => '[ií]',
                'o'     => '[oó]',
                'u'     => '[uúü]',
                'ñ'     => '[nñ]',
                default => preg_quote($c, '/'),
            };
        }

        return $out;
    }

    /**
     * Consulta Overpass con caché en disco y pruebo de espejos.
     *
     * Dos reglas evitan que se caiga sola:
     *
     *  - Caché negativa: si Overpass falló, esa consulta no se reintenta por
     *    un rato. Sin esto, cada tecla que el usuario escribe vuelve a pegarle
     *    a Overpass y lo deja más saturado todavía, y así nunca se recupera.
     *  - Freno compartido: Overpass tolera unas pocas consultas seguidas y
     *    después devuelve 504. El freno vive en un archivo porque cada request
     *    web corre en su propio proceso y una variable estática no serviría.
     *    Cuando el freno dice que todavía no, NO se espera: se sigue con los
     *    resultados normales de la búsqueda, que para eso están. El cruce
     *    aparece en la siguiente tecla, ya con la caché tibia.
     */
    private static function consultarOverpass(string $consulta): ?array
    {
        $clave = self::claveDeConsultaOverpass($consulta);
        $cacheado = self::leerCache($clave);
        if ($cacheado !== null) {
            // Una entrada marcada como fallo dice "no se pudo", no "no hay".
            return ($cacheado['_fallo'] ?? false) === true ? null : $cacheado;
        }

        if (!self::frenoPermite()) {
            return null;
        }

        $inicio = microtime(true);
        $falloDuro = false;

        foreach (self::ENDPOINTS as $i => [$url, $timeout]) {
            $transcurrido = microtime(true) - $inicio;

            if ($i > 0) {
                // El espejo es último recurso: solo se intenta si el principal
                // se cayó rápido. Si tardó, el presupuesto ya se quemó y
                // conviene devolver los resultados normales de una vez.
                if ($transcurrido > self::ESPEJO_SI_FALLO_RAPIDO_S) {
                    break;
                }
            }

            $queda = self::PRESUPUESTO_S - $transcurrido;
            if ($queda <= 0.5) {
                break;
            }

            $respuesta = self::pedir($url, $consulta, (int) min($timeout, max(1.0, $queda)));
            self::marcarFreno();

            if ($respuesta['timeout']) {
                // Saturado, no roto. No se guarda nada: la próxima tecla lo
                // reintenta. Si se cacheara el fallo, un corte de un minuto
                // dejaría esa consulta sin respuesta durante tres.
                return null;
            }

            if (!$respuesta['ok']) {
                $falloDuro = true;
                continue;
            }

            $datos = json_decode($respuesta['cuerpo'], true);
            if (is_array($datos) && isset($datos['elements'])) {
                self::escribirCache($clave, $datos, self::CACHE_TTL);

                return $datos;
            }

            $falloDuro = true;
        }

        // El fallo se recuerda un rato para no reintentar en la próxima tecla,
        // salvo que haya sido solo saturación: en ese caso se prefiere
        // reintentar a dejar la consulta muda durante minutos.
        if ($falloDuro) {
            self::escribirCache($clave, ['_fallo' => true], self::CACHE_TTL_FALLO);
        }

        return null;
    }

    // ------------------------------------------------------------------
    // Freno compartido entre procesos
    // ------------------------------------------------------------------

    /**
     * ¿Ya pasó el mínimo de segundos desde el último pedido a Overpass?
     *
     * @return bool false si hay que esperar (y no se espera: se sigue normal)
     */
    private static function frenoPermite(): bool
    {
        $ruta = self::rutaFreno();
        if (!is_file($ruta)) {
            return true;
        }
        $ultimo = (int) @file_get_contents($ruta);

        return (time() - $ultimo) >= self::FRENO_S;
    }

    private static function marcarFreno(): void
    {
        $dir = self::CACHE_DIR;
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }
        @file_put_contents(self::rutaFreno(), (string) time());
    }

    private static function rutaFreno(): string
    {
        return self::CACHE_DIR . '/ov_freno';
    }

    /**
     * Pide la consulta a un endpoint.
     *
     * Distingue timeout de fallo duro porque consequences opuestas: un timeout
     * significa "Overpass está saturado ahora", y conviene reintentar en la
     * próxima tecla sin dejar nada escrito. Un 400 o un error de conexión
     * significa "esta consulta no va a funcionar", y ahí sí interesa acordarse
     * para no repetir el pedido.
     *
     * @return array{ok: bool, cuerpo: string, timeout: bool}
     */
    private static function pedir(string $url, string $consulta, int $timeout): array
    {
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'cuerpo' => '', 'timeout' => false];
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $consulta,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: text/plain; charset=utf-8',
                // Overpass exige un User-Agent identificable.
                'User-Agent: ElyraDemo/1.0 (gestor de traslados)',
            ],
        ]);
        $r = curl_exec($ch);
        $codigo = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        curl_close($ch);

        $esTimeout = $errno === CURLE_OPERATION_TIMEDOUT;

        // 504 es "saturado", no "no existe": se prueba el siguiente espejo.
        if ($r === false || $codigo !== 200) {
            return ['ok' => false, 'cuerpo' => '', 'timeout' => $esTimeout];
        }

        return ['ok' => true, 'cuerpo' => (string) $r, 'timeout' => false];
    }

    // ------------------------------------------------------------------
    // Geometría
    // ------------------------------------------------------------------

    /**
     * Par de puntos más cercano entre dos polilíneas, en metros.
     * Si las polilíneas se cortan, la distancia es 0.
     *
     * @return array{lat: float, lng: float, distancia: float}|null
     */
    private static function cercania(array $a, array $b): ?array
    {
        $mejor = null;

        for ($i = 0, $na = count($a) - 1; $i < $na; $i++) {
            for ($j = 0, $nb = count($b) - 1; $j < $nb; $j++) {
                $r = self::segmentoSegmento($a[$i], $a[$i + 1], $b[$j], $b[$j + 1]);
                if ($r['distancia'] < 1e-7) {
                    return ['lat' => $r['lat'], 'lng' => $r['lng'], 'distancia' => 0.0];
                }
                if ($mejor === null || $r['distancia'] < $mejor['distancia']) {
                    $mejor = $r;
                }
            }
        }

        return $mejor;
    }

    /**
     * Punto más cercano entre el segmento p1-p2 y el segmento p3-p4.
     * Trabaja en grados (plano), que a escala de calle equivale a la
     * distância real y evita trigonometría por cada par.
     *
     * @return array{lat: float, lng: float, distancia: float}
     */
    private static function segmentoSegmento(array $p1, array $p2, array $p3, array $p4): array
    {
        $x1 = $p1[1]; $y1 = $p1[0];
        $x2 = $p2[1]; $y2 = $p2[0];
        $x3 = $p3[1]; $y3 = $p3[0];
        $x4 = $p4[1]; $y4 = $p4[0];

        $den = (($x2 - $x1) * ($y4 - $y3)) - (($y2 - $y1) * ($x4 - $x3));

        if (abs($den) < 1e-12) {
            // Paralelos: gana el extremo más cercano.
            $opciones = [
                self::proyectar($x1, $y1, $x3, $y3, $x4, $y4),
                self::proyectar($x2, $y2, $x3, $y3, $x4, $y4),
                self::proyectar($x3, $y3, $x1, $y1, $x2, $y2),
                self::proyectar($x4, $y4, $x1, $y1, $x2, $y2),
            ];
            $mejor = $opciones[0];
            foreach ($opciones as $o) {
                if ($o['distancia'] < $mejor['distancia']) {
                    $mejor = $o;
                }
            }
            return $mejor;
        }

        $t = ((($x3 - $x1) * ($y4 - $y3)) - (($y3 - $y1) * ($x4 - $x3))) / $den;
        $u = ((($x3 - $x1) * ($y2 - $y1)) - (($y3 - $y1) * ($x2 - $x1))) / $den;

        if ($t < 0.0 || $t > 1.0 || $u < 0.0 || $u > 1.0) {
            // Los segmentos no se cruzan dentro de su propio recorrido: el
            // mínimo está en algún extremo.
            $opciones = [
                self::proyectar($x1, $y1, $x3, $y3, $x4, $y4),
                self::proyectar($x2, $y2, $x3, $y3, $x4, $y4),
                self::proyectar($x3, $y3, $x1, $y1, $x2, $y2),
                self::proyectar($x4, $y4, $x1, $y1, $x2, $y2),
            ];
            $mejor = $opciones[0];
            foreach ($opciones as $o) {
                if ($o['distancia'] < $mejor['distancia']) {
                    $mejor = $o;
                }
            }
            return $mejor;
        }

        $x = $x1 + ($t * ($x2 - $x1));
        $y = $y1 + ($t * ($y2 - $y1));

        return ['lat' => $y, 'lng' => $x, 'distancia' => 0.0];
    }

    /** Proyecta un punto sobre un segmento y devuelve el punto y la distancia. */
    private static function proyectar(float $px, float $py, float $ax, float $ay, float $bx, float $by): array
    {
        $dx = $bx - $ax;
        $dy = $by - $ay;
        $len2 = ($dx * $dx) + ($dy * $dy);

        if ($len2 < 1e-15) {
            $d = self::metros($px, $py, $ax, $ay);
            return ['lat' => $ay, 'lng' => $ax, 'distancia' => $d];
        }

        $t = ((($px - $ax) * $dx) + (($py - $ay) * $dy)) / $len2;
        $t = max(0.0, min(1.0, $t));
        $x = $ax + ($t * $dx);
        $y = $ay + ($t * $dy);

        return ['lat' => $y, 'lng' => $x, 'distancia' => self::metros($px, $py, $x, $y)];
    }

    /** Distancia en metros entre dos puntos. A escala de calle el plano sirve. */
    private static function metros(float $lon1, float $lat1, float $lon2, float $lat2): float
    {
        $dx = ($lon2 - $lon1) * cos(deg2rad(($lat1 + $lat2) / 2));
        $dy = $lat2 - $lat1;

        return sqrt(($dx * $dx) + ($dy * $dy)) * 111320.0;
    }

    /**
     * Descarta par de polilíneas cuyas cajas están separadas. Puro recorte:
     * si las cajas no se solapan ni están a menos de la tolerancia, el par no
     * puede ser el cruce.
     */
    private static function cajasCerca(array $a, array $b): bool
    {
        $ca = self::caja($a);
        $cb = self::caja($b);

        // Margen de 0.0003 grados (~33 m) para que una polilínea que termina
        // justo en la esquina no se descarte por un milésimo de grado.
        if ($ca['nLat'] + self::TOLERANCIAS_LAT < $cb['sLat'] || $cb['nLat'] + self::TOLERANCIAS_LAT < $ca['sLat']) {
            return false;
        }
        if ($ca['eLng'] + self::TOLERANCIAS_LAT < $cb['oLng'] || $cb['eLng'] + self::TOLERANCIAS_LAT < $ca['oLng']) {
            return false;
        }

        return true;
    }

    /** @return array{sLat: float, oLng: float, nLat: float, eLng: float} */
    private static function caja(array $pts): array
    {
        $sLat = INF; $oLng = INF; $nLat = -INF; $eLng = -INF;
        foreach ($pts as $p) {
            $sLat = min($sLat, $p[0]);
            $nLat = max($nLat, $p[0]);
            $oLng = min($oLng, $p[1]);
            $eLng = max($eLng, $p[1]);
        }

        return ['sLat' => $sLat, 'oLng' => $oLng, 'nLat' => $nLat, 'eLng' => $eLng];
    }

    // ------------------------------------------------------------------
    // Utilidades de texto
    // ------------------------------------------------------------------

    /**
     * Todos los puntos donde el texto se podría partir en dos calles.
     *
     * @return array<int, array{izq: string, der: string, etiqueta: string}>
     */
    private static function posiblesCortes(string $texto): array
    {
        $cortes = [];

        foreach (self::CONECTORES as $conector => $etiqueta) {
            $busqueda = mb_strtolower($texto, 'UTF-8');
            $aguja = mb_strtolower($conector, 'UTF-8');
            $desde = 0;

            while (($pos = mb_strpos($busqueda, $aguja, $desde, 'UTF-8')) !== false) {
                $cortes[] = [
                    // mb_strpos() cuenta caracteres y substr() cuenta bytes:
                    // con "Paysandú" los cortes salen corridos. Todo el
                    // corte tiene que ser con mb_substr() para que no dependa
                    // de si el texto tiene tildes.
                    'izq'      => mb_substr($texto, 0, $pos, 'UTF-8'),
                    'der'      => mb_substr($texto, $pos + mb_strlen($conector, 'UTF-8'), null, 'UTF-8'),
                    'etiqueta' => $etiqueta,
                ];
                $desde = $pos + mb_strlen($aguja, 'UTF-8');
            }
        }

        // El texto más largo primero: si "Treinta y Tres Orientales y
        // Andresito" tiene un corte válido en el segundo "y", se prueba ese.
        usort($cortes, static function (array $x, array $y): int {
            return strlen($y['izq']) <=> strlen($x['izq']);
        });

        return $cortes;
    }

    /** ¿El fragmento puede ser el nombre de una calle? */
    private static function pareceCalle(string $texto): bool
    {
        $limpio = trim($texto);
        if (mb_strlen($limpio) < 3 || mb_strlen($limpio) > 80) {
            return false;
        }
        // "12345" es un número de puerta, no una calle.
        if (preg_match('/^[\d\s]+$/', $limpio)) {
            return false;
        }
        // No puede empezar ni terminar con un conector: son residuo del corte.
        foreach (array_keys(self::CONECTORES) as $conector) {
            $c = trim($conector);
            if (mb_stripos($limpio, $c, 0, 'UTF-8') === 0
                || mb_substr($limpio, -mb_strlen($c, 'UTF-8'), null, 'UTF-8') === $c) {
                return false;
            }
        }

        return true;
    }

    /** Nombre de la ciudad que el usuario escribió, si escribió alguno. */
    private static function primerNombreDistintivo(array $candidatos): ?string
    {
        foreach ($candidatos as $c) {
            $ciudad = trim((string) $c['ciudad']);
            // "Paysandu" son 7 letras; "Pando" también, así que se exige un
            // mínimo razonable.
            if ($ciudad !== '' && mb_strlen($ciudad) >= 4 && self::pareceCalle($ciudad)) {
                return $ciudad;
            }
        }

        return null;
    }

    /** Etiqueta visible para el cruce. */
    private static function etiqueta(array $candidato, ?string $ciudad): string
    {
        $conector = trim((string) $candidato['etiqueta']);
        // Sin el número de puerta: "Uruguay y Brasil" se lee mejor que
        // "Uruguay 2455 y Brasil" como nombre de un cruce de calles.
        $a = self::calleDe($candidato['calle_a']);
        $b = self::calleDe($candidato['calle_b']);
        $base = $a . ' ' . $conector . ' ' . $b;

        if ($ciudad !== null && $ciudad !== '') {
            $base .= ', ' . $ciudad;
        }

        return $base;
    }

    /**
     * Minúsculas, sin acentos y sin signos: así "Andrésito" y "Andresito"
     * son la misma calle y "Treinta y Tres" no se rompe por sus espacios.
     */
    private static function normalizar(string $texto): string
    {
        $t = mb_strtolower(trim($texto), 'UTF-8');
        $t = strtr($t, [
            'á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n',
            'Á'=>'a','É'=>'e','Í'=>'i','Ó'=>'o','Ú'=>'u','Ü'=>'u','Ñ'=>'n',
        ]);
        $t = preg_replace('/[^a-z0-9]+/u', ' ', $t);

        return trim((string) $t);
    }

    /**
     * El nombre de la calle como lo entiende OSM: sin número de puerta.
     *
     * "Uruguay 2455" es el nombre "Uruguay" con la dirección 2455 pegada.
     * Si se busca en OSM el texto entero no aparece nunca, y en OSM las calles
     * no tienen número: el número vive en las direcciones.
     */
    private static function calleDe(string $texto): string
    {
        $limpio = trim($texto);

        // Número de puerta al final: "Uruguay 2455", "Uruguay 2455 bis".
        $limpio = (string) preg_replace('/[\s,]+\d+\s*(?:bis|ter|quater)?\s*$/iu', '', $limpio);
        // Número adelante: "2455 Uruguay".
        $limpio = (string) preg_replace('/^\d+\s*(?:bis|ter|quater)?\s*[,.]?\s*/iu', '', $limpio);

        return trim($limpio);
    }

    /** Nombre de calle ya limpio y normalizado, listo para comparar. */
    private static function claveDeCalle(string $texto): string
    {
        return self::normalizar(self::calleDe($texto));
    }

    /**
     * Clave de un nombre de calle del lado de la consulta: sin palabras de
     * relleno. "la esquina Treinta y Tres Orientales" se pide como
     * "Treinta y Tres Orientales".
     *
     * Ojo con la asimetría: al normalizar los nombres que DEVUELVE OpenStreetMap
     * no se toca nada, porque ahí el texto es el real. Si a "La Paz" le
     * quitáramos el artículo del lado de OSM, dejaríamos de encontrarla.
     */
    private static function claveDeConsulta(string $texto): string
    {
        return self::normalizar(self::calleDe(self::limpiarRelleno($texto)));
    }


    // ------------------------------------------------------------------
    // Caché
    // ------------------------------------------------------------------

    private static function leerCache(string $clave): ?array
    {
        $ruta = self::rutaCache($clave);
        if (!is_file($ruta)) {
            return null;
        }
        $datos = json_decode((string) @file_get_contents($ruta), true);
        if (!is_array($datos)) {
            return null;
        }
        // El vencimiento viaja dentro del archivo: una entrada de éxito vive
        // un mes y una de fallo unos minutos, y comparten el mismo formato.
        if ((int) ($datos['_vence'] ?? 0) <= time()) {
            return null;
        }

        return $datos;
    }

    private static function escribirCache(string $clave, array $valor, int $ttl): void
    {
        $dir = self::CACHE_DIR;
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }

        $ruta = self::rutaCache($clave);
        // Escritura atómica: si dos peticiones compiten, el archivo nunca
        // queda a medio escribir.
        $tmp = $ruta . '.' . getmypid() . '.tmp';
        $valor['_vence'] = time() + $ttl;
        if (@file_put_contents($tmp, json_encode($valor)) !== false) {
            @rename($tmp, $ruta);
        }
    }

    private static function rutaCache(string $clave): string
    {
        return self::CACHE_DIR . '/' . $clave . '.json';
    }
}