# Elyra Demo

> Sistema de gestión hospitalaria para el **Hospital de Clínicas**.
> Front controller en PHP con autenticación, encuestas, documentos, vehículos, insumos y usuarios.

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

### Gestion de documentacion para pacientes
- Carga y clasificacion de documentos informativos por tipo.
- Generacion automatica de codigo QR por documento.
- Panel de administracion con busqueda y filtros.
- Vista publica: el paciente escanea el QR y ve sus documentos.

### Encuestas de satisfaccion
- Creacion de encuestas con preguntas de opcion multiple, escala (1-5) y texto libre.
- Respuesta anonica o vinculada a paciente mediante token QR.
- Resultados con graficos estadisticos.

### Gestion de vehiculos
- ABM completo de vehiculos (patente, modelo, anio).
- Listado con busqueda y filtros.

### Gestion de insumos medicos
- ABM de insumos con control de stock.
- Activar/desactivar insumos (solo admin).
- Alerta de stock critico (menos de 5 unidades).

### Gestion de usuarios
- Busqueda por cedula o nombre.
- Ficha de usuario con edicion de datos.
- Gestion de codigos de funcionario para registro.
- Activar/desactivar usuarios.

### Panel de administracion
- Dashboard con acceso diferenciado por rol.
- Menu lateral con secciones: Inicio, Encuestas, Documentos, Vehiculos, Insumos, Usuarios.

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
│   └── database.php            → Conexion PDO a MySQL
├── database/
│   ├── esquema.sql             → Esquema completo de la base de datos
│   └── script_menu_bd.sh       → Script de administracion de BD por terminal
├── public/
│   ├── css/
│   │   ├── base.css            → Estilos globales
│   │   └── pages/              → Estilos por seccion
│   ├── js/
│   │   ├── elyra.js            → Funciones generales
│   │   └── pages/              → JS por modulo
│   ├── img/                    → Logos e imagenes
│   └── uploads/                → Archivos subidos por usuarios
├── src/
│   ├── Auth.php                → Autenticacion (login, registro, sesion)
│   ├── helpers.php             → Funciones auxiliares (render, base_path, etc.)
│   └── Controller/
│       ├── AuthController.php
│       ├── DashboardController.php
│       ├── DocumentoController.php
│       ├── DocumentoArchivoController.php
│       ├── DocumentoData.php           → Trait de datos (documentos)
│       ├── DocumentoPublicoController.php
│       ├── EncuestaController.php
│       ├── EncuestaData.php            → Trait de datos (encuestas)
│       ├── EncuestaPublicaController.php
│       ├── EncuestaResultadosController.php
│       ├── InsumoController.php
│       ├── InsumoData.php              → Trait de datos (insumos)
│       ├── UsuarioController.php
│       ├── UsuarioData.php             → Trait de datos (usuarios)
│       ├── UsuarioCodigosController.php
│       ├── UsuarioEdicionController.php
│       └── VehiculoController.php
├── storage/
│   ├── docs/                   → PDFs subidos por usuarios
│   └── qrcodes/                → QRs generados
├── views/
│   ├── auth/                   → Login y registro
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

### Tablas principales

- **`usuario`** -- Tabla base (tipo: funcionario | paciente, nombre, apellido, email, documento_identidad).
- **`funcionario`** -- Administradores, superadmins, conductores y copilotos (username, password_hash, rol, licencia, activo).
- **`paciente`** -- Pacientes (token_acceso, username, codigo_qr_id).
- **`documento`** -- Documentos informativos (titulo, descripcion, archivo, tipo, paciente asociado).
- **`tipo_documento`** -- Tipos de documento (resumen, analisis, receta, otro).
- **`encuesta`** -- Encuestas de satisfaccion (titulo, descripcion, activa, creada_por).
- **`pregunta`** -- Preguntas (tipo: multiple_choice | escala | texto_libre, texto, orden).
- **`pregunta_opcion`** -- Opciones para preguntas de opcion multiple.
- **`respuesta`** -- Respuestas (sesion_token, valor_opcion, valor_texto, valor_numerico).
- **`vehiculo`** -- Vehiculos (patente, modelo, anio).
- **`insumo`** -- Insumos medicos (nombre, stock, activo).
- **`organo`** -- Organos (nombre, stock, activo).
- **`equipamiento`** -- Equipamiento medico (nombre, stock, activo).
- **`traslado`** -- Traslados (conductor, vehiculo, origen, destino, estado).
- **`paciente_traslado`** -- Relacion paciente-traslado.
- **`codigo_funcionario`** -- Codigos de registro para funcionarios.
- **`codigo_qr`** -- Codigos QR associados a pacientes.
- **`historial_estado`** -- Historial de cambios de estado de traslados.
- **`ubicacion_conductor`** -- Posicion GPS del conductor.

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
| `superadmin` | Acceso total: todos los modulos, administracion de usuarios y codigos. |
| `admin` | Gestion completa excepto administrar usuarios y codigos. |
| `conductor` | Gestion de vehiculos y traslados asignados. |
| `copiloto` | Vision limitada a traslados asignados. |
| `paciente` | Acceso publico via QR: ver documentos propios, responder encuestas. |

