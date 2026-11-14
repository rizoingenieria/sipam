#!/usr/bin/env bash
#
# Actualiza SIPAM en el servidor con lo último del repositorio.
#
#   cd /var/www/sipam && ./scripts/actualizar.sh
#
# Pide la contraseña de panteon_migra (o tómala de la variable MIGRA_PASS) y hace, en orden:
# respaldo, mantenimiento, git pull, dependencias, migraciones, permisos de la base, estilos y caché, reapertura.
# Si algo falla, se detiene y lo dice; el respaldo del paso 1 queda en RESPALDOS_RUTA.
#
# Supone: código propiedad de tu usuario, storage y bootstrap/cache de www-data (guía de producción),
# y panteon_migra con GRANT OPTION sobre la base para regenerar los permisos de panteon_app.

set -euo pipefail
cd "$(dirname "$0")/.."

PHP="${PHP:-php8.5}"
COMPOSER="${COMPOSER_BIN:-/usr/local/bin/composer}"
WEB="${WEB_USER:-www-data}"
MIGRA="${MIGRA_USER:-panteon_migra}"
APP_USER_DB="${APP_DB_USER:-panteon_app}"
APP_HOST_DB="${APP_DB_HOST:-localhost}"   # host con el que se creó panteon_app en MySQL

leer() { grep -E "^$1=" .env | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }
como_web() { sudo -u "$WEB" "$PHP" artisan "$@"; }
paso() { printf '\n\033[1m%s\033[0m\n' "$1"; }

[ -f .env ] || { echo "No encuentro .env en $(pwd). Corre el script desde la carpeta del sistema."; exit 1; }
DB="$(leer DB_DATABASE)"; HOST="$(leer DB_HOST)"; PORT="$(leer DB_PORT)"
HOST="${HOST:-127.0.0.1}"; PORT="${PORT:-3306}"

if [ -z "${MIGRA_PASS:-}" ]; then
    read -rs -p "Contraseña de $MIGRA: " MIGRA_PASS; echo
fi
export MYSQL_PWD="$MIGRA_PASS"
mysql -h "$HOST" -P "$PORT" -u "$MIGRA" -e "SELECT 1" "$DB" >/dev/null 2>&1 \
    || { echo "La contraseña de $MIGRA no es correcta o la base «$DB» no responde. No se cambió nada."; exit 1; }

en_mantenimiento=0
al_fallar() {
    echo
    echo "La actualización se detuvo en el paso de arriba. Revisa el mensaje."
    if [ "$en_mantenimiento" = 1 ]; then
        echo "El sistema quedó en mantenimiento para no operar a medias."
        echo "Cuando lo corrijas: vuelve a correr este script, o reabre con: sudo -u $WEB $PHP artisan up"
    fi
}
trap al_fallar ERR

paso "1/7 Respaldo de la base"
como_web panteones:respaldar --sin-archivos

paso "2/7 Modo mantenimiento (el personal ve «Estamos actualizando el sistema»)"
como_web down --retry=30
en_mantenimiento=1

paso "3/7 Código nuevo (git pull)"
git pull --ff-only

paso "4/7 Dependencias"
# Composer corre con tu usuario (vendor es tuyo) y sin sus scripts: los scripts de Laravel escriben en
# bootstrap/cache y storage/logs, que son de www-data. Esa parte se hace enseguida como www-data.
"$PHP" "$COMPOSER" install --no-dev --optimize-autoloader --no-interaction --no-scripts
# Lo mismo que harían esos scripts: borrar la lista de paquetes en caché (sin arrancar Laravel, que fallaría
# si la lista menciona paquetes que ya no están) y volver a descubrirlos.
sudo -u "$WEB" rm -f bootstrap/cache/packages.php bootstrap/cache/services.php
como_web package:discover

paso "5/7 Base de datos"
# Sin caché de configuración, para que tomen efecto las credenciales de migración.
como_web optimize:clear
DB_USERNAME="$MIGRA" DB_PASSWORD="$MIGRA_PASS" sudo --preserve-env=DB_USERNAME,DB_PASSWORD -u "$WEB" "$PHP" artisan migrate --force

paso "6/7 Permisos de la aplicación (la bitácora sólo admite agregar; el acervo no se borra)"
mysql -h "$HOST" -P "$PORT" -u "$MIGRA" -N -e "
    SELECT CONCAT('GRANT SELECT, INSERT, UPDATE, DELETE ON \`$DB\`.\`', table_name, '\` TO ''$APP_USER_DB''@''$APP_HOST_DB'';')
      FROM information_schema.tables WHERE table_schema = '$DB' AND table_name NOT IN ('bitacora', 'documentos_acervo', 'archivos_acervo');
    SELECT 'GRANT SELECT, INSERT ON \`$DB\`.\`bitacora\` TO ''$APP_USER_DB''@''$APP_HOST_DB'';';
    SELECT CONCAT('GRANT SELECT, INSERT, UPDATE ON \`$DB\`.\`', table_name, '\` TO ''$APP_USER_DB''@''$APP_HOST_DB'';')
      FROM information_schema.tables WHERE table_schema = '$DB' AND table_name IN ('documentos_acervo', 'archivos_acervo');" \
    | mysql -h "$HOST" -P "$PORT" -u "$MIGRA"

paso "7/7 Estilos, caché y reapertura"
"$PHP" artisan filament:assets
como_web optimize
como_web up
en_mantenimiento=0
unset MYSQL_PWD MIGRA_PASS

echo
echo "Listo. Versión instalada: $(git log -1 --format='%h %s (%cd)' --date=format:'%d/%m/%Y %H:%M')"
