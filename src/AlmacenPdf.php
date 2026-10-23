<?php

declare(strict_types=1);

/**
 * AlmacenPdf: capa de almacenamiento de los PDFs de documentos.
 *
 * Antes, DocumentoController escribía siempre en storage/docs con
 * move_uploaded_file(). En un servidor donde el usuario de PHP (www-data) no es
 * dueño de esa carpeta, y sin root no se puede hacer chown, la subida fallaba.
 *
 * Acá se desacopla "dónde vive el PDF" del controlador, con dos drivers:
 *
 *   - disco : archivo en storage/docs (rápido, el caso normal).
 *   - bd    : bytes en documento.archivo_contenido (longblob). No depende de
 *             permisos del filesystem, así que funciona sin root.
 *
 * Se elige con PDF_DRIVER (config/env.php o .env):
 *   - auto  (por defecto): intenta disco y, si no puede, cae a BD.
 *   - disco: solo disco; si falla, error.
 *   - bd   : solo BD.
 *
 * En la BD se guarda una CLAVE relativa (el nombre del archivo), no una ruta
 * absoluta: así el proyecto se puede mover de carpeta/servidor sin romper los
 * enlaces. Las filas viejas con ruta absoluta se siguen leyendo.
 *
 * Para escalar a varios servidores, el próximo paso es un driver de
 * almacenamiento por objetos (S3 / Spaces / MinIO): se agrega acá sin tocar
 * los controladores, que ya no saben dónde vive el archivo.
 */
final class AlmacenPdf
{
    public const DISCO = 'disco';
    public const BD    = 'bd';
    public const AUTO  = 'auto';

    /** Carpeta física de los PDFs en el driver disco. */
    public static function dir(): string
    {
        return storage_path('docs');
    }

    /** Driver configurado (PDF_DRIVER), validado contra los valores conocidos. */
    public static function driver(): string
    {
        $valor = strtolower(trim((string) ($_ENV['PDF_DRIVER'] ?? self::AUTO)));
        return in_array($valor, [self::AUTO, self::DISCO, self::BD], true) ? $valor : self::AUTO;
    }

    /**
     * Guarda el PDF recién subido bajo la clave indicada.
     * Devuelve ['path' => clave, 'contenido' => ?string] listo para el INSERT:
     * 'contenido' es null cuando quedó en disco, o los bytes cuando quedó en BD.
     * Lanza RuntimeException si ningún driver pudo guardarlo.
     */
    public static function guardar(array $archivo, string $clave): array
    {
        $driver = self::driver();

        if ($driver === self::DISCO || $driver === self::AUTO) {
            $motivo = self::guardarEnDisco((string) $archivo['tmp_name'], $clave);

            if ($motivo === null) {
                return ['path' => $clave, 'contenido' => null];
            }
            if ($driver === self::DISCO) {
                log_seguridad('pdf_guardar_disco_fallido', ['clave' => $clave, 'motivo' => $motivo]);
                throw new RuntimeException($motivo);
            }
            // auto: se registra el motivo real y se continúa con la BD.
            log_seguridad('pdf_fallback_bd', ['clave' => $clave, 'motivo' => $motivo]);
        }

        $bytes = @file_get_contents((string) $archivo['tmp_name']);
        if ($bytes === false) {
            log_seguridad('pdf_leer_temporal_fallido', ['clave' => $clave]);
            throw new RuntimeException('No se pudo leer el archivo temporal de la subida.');
        }

        return ['path' => $clave, 'contenido' => $bytes];
    }

    /** Intenta escribir en storage/docs. Devuelve null si pudo, o el motivo del fallo. */
    private static function guardarEnDisco(string $tmp, string $clave): ?string
    {
        $dir = self::dir();

        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return 'No se pudo crear la carpeta storage/docs.';
        }
        if (!is_writable($dir)) {
            return 'La carpeta storage/docs no tiene permisos de escritura para el usuario de PHP.';
        }
        if (!@move_uploaded_file($tmp, $dir . '/' . $clave)) {
            return 'move_uploaded_file() falló al escribir en storage/docs.';
        }
        return null;
    }

    /**
     * Ubica el PDF de una fila: bytes (BD) o ruta real en disco.
     * Devuelve ['bd' => string] | ['disco' => string] | null si no existe.
     */
    public static function ubicar(?string $clave, ?string $contenido): ?array
    {
        if ($contenido !== null && $contenido !== '') {
            return ['bd' => (string) $contenido];
        }
        $ruta = self::rutaEnDisco($clave);
        return $ruta === null ? null : ['disco' => $ruta];
    }

    /**
     * Resuelve la clave (relativa) a una ruta real dentro de storage/docs.
     * Acepta también rutas absolutas de filas viejas. Devuelve null si queda
     * fuera de storage/docs: evita leer rutas manipuladas en la BD.
     */
    public static function rutaEnDisco(?string $clave): ?string
    {
        if ($clave === null || $clave === '') {
            return null;
        }
        $absoluta  = str_starts_with($clave, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $clave) === 1;
        $candidato = $absoluta ? $clave : self::dir() . '/' . basename($clave);
        return ruta_pdf_segura($candidato);
    }
}
