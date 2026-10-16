<?php

/**
 * config/env.example.php — Plantilla de configuración por PHP.
 *
 * Copiar a config/env.php y completar con los valores reales.
 *
 * ¿Por qué usar este archivo en vez de `.env`?
 *   El servidor (nginx + PHP-FPM) EJECUTA los archivos .php, así que un archivo
 *   de configuración en PHP nunca se descarga por HTTP: quien pida la URL solo
 *   recibe la salida (vacía) del script. Un `.env`, en cambio, es un archivo de
 *   texto estático: si el docroot apunta a la raíz del proyecto, cualquiera
 *   puede abrir https://tu-dominio/.env y leer las credenciales.
 *   Por eso, en producción, se recomienda usar config/env.php y BORRAR el .env.
 *
 * Prioridad: los valores de config/env.php pisan a los del .env.
 *
 * config/env.php NO se versiona (está en .gitignore): nunca subas este archivo
 * con credenciales reales al repositorio.
 */

return [
    // --- Aplicación ---
    'APP_URL'        => 'https://itspvm.duckdns.org/proyectos/elyra',
    'APP_TIMEZONE'   => 'America/Montevideo',
    'APP_SECRET'     => 'cadena_larga_y_aleatoria_para_firmar_tokens',

    // --- Base de datos ---
    'DB_HOST'        => '127.0.0.1',
    'DB_PORT'        => '3306',
    'DB_DATABASE'    => 'elyra',
    'DB_USERNAME'    => 'elyra',
    'DB_PASSWORD'    => 'tu_contrasena_segura',
    'DB_SOCKET'      => '',

    // --- Geocodificación / rutas ---
    'GEOAPIFY_API_KEY' => '',
    'GEOPROVIDER'      => 'geoapify',

    // --- Correo saliente (recuperación de contraseña) ---
    // MAIL_PASSWORD es una "contraseña de aplicación" de Google, no la de la cuenta.
    'MAIL_HOST'      => 'smtp.gmail.com',
    'MAIL_PORT'      => '587',
    'MAIL_USERNAME'  => 'tu_correo@gmail.com',
    'MAIL_PASSWORD'  => 'contrasena_de_aplicacion',
    'MAIL_FROM'      => 'tu_correo@gmail.com',
    'MAIL_FROM_NAME' => 'Elyra',
];
