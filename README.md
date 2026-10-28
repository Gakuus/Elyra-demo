# Elyra Demo

> Sistema de gestión hospitalaria para el **Hospital de Clínicas**.
> Front controller en PHP con autenticación, traslados con mapa en vivo, encuestas, documentos, vehículos, insumos, equipamiento y usuarios.

📊 **Documentación visual:** [Diagramas de actividad](docs/diagrama_actividad.md) · [Versión HTML](docs/diagramas_actividad.html) · [PDF](docs/diagramas_actividad.pdf)

[![License: CC BY-NC 4.0](https://img.shields.io/badge/License-CC_BY--NC_4.0-lightgrey.svg)](https://creativecommons.org/licenses/by-nc/4.0/)
[![CI](https://github.com/Gakuus/Elyra-demo/actions/workflows/ci.yml/badge.svg)](https://github.com/Gakuus/Elyra-demo/actions/workflows/ci.yml)
[![PHP](https://img.shields.io/badge/PHP-%3E%3D8.1-777BB4?logo=php&logoColor=white)](https://php.net)
[![GitHub last commit](https://img.shields.io/github/last-commit/Gakuus/Elyra-demo)](https://github.com/Gakuus/Elyra-demo/commits)
[![Repo size](https://img.shields.io/github/repo-size/Gakuus/Elyra-demo)](https://github.com/Gakuus/Elyra-demo)

## Tabla de contenidos

- [Funcionalidades](#funcionalidades)
- [Stack tecnologico](#stack-tecnologico)
- [Estructura del proyecto](#estructura-del-proyecto)
- [Base de datos](#base-de-datos)
- [Roles y permisos](#roles-y-permisos)
- [Instalacion local](#instalacion-local)
- [Despliegue en servidor ITS P](#despliegue-en-servidor-its-p)
- [Desarrollo](#desarrollo)
- [API de rutas](#api-de-rutas)
- [Tema visual](#tema-visual)
- [Roadmap](#roadmap)
- [Acerca de](#acerca-de)
- [Licencia](#licencia)

---

## Funcionalidades

### Gestion de traslados y mapa en vivo
- Registro de traslados (paciente, insumo, equipamiento u organo) con conductor, copiloto, vehiculo, ruta, origen y destino.
- Flujo de estados: pendiente, en curso, en destino, en retorno, completado (con cancelacion y motivo).
- Historial de cambios de estado por cada traslado.
- Mapa en vivo (Leaflet) con la posicion GPS de los conductores y catalogo de ubicaciones.
- Geocodificacion de direcciones (Nominatim/Geoapify) y calculo de ruta real (OSRM/Geoapify).

### Gestion de documentacion para pacientes
- Carga y clasificacion de documentos informativos por tipo.
- Generacion automatica de codigo QR por documento.
- Panel de administracion con busqueda y filtros.
- Vista publica: el paciente escanea el QR y ve sus documentos.

### Encuestas de satisfaccion
- Creacion de encuestas con preguntas de opcion multiple, escala (1-5) y texto libre.
- Respuesta anonima o vinculada a paciente mediante token QR.
- Resultados con graficos estadisticos.

### Gestion de vehiculos
- ABM completo de vehiculos (patente, modelo, anio).
- Listado con busqueda y filtros.

### Gestion de insumos medicos
- ABM de insumos con control de stock.
- Activar/desactivar insumos (solo admin).
- Alerta de stock critico (menos de 5 unidades).

### Gestion de equipamiento medico
- ABM de equipamiento con control de stock.
- Activar/desactivar equipamiento (solo admin).

### Gestion de organos
- ABM de organos para traslado con control de stock.
- Activar/desactivar organos (solo admin).

### Gestion de usuarios
- Creacion de usuarios, busqueda por cedula o nombre y ficha con edicion de datos.
- Gestion de codigos de funcionario para el registro.
- Activar/desactivar usuarios.

### Cuenta y seguridad
- Perfil propio: ver datos, editar contacto y cambiar contrasena.
- Recuperacion de contrasena por email (enlace con token, via SMTP/PHPMailer).

### Panel de administracion
- Dashboard con acceso diferenciado por rol.
- Menu lateral con secciones: Inicio, Mapa, Vehiculos, Insumos, Equipamiento, Organos, Documentos, Encuestas, Usuarios.

### Pagina publica
- Home publico con informacion del hospital.
- Vista de documentos escaneando QR.
- Formulario de registro para pacientes.

### Idiomas (espanol / ingles)
- Interfaz completa en espanol o ingles, incluido el panel, los modulos y las vistas publicas.
- Selector ES | EN en el encabezado y en las paginas de login, registro y acceso publico.
- Preferencia persistida en sesion y cookie, con deteccion automatica del idioma del navegador.
- Diccionario propio en `src/lang/en.php` (sin servicios externos de traduccion).

### Accesibilidad
- Widget propio (sin servicios externos) en todas las paginas: boton flotante y panel de preferencias.
- Tamano de texto en 5 niveles (110% a 200%), alto contraste, inversion de colores y escala de grises.
- Fuente de lectura facil, espaciado de texto, resaltado de enlaces, encabezados y foco, cursor grande, guia de lectura, pausa de animaciones y ocultamiento de imagenes.
- Lectura en voz alta con la Web Speech API: pagina completa, seleccion o bloque al hacer clic, con idioma `es-UY` / `en-US`.
- Preferencias persistidas en `localStorage` (`elyra_accesibilidad_v1`) y aplicadas antes del primer pintado.
- Atajo `Alt + A` para abrir o cerrar; `Escape` cierra; el foco queda atrapado dentro del panel.
- El panel queda excluido de los filtros de color para que siga siendo legible en cualquier modo.

### Chatbot de Elyra (IA gratuita)
- La mascota Elyra (dragon amarillo con sombrero de mago) responde en todas las paginas: panel, login, registro, encuesta publica y 404.
- Proveedor gratuito configurable: Groq, OpenRouter, Gemini u otro compatible con OpenAI. La clave se usa solo en el servidor.
- Respuestas en el idioma activo (ES/EN) y con datos reales segun el rol: agregados para admin/superadmin, traslados propios para conductor/copiloto y paciente. Los visitantes solo reciben informacion general.
- Nunca expone nombres de pacientes, documentos, correos ni credenciales. Si el proveedor falla, muestra un error claro en lugar de inventar una respuesta.
- Sin clave el widget aparece igual, avisa que falta configurarlo y deshabilita la entrada.
- Conversacion por pestana en `sessionStorage`, atajo `Alt + M`, `Escape` para cerrar, foco atrapado y sugerencias iniciales.
- Limites: 600 caracteres por mensaje, 8 mensajes de contexto, 400 tokens de salida, 25 s de espera y 40 consultas cada 5 minutos por IP.

---

## Stack tecnologico

| Capa | Tecnologia |
|---|---|
| Lenguaje | PHP 8.1+ (desarrollado con PHP 8.5) |
| Base de datos | MySQL / MariaDB |
| Frontend | HTML5, CSS3, JavaScript (ES6+) |
| Plantillas | Sistema de marcadores propio (mustache-like) |
| Iconos | Bootstrap Icons, FamFamFam Silk |
| Arquitectura | Front controller + require_once |

---

## Estructura del proyecto

```
/
├── config/
│   ├── database.php            → Conexion PDO a MySQL
│   └── env.example.php         → Plantilla de configuracion por PHP (copiar a env.php)
├── database/
│   ├── esquema.sql             → Esquema completo de la base de datos (21 tablas)
│   └── script_menu_bd.sh       → Script de administracion de BD por terminal
├── public/
│   ├── css/
│   │   ├── base.css            → Estilos globales
│   │   ├── accesibilidad.css   → Widget y preferencias de accesibilidad
│   │   ├── chatbot.css         → Mascota y panel de chat
│   │   └── pages/              → Estilos por seccion
│   ├── js/
│   │   ├── elyra.js            → Funciones generales
│   │   ├── comun/              → Utilidades compartidas (idioma.js, accesibilidad.js, chatbot.js)
│   │   ├── mapa/               → Modulos del mapa en vivo
│   │   ├── traslado/           → Modal de registro de traslado
│   │   └── pages/              → JS por modulo
│   ├── img/                    → Logos e imagenes
│   ├── manifest.json           → PWA basica
│   └── uploads/                → Reservada para archivos públicos (sin uso hoy)
├── src/
│   ├── Auth.php                → Autenticacion (login, registro, sesion)
│   ├── Accesibilidad.php       → Widget de accesibilidad (inyeccion y HTML)
│   ├── Chatbot.php             → Mascota Elyra: widget, proveedor de IA y contexto por rol
│   ├── I18n.php                → Motor de idiomas (espanol / ingles)
│   ├── helpers.php             → Funciones auxiliares (render, guards, CSRF, tokens)
│   ├── lang/
│   │   └── en.php              → Diccionario espanol → ingles
│   ├── MailService.php         → Envio de correo (PHPMailer sobre SMTP)
│   ├── GeocodeService.php      → Texto <-> coordenadas (Nominatim)
│   ├── GeoapifyService.php     → Geocodificacion/ruteo (Geoapify)
│   ├── RutaService.php         → Ruta real por calles (OSRM)
│   ├── InterseccionService.php → Intersecciones de calles (Overpass)
│   └── Controller/
│       ├── AuthController.php
│       ├── ChatController.php          → Endpoint JSON del chatbot (/api/chat)
│       ├── DashboardController.php     → Panel, mapa, traslados y APIs
│       ├── DocumentoController.php
│       ├── DocumentoArchivoController.php
│       ├── DocumentoData.php           → Trait de datos (documentos)
│       ├── DocumentoPublicoController.php
│       ├── EncuestaController.php
│       ├── EncuestaData.php            → Trait de datos (encuestas)
│       ├── EncuestaPublicaController.php
│       ├── EncuestaResultadosController.php
│       ├── EquipamientoController.php
│       ├── EquipamientoData.php        → Trait de datos (equipamiento)
│       ├── I18nController.php          → Selector y API de idioma
│       ├── InsumoController.php
│       ├── InsumoData.php              → Trait de datos (insumos)
│       ├── OrganoController.php
│       ├── OrganoData.php              → Trait de datos (organos)
│       ├── PerfilController.php        → Perfil propio (/perfil)
│       ├── UsuarioController.php
│       ├── UsuarioData.php             → Trait de datos (usuarios)
│       ├── UsuarioCodigosController.php
│       ├── UsuarioCreacionController.php
│       ├── UsuarioEdicionController.php
│       └── VehiculoController.php
├── storage/
│   ├── docs/                   → PDFs subidos por usuarios
│   ├── logs/                   → Bitacora de seguridad
│   ├── rate-limit/             → Control de intentos (login, encuesta publica)
│   └── *-cache/                → Cache de geocodificacion y rutas
├── vendor/
│   └── PHPMailer/              → PHPMailer vendorizado (sin Composer)
├── views/
│   ├── auth/                   → Login, registro y recuperacion de contrasena
│   ├── dashboard/              → Panel de administracion
│   │   └── fragmentos/         → Fragmentos HTML para AJAX
│   └── publico/                → Vistas publicas (home, encuesta, documento)
├── index.php                   → Front controller (punto de entrada unico)
├── .env                        → Variables de entorno (credenciales)
├── .gitignore
└── iniciar-phpmyadmin.sh       → Levanta phpMyAdmin local
```

---

## Base de datos

### Tablas principales (21)

- **`usuario`** -- Tabla base (tipo: funcionario | paciente, nombre, apellido, email, documento_identidad).
- **`funcionario`** -- Administradores, superadmins, conductores y copilotos (username, password_hash, rol, licencia, activo).
- **`paciente`** -- Pacientes (token_acceso, username, codigo_qr_id).
- **`codigo_funcionario`** -- Codigos de registro para funcionarios.
- **`codigo_qr`** -- Codigos QR asociados a pacientes.
- **`documento`** -- Documentos informativos (titulo, descripcion, archivo, tipo, paciente asociado).
- **`tipo_documento`** -- Tipos de documento (resumen, analisis, receta, otro).
- **`encuesta`** -- Encuestas de satisfaccion (titulo, descripcion, activa, creada_por).
- **`pregunta`** -- Preguntas (tipo: multiple_choice | escala | texto_libre, texto, orden).
- **`pregunta_opcion`** -- Opciones para preguntas de opcion multiple.
- **`respuesta`** -- Respuestas (sesion_token, valor_opcion, valor_texto, valor_numerico).
- **`vehiculo`** -- Vehiculos (patente, modelo, anio, activo).
- **`ruta`** -- Rutas predefinidas (nombre, origen, destino, distancia_km).
- **`ubicacion`** -- Ubicaciones georreferenciadas (nombre, latitud, longitud, activo).
- **`traslado`** -- Traslados (conductor, copiloto, vehiculo, ruta, origen, destino, estado).
- **`historial_estado`** -- Historial de cambios de estado de traslados.
- **`ubicacion_conductor`** -- Posicion GPS del conductor.
- **`paciente_traslado`** -- Relacion paciente-traslado.
- **`insumo`** -- Insumos medicos (nombre, stock, activo).
- **`equipamiento`** -- Equipamiento medico (nombre, stock, activo).
- **`organo`** -- Organos (nombre, stock, activo).

### Restaurar la base de datos desde backup

```bash
# Descomprimir el backup
tar -xzf database.tar.gz

# Importar el esquema (requiere usuario con permisos)
mysql -u root -p elyra < database/esquema.sql
```

---

## Roles y permisos

| Rol | Acceso |
|---|---|
| `superadmin` | Acceso total: todos los modulos de gestion, administracion de usuarios y codigos. |
| `admin` | Gestion completa: documentos, encuestas, vehiculos, insumos, equipamiento, usuarios y codigos. |
| `conductor` | Traslados propios, reporte de ubicacion GPS y gestion de vehiculos. |
| `copiloto` | Traslados propios y participacion en el mapa. |
| `paciente` | Solo el mapa (sus traslados); acceso publico via QR a documentos y encuestas. |

---

## Instalacion local

### Requisitos

- PHP 8.1+ (con extension `pdo_mysql`)
- MySQL o MariaDB
- Git

> No requiere Composer: PHPMailer viene vendorizado en `vendor/PHPMailer` y se incluye a mano desde `src/MailService.php`.

### Pasos

```bash
# 1. Clonar
git clone git@github.com:Gakuus/Elyra-demo.git
cd Elyra-demo

# 2. Configurar entorno
# Editar el archivo .env con tus credenciales de base de datos:
#   DB_HOST=127.0.0.1
#   DB_PORT=3306
#   DB_DATABASE=elyra
#   DB_USERNAME=elyra
#   DB_PASSWORD=poné_una_contraseña_segura

# 3. Crear la base de datos y importar el esquema
mysql -u root -p < database/esquema.sql

# 4. Iniciar el servidor de desarrollo
php -S 127.0.0.1:8000
# La app queda en http://localhost:8000
```

### phpMyAdmin (opcional)

El proyecto incluye un script para levantar phpMyAdmin localmente:

```bash
bash iniciar-phpmyadmin.sh
# phpMyAdmin en http://localhost:8081
# Usuario BD: el definido en tu .env (DB_USERNAME / DB_PASSWORD)
```

---

## Despliegue en servidor ITS P

1. Crear una cuenta en https://itspvm.duckdns.org/registro/ (o entrar con las credenciales ya otorgadas).
2. Loguearse en https://itspvm.duckdns.org/registro/login. Una vez dentro, tenes un escritorio virtual en el navegador (como una computadora pero web).
3. Abrir la terminal y clonar el proyecto:
   ```bash
   cd /var/www
   git clone git@github.com:Gakuus/Elyra-demo.git elyra
   ```
4. Abrir phpMyAdmin desde el escritorio, crear la base de datos `elyra` e importar el esquema desde `database/esquema.sql`.
5. Configurar las credenciales. **En producción se recomienda `config/env.php`** (no `.env`):
   ```bash
   cd /var/www/elyra
   cp config/env.example.php config/env.php
   nano config/env.php        # completar APP_URL, DB_* y MAIL_*
   ```
   El motivo es de seguridad: el servidor ejecuta los `.php` y **nunca expone
   su contenido por HTTP**, mientras que un `.env` es texto plano y puede quedar
   accesible por URL (`https://itspvm.duckdns.org/proyectos/elyra/.env`). Si
   igual preferís `.env`, existe la plantilla `.env.example`, pero entonces es
   imprescindible lo del paso 6. Usá `APP_URL` con **https**.

6. Endurecer el directorio. El docroot es la **raíz del proyecto** y nginx
   **ignora `.htaccess`**, así que sacá del servidor lo que no debe ser público:
   ```bash
   cd /var/www/elyra
   rm -rf .git phpmyadmin     # historial de git y herramientas locales
   rm -f .env                 # si usás config/env.php
   ```

7. Bloquear lo que quede expuesto. Los `.php` se ejecutan (no filtran), pero los
   archivos estáticos sí. Si tenés acceso a la configuración de nginx, agregá
   este bloque; si no, pasáselo al administrador del servidor:
   ```nginx
   # Bloquea estáticos sensibles del proyecto bajo la subruta.
   location ~ ^/proyectos/elyra/(\.git/|\.env|database/.*\.sql|storage/docs/|README\.md) {
       deny all;
   }
   ```

8. Listo. La app queda accesible desde el mismo escritorio web y en https://itspvm.duckdns.org/proyectos/elyra.

### Rutas de almacenamiento

Todo lo que la app escribe (PDFs, logs, rate-limit y cachés) cuelga de una
sola raíz, configurable con `STORAGE_PATH`:

- **Vacío (por defecto)**: `<raíz del proyecto>/storage`, que en el servidor es
  `/var/www/elyra/storage`. Si el proyecto se clona ahí, no hay que tocar nada.
- **Absoluta**: por ejemplo `/var/www/elyra/storage`, si preferís dejarlo
  explícito en `config/env.php`.
- **Relativa**: se resuelve desde la raíz del proyecto.

Las subcarpetas (`docs`, `logs`, `rate-limit`, `*-cache`) se crean solas; lo
que importa es que el usuario de PHP (`www-data`) pueda escribir en la raíz.
Para verificarlo y, si hay root, corregir permisos:

```bash
cd /var/www/elyra
sudo -u www-data sh -c 'for d in storage storage/docs storage/logs storage/rate-limit; do
  [ -w "$d" ] && echo "OK   $d" || echo "FAIL $d"; done'

chgrp -R www-data storage
find storage -type d -exec chmod 2775 {} \;
find storage -type f -exec chmod 664 {} \;
```

`public/uploads` **no se usa**: ningún controlador lee ni escribe ahí. Los
documentos son privados y viven en `storage/docs` (los sirve el controlador,
nunca directo por URL). No la uses para PDFs: todo lo que está bajo `public/`
se sirve sin autenticación.

### Almacenamiento de PDFs (subida de documentos)

En el servidor, el usuario de PHP (`www-data`) no suele ser el dueño de
`storage/docs` (lo crea el `git clone` con tu usuario). Si no hay root para
hacer `chown`, `move_uploaded_file()` falla y la subida de PDFs da error. El
proyecto lo resuelve con la variable `PDF_DRIVER` (ver `config/env.example.php`):

- `auto` (por defecto): intenta guardar en `storage/docs` y, si no tiene
  permisos, guarda el PDF en MySQL (`documento.archivo_contenido`). El visor y
  los enlaces siguen funcionando igual.
- `disco`: solo `storage/docs`. Requiere que el usuario de PHP pueda escribir.
- `bd`: siempre en MySQL (útil si el disco es de solo lectura o efímero).

El motivo real de cualquier fallo de disco queda en
`storage/logs/seguridad-AAAA-MM-DD.log` (evento `pdf_fallback_bd`).

Además, `php.ini` del servidor trae `upload_max_filesize = 2M`, pero la app
acepta PDFs de hasta 10 MB. Sin root no se puede editar `php.ini`, pero
PHP-FPM sí lee el archivo `.user.ini` del proyecto (ya versionado), que sube el
límite a 10 MB. Verificá con `php -i | grep user_ini` que esté habilitado.

> La recuperación de contraseña se envía por Gmail (SMTP) usando PHPMailer. Las
> variables deben llamarse `MAIL_*` (`MAIL_USERNAME`, `MAIL_PASSWORD`,
> `MAIL_FROM`), **no** `SMTP_*`. Sin ellas la app funciona igual, solo no se
> envían los correos de restablecimiento.

---

## Desarrollo

### Workflow de ramas

```bash
git checkout -b <nombre>-features
# Trabajar en commits pequenos y descriptivos
git commit -m "tipo: descripcion breve"
git push origin <nombre>-features
# Al finalizar, abrir Pull Request contra main
```

### Ramas del proyecto

- `main` -- Version estable
- `tom-features` -- Desarrollo de Tom
- `alan-features` -- Desarrollo de Alan
- `kevin-features` -- Desarrollo de Kevin

### Servidor de desarrollo

```bash
php -S 127.0.0.1:8000
# La app recarga automaticamente los cambios en PHP.
```

### Idiomas

El espanol es el idioma de origen: los textos se escriben directamente en espanol y, cuando el idioma activo es ingles, se traducen con el diccionario `src/lang/en.php`.

- En PHP: `I18n::t('texto')`. Acepta parametros: `I18n::t('Traslado :codigo', ['codigo' => $codigo])`.
- En plantillas: `{{t('texto')}}` (escapado) o `{{{t('texto')}}}` (HTML crudo).
- En JavaScript: `window.ElyraIdioma.t('texto')`. El script tambien traduce automaticamente el DOM que se inserta despues y los `alert` / `confirm` / `prompt`.
- Para agregar un texto nuevo, sumalo como clave en `src/lang/en.php`; si falta, se muestra el espanol.
- El idioma se guarda en `$_SESSION['idioma']` y en la cookie `elyra_idioma`. Sin preferencia guardada se usa `Accept-Language` y, si no hay datos, espanol.

El selector usa `GET /idioma?lang=es|en&volver=<ruta>`. La API `GET/POST /api/idioma` responde JSON; el POST exige el token CSRF global.

### Implementacion de accesibilidad

El widget se inyecta automaticamente en `render_vista()` y `pagina_404()` (`src/helpers.php`), despues de traducir el HTML, asi que aparece en todas las paginas completas sin editar cada plantilla.

- `src/Accesibilidad.php`: HTML, textos traducidos y script temprano del `<head>`.
- `public/css/accesibilidad.css`: UI del panel y efectos sobre el documento.
- `public/js/comun/accesibilidad.js`: estado, persistencia, teclado y lectura en voz alta.

Las preferencias se guardan en `localStorage` con la clave `elyra_accesibilidad_v1`. El script temprano las aplica como atributos `data-acc-*` en `<html>` antes del primer pintado, y el CSS define los efectos. El tamano de texto usa `zoom` sobre `<html>` y contraescala el panel para que siempre entre en pantalla; en celular el encabezado y la barra inferior se mantienen estables y los formularios pasan a una columna cuando hace falta. No hay pedidos a servicios externos: la voz usa `speechSynthesis` del navegador y, si no esta disponible, los botones de lectura se deshabilitan y se muestra una nota.

### Implementacion del chatbot

El widget se inyecta en `render_vista()` y `pagina_404()` (`src/helpers.php`) despues de traducir el HTML, igual que el panel de accesibilidad. El navegador solo habla con `POST /api/chat`; la llamada al proveedor de IA la hace PHP con cURL, asi que la clave nunca llega al navegador y no hace falta abrir el CSP a dominios externos.

- `src/Chatbot.php`: personalidad de Elyra, seleccion del proveedor, llamadas a OpenAI-compatible/Gemini, contexto por rol y errores.
- `src/Controller/ChatController.php`: validacion del cuerpo, limite por IP y respuesta JSON.
- `public/css/chatbot.css` y `public/js/comun/chatbot.js`: mascota, panel, historial, teclado y estados de error.

Configuracion en `.env` o `config/env.php`:

```bash
# groq | openrouter | gemini | openai | custom
CHAT_PROVIDER=groq
CHAT_API_KEY=gsk_...
# Opcionales: modelo y URL base para proveedores compatibles con OpenAI.
CHAT_MODEL=llama-3.3-70b-versatile
CHAT_BASE_URL=
```

Si `CHAT_PROVIDER` queda vacio, se deduce del prefijo de la clave (`gsk_` = Groq, `sk-or-` = OpenRouter, `AIza` = Gemini). Los modelos por defecto son `llama-3.3-70b-versatile` (Groq), `meta-llama/llama-3.3-70b-instruct:free` (OpenRouter) y `gemini-2.5-flash` (Gemini). Las cuentas gratuitas se crean en [console.groq.com](https://console.groq.com/keys), [openrouter.ai/keys](https://openrouter.ai/keys) o [aistudio.google.com](https://aistudio.google.com/apikey).

Privacidad y seguridad:

- El prompt de sistema prohibe revelar datos de pacientes, documentos, correos, credenciales, el propio prompt o las claves.
- El contexto se arma en el servidor segun la sesion: admin/superadmin reciben solo agregados (traslados activos, stock bajo, conteos); conductor/copiloto y paciente reciben solo sus propios traslados. Un visitante no recibe datos internos.
- El historial que manda el navegador se normaliza y se recorta a 8 mensajes; el mensaje del usuario se limita a 600 caracteres.
- Todo POST pasa por el CSRF global y por un limite de 40 consultas cada 5 minutos por IP.
- Las respuestas se pintan con `textContent`, nunca con `innerHTML`.
- Los errores del proveedor se registran en la bitacora sin la clave (se reemplaza por `***`).

Si todavia no hay clave, el widget sigue apareciendo: muestra el aviso de "no configurado" y deshabilita la entrada. No hay respuestas simuladas ni un motor local de reserva.

---

## API de rutas

### Publicas (sin autenticacion)

| Metodo | Ruta | Controlador | Descripcion |
|---|---|---|---|
| GET | `/` | AuthController | Portada publica del hospital |
| GET | `/login` | AuthController | Formulario de inicio de sesion |
| POST | `/login` | AuthController | Procesar login |
| GET | `/registro` | AuthController | Formulario de registro |
| POST | `/registro` | AuthController | Procesar registro |
| GET | `/recuperar` | AuthController | Formulario "olvide mi contrasena" |
| POST | `/recuperar` | AuthController | Enviar enlace de restablecimiento por email |
| GET | `/restablecer` | AuthController | Formulario de nueva contrasena (token) |
| POST | `/restablecer` | AuthController | Guardar la nueva contrasena |
| GET | `/publico/doc` | DocumentoPublicoController | Ver documento via QR (token HMAC) |
| GET | `/publico/archivo` | DocumentoPublicoController | Descargar archivo via QR (token HMAC) |
| GET | `/publico/encuesta` | EncuestaPublicaController | Mostrar encuesta |
| POST | `/publico/encuesta` | EncuestaPublicaController | Responder encuesta |
| GET | `/idioma` | I18nController | Cambiar idioma y volver a la pagina anterior (`?lang=es\|en`) |
| GET | `/api/idioma` | I18nController | Consultar el idioma activo (JSON) |
| POST | `/api/idioma` | I18nController | Cambiar el idioma activo (JSON, requiere CSRF) |
| POST | `/api/chat` | ChatController | Consultar al chatbot de Elyra (JSON, requiere CSRF) |

### Autenticadas (requieren sesion)

| Metodo | Ruta | Controlador | Descripcion |
|---|---|---|---|
| GET | `/dashboard` | DashboardController | Panel principal (paciente redirige a `/mapa`) |
| POST | `/logout` | AuthController | Cerrar sesion |
| GET | `/perfil` | PerfilController | Perfil propio (datos y cambio de contrasena) |
| POST | `/perfil/datos` | PerfilController | Actualizar datos de contacto |
| POST | `/perfil/password` | PerfilController | Cambiar contrasena |
| GET | `/mapa` | DashboardController | Mapa en vivo (Leaflet) |
| GET | `/api/mapa` | DashboardController | Datos del mapa (JSON, filtrado por rol) |
| GET | `/api/ruta/real` | DashboardController | Calcular ruta real por calles |
| GET | `/api/geocodificar` | DashboardController | Buscar direcciones / geocodificar |
| GET | `/api/pacientes/buscar` | DashboardController | Busqueda AJAX de pacientes |
| POST | `/api/ubicacion` | DashboardController | Reporte GPS del conductor |
| GET | `/traslados/nuevo` | DashboardController | Redirige al mapa con el modal abierto |
| POST | `/traslados/nuevo` | DashboardController | Registrar traslado (JSON) |
| POST | `/traslados/estado` | DashboardController | Avanzar/cancelar estado de traslado |
| GET | `/encuestas` | EncuestaController | Listado de encuestas |
| GET | `/encuestas/crear` | EncuestaController | Formulario de creacion |
| POST | `/encuestas/crear` | EncuestaController | Procesar creacion |
| GET | `/encuestas/editar` | EncuestaController | Formulario de edicion (`?id=`) |
| POST | `/encuestas/editar` | EncuestaController | Procesar edicion |
| POST | `/encuestas/toggle` | EncuestaController | Activar/desactivar |
| GET | `/encuestas/resultados` | EncuestaResultadosController | Ver resultados (`?id=`) |
| GET | `/documentos` | DocumentoController | Listado de documentos |
| GET | `/documentos/subir` | DocumentoController | Formulario de carga |
| POST | `/documentos/subir` | DocumentoController | Procesar carga |
| GET | `/documentos/editar` | DocumentoController | Formulario de edicion (`?id=`) |
| POST | `/documentos/editar` | DocumentoController | Procesar edicion |
| POST | `/documentos/estado` | DocumentoController | Activar/desactivar (baja logica) |
| GET | `/documentos/ver` | DocumentoController | Ver detalle (`?id=`) |
| GET | `/documentos/archivo` | DocumentoController | Descargar archivo interno |
| GET | `/vehiculos` | VehiculoController | Listado de vehiculos |
| GET | `/vehiculos/agregar` | VehiculoController | Formulario de alta |
| POST | `/vehiculos/agregar` | VehiculoController | Procesar alta |
| GET | `/vehiculos/editar` | VehiculoController | Formulario de edicion (`?id=`) |
| POST | `/vehiculos/editar` | VehiculoController | Procesar edicion |
| POST | `/vehiculos/toggle` | VehiculoController | Activar/desactivar |
| GET | `/insumos` | InsumoController | Listado de insumos |
| GET | `/insumos/agregar` | InsumoController | Formulario de alta |
| POST | `/insumos/agregar` | InsumoController | Procesar alta |
| GET | `/insumos/editar` | InsumoController | Formulario de edicion (`?id=`) |
| POST | `/insumos/editar` | InsumoController | Procesar edicion |
| POST | `/insumos/toggle` | InsumoController | Activar/desactivar |
| GET | `/equipamiento` | EquipamientoController | Listado de equipamiento |
| GET | `/equipamiento/agregar` | EquipamientoController | Formulario de alta |
| POST | `/equipamiento/agregar` | EquipamientoController | Procesar alta |
| GET | `/equipamiento/editar` | EquipamientoController | Formulario de edicion (`?id=`) |
| POST | `/equipamiento/editar` | EquipamientoController | Procesar edicion |
| POST | `/equipamiento/toggle` | EquipamientoController | Activar/desactivar |
| GET | `/organos` | OrganoController | Listado de organos |
| GET | `/organos/agregar` | OrganoController | Formulario de alta |
| POST | `/organos/agregar` | OrganoController | Procesar alta |
| GET | `/organos/editar` | OrganoController | Formulario de edicion (`?id=`) |
| POST | `/organos/editar` | OrganoController | Procesar edicion |
| POST | `/organos/toggle` | OrganoController | Activar/desactivar |
| GET | `/usuarios` | UsuarioController | Listado de usuarios |
| GET | `/usuarios/buscar` | UsuarioController | Busqueda AJAX (`?q=`) |
| GET | `/usuarios/ver` | UsuarioController | Ficha de usuario (`?id=`) |
| GET | `/usuarios/crear` | UsuarioCreacionController | Formulario de alta |
| POST | `/usuarios/crear` | UsuarioCreacionController | Procesar alta |
| GET | `/usuarios/editar` | UsuarioEdicionController | Formulario de edicion (`?id=`) |
| POST | `/usuarios/editar` | UsuarioEdicionController | Procesar edicion |
| POST | `/usuarios/estado` | UsuarioEdicionController | Activar/desactivar usuario |
| GET | `/usuarios/codigos` | UsuarioCodigosController | Gestion de codigos |
| POST | `/usuarios/codigos` | UsuarioCodigosController | Generar codigo de funcionario |

---

## Tema visual

La interfaz usa un estilo **Web 2.0 retro** con estetica propia, inspirada en la mascota del proyecto:

- **Paleta**: Tonos azules, fondos gris claro, tipografia Tahoma/Verdana.
- **Sidebar**: Barra lateral con links y navegacion.
- **Paneles**: Cabezales con degradados y bordes definidos.
- **Botones**: Gradiente 3D con estilo clasico.
- **Tablas**: Fondo de color en el encabezado, texto blanco.
- **Iconos**: Bootstrap Icons y FamFamFam Silk icons (16x16).
- **Login**: Card centrada sobre fondo del hospital.

---

## Roadmap

> Detalle completo en [`docs/planificacion-sprints.md`](docs/planificacion-sprints.md) y [`docs/sprints.md`](docs/sprints.md).

| Sprint | Estado | Descripcion |
|---|---|---|
| Sprint 0 -- Fundacion y Seguridad | Completado | Front controller, sesion, CSRF global, cabeceras de seguridad, guards por rol y esquema base. |
| Sprint 1 -- Encuestas y Documentos | Completado | CRUD de encuestas con tipos de preguntas. CRUD de documentos con QR y token HMAC. Vista publica. CI/CD. |
| Sprint 2 -- Gestion de Usuarios | Completado | Directorio con busqueda, ficha, edicion y baja logica. Codigos de funcionario con consumo transaccional. |
| Sprint 3 -- Traslados y Mapa en Vivo | Completado | Registro de traslados, maquina de estados con historial, GPS del conductor y mapa Leaflet. |
| Sprint 4 -- Vehiculos e Insumos | Completado | ABM de vehiculos e insumos con baja logica, busqueda/filtros y alerta de stock bajo. |
| Sprint 5 -- Pulido y Despliegue | Completado | Integraciones con cache, PWA, vistas publicas responsive, CI y despliegue bajo subruta. |

---

## Acerca de

Elyra Demo es desarrollado por **Lain**, colectivo de estudiantes del **Instituto Tecnologico De Paysandu**.

**Equipo:** Alan, Kevin, Tom.

### Herramientas

PHP 8.1+, MySQL/MariaDB, JavaScript (ES6+), HTML5/CSS3, Apache, Git/GitHub, phpMyAdmin.

---

## Licencia

**CC BY-NC 4.0** -- Creative Commons Atribucion-NoComercial 4.0 Internacional.

Podes usar, modificar y compartir el codigo siempre que no sea con fines comerciales y se dé credito al equipo.
