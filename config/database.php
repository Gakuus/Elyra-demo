<?php

declare(strict_types=1);

/**
 * database.php: configuración y conexión a la base de datos MySQL.
 * Define la función global db_connect() que todos los controladores usan
 * para obtener la conexión PDO.
 */

/**
 * Devuelve la conexión activa a MySQL.
 *
 * Usa una variable estática ($pdo) para que la conexión se cree UNA sola
 * vez por petición. Si ya existe, la reutiliza (evita abrir miles de
 * conexiones y es mucho más rápido).
 */
function db_connect(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        // Lee los datos de conexión desde las variables de entorno.
        // El archivo .env se carga en index.php y queda disponible en $_ENV.
        $host = $_ENV['DB_HOST'] ?? '';
        $port = $_ENV['DB_PORT'] ?? '';
        $database = $_ENV['DB_DATABASE'] ?? '';
        $username = $_ENV['DB_USERNAME'] ?? '';
        $password = $_ENV['DB_PASSWORD'] ?? '';

        // DSN: la cadena de conexión que PDO necesita para hablar con MySQL.
        // charset=utf8mb4 garantiza soporte de tildes y emojis.
        $dsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";

        // Crea la conexión con opciones de seguridad:
        $pdo = new PDO($dsn, $username, $password, [
            // 1) Los errores SQL lanzan excepciones (más fácil de controlar).
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            // 2) Las consultas devuelven arrays asociativos (columna => valor).
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // 3) Prepara las consultas en el servidor MySQL (más seguro,
            //    protege de inyección SQL).
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    return $pdo;
}
