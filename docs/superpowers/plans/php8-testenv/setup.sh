#!/usr/bin/env bash
# Prepara l'ambiente di prova del CMS su PHP 8.3 con il kernel del branch php8.
# Non tocca i volumi cms_* né il repo CMS: lavora su copie in $WORK_DIR.
#
# Uso: setup.sh [up|down]
#   CMS_DIR    repo del CMS            (default ~/Work/htdocs/cms)
#   KERNEL_DIR repo del kernel          (default la cartella di questo repo)
#   KERNEL_REF branch o commit del kernel (default php8)
#   WORK_DIR   cartella di lavoro       (default ~/Work/php8-testenv)
#   APP_IMAGE  immagine 7.4 da cui copiare gli script di avvio (default cms/app:latest)
set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
CMS_DIR="${CMS_DIR:-$HOME/Work/htdocs/cms}"
KERNEL_DIR="${KERNEL_DIR:-$(cd "$HERE/../../../.." && pwd)}"
KERNEL_REF="${KERNEL_REF:-php8}"
WORK_DIR="${WORK_DIR:-$HOME/Work/php8-testenv}"
APP_IMAGE="${APP_IMAGE:-registry.gitlab.com/opencity-labs/sito-istituzionale/cms/app:latest}"
COMPOSE=(docker compose --project-directory "$CMS_DIR" -f "$CMS_DIR/docker-compose.yml" -f "$WORK_DIR/compose-php8.yml")

if [[ "${1:-up}" == "down" ]]; then
    "${COMPOSE[@]}" down
    exit 0
fi

mkdir -p "$WORK_DIR/image"

# 1. Codice: html del CMS con sopra il kernel (ezjscore resta quello di opencontent/ezjscore)
rsync -a --delete "$CMS_DIR/html/" "$WORK_DIR/html/"
git -C "$KERNEL_DIR" archive "$KERNEL_REF" | tar -x -C "$WORK_DIR/html" --exclude='extension/ezjscore'
mkdir -p "$WORK_DIR/html/settings/override" "$WORK_DIR/html/settings/siteaccess" "$WORK_DIR/html/.well-known"
cp -R "$CMS_DIR/conf.d/ez/override/." "$WORK_DIR/html/settings/override/"
cp -R "$CMS_DIR/conf.d/ez/siteaccess/." "$WORK_DIR/html/settings/siteaccess/"
cp "$CMS_DIR/conf.d/ez/config.cluster.php" "$WORK_DIR/html/config.cluster.php"
cp "$CMS_DIR/security.txt" "$WORK_DIR/html/.well-known/security.txt"
echo "php8-test" > "$WORK_DIR/html/VERSION"

# 2. Immagine: webdevops 8.3 + script di avvio del CMS e dell'immagine 7.4
CTX="$WORK_DIR/image"
rm -rf "$CTX/opt" "$CTX/php-nginx"
mkdir -p "$CTX/opt"
cid=$(docker create --platform linux/amd64 "$APP_IMAGE")
docker cp "$cid:/opt/docker/provision/entrypoint.d/19-istances.sh" "$CTX/opt/"
docker cp "$cid:/opt/docker/provision/entrypoint.d/21-istances-check.sh" "$CTX/opt/"
docker cp "$cid:/opt/docker/bin/istances-generate.php" "$CTX/opt/"
docker cp "$cid:/opt/docker/lib" "$CTX/opt/lib"
docker rm "$cid" >/dev/null
cp -R "$CMS_DIR/docker/php-nginx" "$CTX/php-nginx"
cp "$CMS_DIR/docker/cron/root" "$CTX/cron-root"
# BusyBox 1.37 non accetta "chown utente.gruppo" (vedi il piano, CMS-1)
sed -i.bak 's/\$EZ_USER\.\$EZ_USER_GROUP/$EZ_USER:$EZ_USER_GROUP/g' "$CTX/php-nginx/provision/entrypoint.d/20-php.sh"
rm -f "$CTX/php-nginx/provision/entrypoint.d/20-php.sh.bak"
cp "$HERE/Dockerfile" "$CTX/Dockerfile"
docker build -q -t cms-php8-test:local "$CTX"

# 3. Volumi: copie di quelli del CMS (lo stack cms deve essere spento)
if docker ps --format '{{.Names}}' | grep -q '^cms-postgres-'; then
    echo "Lo stack cms è acceso: spegnilo prima di copiare il database" >&2
    exit 1
fi
for v in pgdata dfs miniodata solrdata; do
    if ! docker volume inspect "cmsphp8_$v" >/dev/null 2>&1; then
        docker volume create "cmsphp8_$v" >/dev/null
        docker run --rm -v "cms_$v:/from:ro" -v "cmsphp8_$v:/to" alpine sh -c 'cp -a /from/. /to/'
    fi
done

# 4. Override compose: progetto separato, porta 18080, niente traefik/varnish, installer spento
sed "s#__HTML__#$WORK_DIR/html#" "$HERE/compose-php8.yml" > "$WORK_DIR/compose-php8.yml"
"${COMPOSE[@]}" up -d app
echo "CMS su PHP 8.3: curl -H 'Host: opencity.localtest.me' http://127.0.0.1:18080/"
