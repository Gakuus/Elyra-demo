#!/usr/bin/env bash
# Levanta phpMyAdmin (instalado por composer) con el server embebido de PHP.
# URL: http://localhost:8081  (usuario BD: elyra / elyra_pass)
set -e
cd "$(dirname "$0")"
PORT=8081
if ! curl -s -o /dev/null "http://127.0.0.1:${PORT}/"; then
    nohup php -S "127.0.0.1:${PORT}" -t "$PWD/phpmyadmin" > /tmp/phpmyadmin_server.log 2>&1 &
    echo "phpMyAdmin iniciado en http://localhost:${PORT}"
else
    echo "phpMyAdmin ya está corriendo en http://localhost:${PORT}"
fi
