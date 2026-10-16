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

### Gestion de usuarios
- Creacion de usuarios, busqueda por cedula o nombre y ficha con edicion de datos.
- Gestion de codigos de funcionario para el registro.
- Activar/desactivar usuarios.

### Cuenta y seguridad
- Perfil propio: ver datos, editar contacto y cambiar contrasena.
- Recuperacion de contrasena por email (enlace con token, via SMTP/PHPMailer).

### Panel de administracion
- Dashboard con acceso diferenciado por rol.
- Menu lateral con secciones: Inicio, Mapa, Vehiculos, Insumos, Equipamiento, Documentos, Encuestas, Usuarios.

### Pagina publica
- Home publico con informacion del hospital.
- Vista de documentos escaneando QR.
- Formulario de registro para pacientes.

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
│   │   └── pages/              → Estilos por seccion
│   ├── js/
│   │   ├── elyra.js            → Funciones generales
│   │   ├── comun/              → Utilidades compartidas
│   │   ├── mapa/               → Modulos del mapa en vivo
│   │   ├── traslado/           → Modal de registro de traslado
│   │   └── pages/              → JS por modulo
│   ├── img/                    → Logos e imagenes
│   ├── manifest.json           → PWA basica
│   └── uploads/                → Archivos subidos por usuarios
├── src/
│   ├── Auth.php                → Autenticacion (login, registro, sesion)
│   ├── helpers.php             → Funciones auxiliares (render, guards, CSRF, tokens)
│   ├── MailService.php         → Envio de correo (PHPMailer sobre SMTP)
│   ├── GeocodeService.php      → Texto <-> coordenadas (Nominatim)
│   ├── GeoapifyService.php     → Geocodificacion/ruteo (Geoapify)
│   ├── RutaService.php         → Ruta real por calles (OSRM)
│   ├── InterseccionService.php → Intersecciones de calles (Overpass)
│   └── Controller/
│       ├── AuthController.php
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
│       ├── InsumoController.php
│       ├── InsumoData.php              → Trait de datos (insumos)
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
