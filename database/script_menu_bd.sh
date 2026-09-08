#!/usr/bin/env bash

set -u

DIR_SCRIPT="$(cd "$(dirname "$0")" && pwd)"
ENV_FILE="$DIR_SCRIPT/../.env"

CLIENTE=""
if command -v mariadb >/dev/null 2>&1; then
    CLIENTE="mariadb"
elif command -v mysql >/dev/null 2>&1; then
    CLIENTE="mysql"
else
    echo "No se encontró el cliente de base de datos (mariadb/mysql)."
    exit 1
fi

leer_env() {
    grep -E "^$1=" "$ENV_FILE" 2>/dev/null | head -n1 | cut -d'=' -f2- | tr -d '\r'
}

DB_HOST="$(leer_env DB_HOST)"
DB_PORT="$(leer_env DB_PORT)"
DB_NOMBRE="$(leer_env DB_DATABASE)"
DB_USUARIO="$(leer_env DB_USERNAME)"
DB_PASS="$(leer_env DB_PASSWORD)"

[ -z "$DB_PORT" ] && DB_PORT="3306"

if [ -z "$DB_NOMBRE" ] || [ -z "$DB_USUARIO" ]; then
    echo "Faltan datos de conexión en $ENV_FILE"
    exit 1
fi

ejecutar_sql() {
    MYSQL_PWD="$DB_PASS" "$CLIENTE" \
        -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USUARIO" \
        --batch --skip-ssl "$DB_NOMBRE" -e "$1"
}

pausa() {
    echo
    read -r -p "Presioná Enter para volver al menú... " _ignorado
}

ver_insumos_criticos() {
    echo "== Insumos con stock bajo (menor o igual a 5 unidades) =="
    ejecutar_sql "
        SELECT id, nombre, stock,
               IF(activo = 1, 'activo', 'inactivo') AS estado
        FROM insumo
        WHERE stock <= 5
        ORDER BY stock ASC
        LIMIT 50;"
    pausa
}

ver_vehiculos() {
    echo "== Vehículos registrados =="
    ejecutar_sql "
        SELECT id, patente, modelo, anio,
               DATE_FORMAT(created_at, '%d/%m/%Y') AS registrado
        FROM vehiculo
        ORDER BY patente;"
    pausa
}

registrar_insumo() {
    echo "== Registrar un insumo nuevo =="

    read -r -p "Nombre: " nombre
    read -r -p "Descripción: " descripcion
    read -r -p "Stock inicial: " stock

    if [ -z "$nombre" ]; then
        echo "El nombre es obligatorio."
        pausa
        return
    fi
    if ! [[ "$stock" =~ ^[0-9]+$ ]]; then
        echo "El stock debe ser un número entero."
        pausa
        return
    fi

    nombre_sql="${nombre//\'/\'\'}"
    desc_sql="${descripcion//\'/\'\'}"

    if ejecutar_sql "
        INSERT INTO insumo (nombre, descripcion, stock)
        VALUES ('$nombre_sql', '$desc_sql', $stock);"; then
        echo "Insumo registrado correctamente."
    else
        echo "No se pudo registrar (¿el nombre ya existe?)."
    fi
    pausa
}

actualizar_stock() {
    echo "== Actualizar el stock de un insumo =="

    ejecutar_sql "
        SELECT id, nombre, stock FROM insumo
        ORDER BY nombre;"
    echo

    read -r -p "ID del insumo: " id
    read -r -p "Nuevo stock: " stock

    if ! [[ "$id" =~ ^[0-9]+$ ]] || ! [[ "$stock" =~ ^[0-9]+$ ]]; then
        echo "El ID y el stock deben ser números enteros."
        pausa
        return
    fi

    if ejecutar_sql "
        UPDATE insumo SET stock = $stock
        WHERE id = $id;"; then
        echo "Stock actualizado."
    else
        echo "No se pudo actualizar el stock."
    fi
    pausa
}

menu_principal() {
    echo
    echo "============================================"
    echo "  ELYRA — Administración de la base de datos"
    echo "============================================"
    echo
    echo "  1) Ver insumos con stock bajo"
    echo "  2) Ver vehículos registrados"
    echo "  3) Registrar un insumo nuevo"
    echo "  4) Actualizar stock de un insumo"
    echo "  5) Salir"
    echo
    read -r -p "Elegí una opción [1-5]: " opcion || return 1
}

while true; do
    menu_principal || break
    case "$opcion" in
        1) ver_insumos_criticos ;;
        2) ver_vehiculos ;;
        3) registrar_insumo ;;
        4) actualizar_stock ;;
        5) echo "Chau."; break ;;
        *) echo "Opción inválida. Usá un número del 1 al 5." ;;
    esac
done