---

## Instalacion local

### Requisitos

- PHP 8.1+ (con extension `pdo_mysql`)
- MySQL o MariaDB
- Git

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
#   DB_PASSWORD=elyra_pass

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
# Usuario BD: elyra / elyra_pass
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
5. Crear el archivo `.env` en la raiz del proyecto con las credenciales de la BD:
   ```
   APP_URL=http://itspvm.duckdns.org/proyectos/elyra
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=elyra
   DB_USERNAME=elyra
   DB_PASSWORD=elyra_pass
   ```
6. Listo. La app queda accesible desde el mismo escritorio web y en https://itspvm.duckdns.org/proyectos/elyra.

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
| GET | `/publico/doc` | DocumentoPublicoController | Ver documento via QR |
| GET | `/publico/archivo` | DocumentoPublicoController | Descargar archivo via QR |
| GET | `/publico/encuesta` | EncuestaPublicaController | Mostrar encuesta |
| POST | `/publico/encuesta` | EncuestaPublicaController | Responder encuesta |

### Autenticadas (requieren sesion)

| Metodo | Ruta | Controlador | Descripcion |
|---|---|---|---|
| GET | `/dashboard` | DashboardController | Panel principal |
| GET | `/logout` | AuthController | Cerrar sesion |
| GET | `/encuestas` | EncuestaController | Listado de encuestas |
| GET | `/encuestas/crear` | EncuestaController | Formulario de creacion |
| POST | `/encuestas/crear` | EncuestaController | Procesar creacion |
| GET | `/encuestas/editar/{id}` | EncuestaController | Formulario de edicion |
| POST | `/encuestas/editar/{id}` | EncuestaController | Procesar edicion |
| POST | `/encuestas/toggle/{id}` | EncuestaController | Activar/desactivar |
| GET | `/encuestas/resultados/{id}` | EncuestaResultadosController | Ver resultados |
| GET | `/documentos` | DocumentoController | Listado de documentos |
| GET | `/documentos/subir` | DocumentoController | Formulario de carga |
| POST | `/documentos/subir` | DocumentoController | Procesar carga |
| GET | `/documentos/editar/{id}` | DocumentoController | Formulario de edicion |
| POST | `/documentos/editar/{id}` | DocumentoController | Procesar edicion |
| POST | `/documentos/toggle/{id}` | DocumentoController | Activar/desactivar |
| GET | `/documentos/ver/{id}` | DocumentoController | Ver detalle |
| GET | `/vehiculos` | VehiculoController | Listado de vehiculos |
| GET | `/vehiculos/agregar` | VehiculoController | Formulario de alta |
| POST | `/vehiculos/agregar` | VehiculoController | Procesar alta |
| GET | `/vehiculos/editar/{id}` | VehiculoController | Formulario de edicion |
| POST | `/vehiculos/editar/{id}` | VehiculoController | Procesar edicion |
| POST | `/vehiculos/eliminar/{id}` | VehiculoController | Eliminar vehiculo |
| GET | `/insumos` | InsumoController | Listado de insumos |
| GET | `/insumos/agregar` | InsumoController | Formulario de alta |
| POST | `/insumos/agregar` | InsumoController | Procesar alta |
| GET | `/insumos/editar/{id}` | InsumoController | Formulario de edicion |
| POST | `/insumos/editar/{id}` | InsumoController | Procesar edicion |
| POST | `/insumos/toggle/{id}` | InsumoController | Activar/desactivar |
| GET | `/usuarios` | UsuarioController | Listado de usuarios |
| GET | `/usuarios/ver/{id}` | UsuarioController | Ficha de usuario |
| GET | `/usuarios/editar/{id}` | UsuarioEdicionController | Formulario de edicion |
| POST | `/usuarios/editar/{id}` | UsuarioEdicionController | Procesar edicion |
| POST | `/usuarios/desactivar/{id}` | UsuarioController | Desactivar usuario |
| GET | `/usuarios/codigos` | UsuarioCodigosController | Gestion de codigos |

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

| Sprint | Estado | Descripcion |
|---|---|---|
| Sprint 1 -- Encuestas y Documentos | Completado | CRUD de encuestas con tipos de preguntas. CRUD de documentos con QR. Vista publica. CI/CD en GitHub Actions. |
| Sprint 2 -- Traslados | Pendiente | CRUD de vehiculos y rutas. Registro de traslados con seleccion de conductor, vehiculo y ruta. Flujo de estados con historial. Seguimiento GPS. |
| Sprint 3 -- Gestion de Usuarios | Pendiente | CRUD de funcionarios con creacion transaccional. CRUD de pacientes. Gestion de roles y permisos. |
| Sprint 4 -- Pulido y Produccion | Pendiente | Paginacion y busqueda. Responsive mobile. Tests basicos para controllers. |

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
