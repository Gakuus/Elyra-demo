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

    // --- Almacenamiento ---
    // Raíz del almacenamiento privado (PDFs, logs, rate-limit y cachés).
    // Vacío = <raíz del proyecto>/storage. En el servidor ITS P se puede
    // dejar vacío si el proyecto se clona en /var/www/elyra, o poner la ruta
    // absoluta si el hosting habilita otra carpeta escribible para www-data.
    'STORAGE_PATH'   => '',

    // --- Almacenamiento de documentos (PDFs) ---
    // disco = archivo en storage/docs | bd = bytes en MySQL | auto = prueba
    // disco y, si el usuario de PHP no puede escribir, cae a BD (recomendado
    // en el servidor ITS P, donde no hay root para cambiar permisos).
    'PDF_DRIVER'     => 'auto',

    // --- Geocodificación / rutas ---
    'GEOAPIFY_API_KEY' => '',
    'GEOPROVIDER'      => 'geoapify',

    // --- Captcha del login (Google reCAPTCHA v2) ---
    // Sin ambas claves el captcha no se muestra. La clave de sitio es pública;
    // la secreta se usa solo en el servidor y nunca se imprime ni se registra.
    'RECAPTCHA_SITE_KEY'   => '',
    'RECAPTCHA_SECRET_KEY' => '',

    // --- Chatbot de Elyra (IA gratuita) ---
    // groq | openrouter | gemini | openai | custom. Si se deja vacío, se
    // deduce del prefijo de la clave (gsk_ = Groq, sk-or- = OpenRouter,
    // AIza = Gemini). Sin clave, el widget avisa que no está configurado.
    // La clave se usa SOLO del lado del servidor: nunca llega al navegador.
    'CHAT_PROVIDER' => '',
    'CHAT_API_KEY'  => '',
    'CHAT_MODEL'    => '',
    'CHAT_BASE_URL' => '',

    // --- Correo saliente (recuperación de contraseña) ---
    // MAIL_PASSWORD es una "contraseña de aplicación" de Google, no la de la cuenta.
    'MAIL_HOST'      => 'smtp.gmail.com',
    'MAIL_PORT'      => '587',
    'MAIL_USERNAME'  => 'tu_correo@gmail.com',
    'MAIL_PASSWORD'  => 'contrasena_de_aplicacion',
    'MAIL_FROM'      => 'tu_correo@gmail.com',
    'MAIL_FROM_NAME' => 'Elyra',
];
