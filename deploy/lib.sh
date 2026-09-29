#!/usr/bin/env bash
# ==========================================================================
# lib.sh — shared helpers for deploy-pda and promote-apps.
#
# The two entry scripts source this file. It knows how to:
#   - load deploy.env
#   - build a clean "home image" from a git ref (repo layout -> server layout)
#   - upload it, lint it on the server (php -l), back up the live site,
#     and copy the image into place WITHOUT deleting server-only files
#     (assets/, icon/, modules/, awstats symlinks, vendor/, uploads...).
#   - stamp a per-site config/version.json marker (env + version).
#
# No secrets live here. Infra values come from deploy/deploy.env.
# ==========================================================================
set -euo pipefail

DEPLOY_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="$(cd "$DEPLOY_DIR/.." && pwd)"

die() { printf '\n\033[31m✗ %s\033[0m\n' "$*" >&2; exit 1; }
step() { printf '\033[36m→ %s\033[0m\n' "$*"; }
ok()   { printf '\033[32m✓ %s\033[0m\n' "$*"; }

load_env() {
  local f="$DEPLOY_DIR/deploy.env"
  [ -f "$f" ] || die "Falta $f — copiá deploy.env.example y completalo."
  # shellcheck disable=SC1090
  . "$f"
  : "${SSH_HOST:?}" "${SSH_LOGIN:?}" "${SSH_KEY:?}"
  SSH_PORT="${SSH_PORT:-22}"
  KEEP_BACKUPS="${KEEP_BACKUPS:-10}"
  SSH_KEY="${SSH_KEY/#\~/$HOME}"
  [ -f "$SSH_KEY" ] || die "No encuentro la clave SSH: $SSH_KEY"
}

ssh_do()  { ssh -i "$SSH_KEY" -p "$SSH_PORT" -o ConnectTimeout=20 "$SSH_LOGIN@$SSH_HOST" "$@"; }
scp_to()  { scp -i "$SSH_KEY" -P "$SSH_PORT" -o ConnectTimeout=20 "$1" "$SSH_LOGIN@$SSH_HOST:$2"; }

# Resolve a git ref to its commit; fail if unknown.
resolve_commit() { git -C "$REPO_DIR" rev-parse --verify "${1}^{commit}" 2>/dev/null || die "Ref desconocido: $1"; }

# Read the commit currently deployed on a site (from its version.json).
remote_commit() {
  local home="$1"
  ssh_do "sudo cat '$home/config/version.json' 2>/dev/null" \
    | tr ',' '\n' | sed -n 's/.*\"commit\"[[:space:]]*:[[:space:]]*\"\([^\"]*\)\".*/\1/p' | head -1
}

# Build a staging tree from a ref that mirrors the server's HOME layout.
# Must be called directly (NOT in $(...)): it exports IMG_DIR, IMG_VERSION,
# IMG_COMMIT and STAGE_WORK to the caller.
#   $1 ref  $2 env(staging|production)  $3 refname-for-marker
build_image() {
  local ref="$1" env="$2" refname="$3"
  local commit version now img
  commit="$(resolve_commit "$ref")"
  version="$(git -C "$REPO_DIR" describe --tags --always "$ref" 2>/dev/null || echo "$commit")"
  now="$(date +%Y-%m-%dT%H:%M:%S%z)"

  local work; work="$(mktemp -d)"; STAGE_WORK="$work"   # exported for cleanup by caller
  img="$work/image"
  mkdir -p "$img/public_html" "$img/src" "$img/bin" "$img/config"

  # Clean export of the repo at the ref (no local junk, no .git).
  git -C "$REPO_DIR" archive "$ref" | tar -x -C "$work" -f - --exclude-vcs || die "git archive falló"
  local ex="$work"   # extracted repo root

  # repo layout  ->  server /home/<user> layout
  cp -a "$ex/public/." "$img/public_html/"
  [ -f "$ex/docs/index.html" ] && cp -a "$ex/docs/index.html" "$img/public_html/doc.html"
  [ -f "$ex/docs/docDash.js" ] && cp -a "$ex/docs/docDash.js" "$img/public_html/docDash.js"
  cp -a "$ex/src/." "$img/src/"
  cp -a "$ex/lib.php" "$img/lib.php"
  cp -a "$ex/rh-auth.php" "$img/rh-auth.php"
  [ -f "$ex/bin/generate.php" ] && cp -a "$ex/bin/generate.php" "$img/bin/generate.php"
  [ -f "$ex/config/settings.example.json" ] && cp -a "$ex/config/settings.example.json" "$img/config/settings.example.json"

  # Environment marker (read by Settings::env() -> STAGING badge).
  cat > "$img/config/version.json" <<JSON
{
  "env": "$env",
  "version": "$version",
  "ref": "$refname",
  "commit": "$commit",
  "deployed_at": "$now"
}
JSON

  IMG_DIR="$img"; IMG_VERSION="$version"; IMG_COMMIT="$commit"   # exported to caller
}

# Upload the image, lint it on the server, back up the live site, and copy
# into place (no deletes). Runs everything server-side under sudo.
#   $1 image-dir  $2 site-user  $3 site-home
push_image() {
  local img="$1" user="$2" home="$3"
  local ts; ts="$(date +%Y%m%d-%H%M%S)"
  local tar="$STAGE_WORK/site-$ts.tar" remote="/tmp/deploy-$user-$ts"

  step "Empaquetando imagen…"
  tar -C "$img" -cf "$tar" .

  step "Subiendo al servidor ($SSH_HOST)…"
  ssh_do "mkdir -p '$remote'"
  scp_to "$tar" "$remote/site.tar"

  step "Lint (php -l) + backup + instalación en el servidor…"
  ssh_do "sudo bash -s" <<REMOTE || die "El despliegue remoto falló (revisá la salida de arriba)."
set -euo pipefail
USER_='$user'; HOME_='$home'; REMOTE='$remote'; KEEP='$KEEP_BACKUPS'; TS='$ts'
PHP="\$(command -v php || command -v php8.3)"; [ -n "\$PHP" ] || { echo 'php no encontrado'; exit 1; }

mkdir -p "\$REMOTE/image"
tar -C "\$REMOTE/image" -xf "\$REMOTE/site.tar"

# --- lint: abortar si algún .php no compila ---
FAIL=0
while IFS= read -r -d '' f; do
  "\$PHP" -l "\$f" >/dev/null 2>&1 || { echo "  php -l FALLÓ: \$f"; "\$PHP" -l "\$f" || true; FAIL=1; }
done < <(find "\$REMOTE/image" -name '*.php' -print0)
[ "\$FAIL" = 0 ] || { echo 'Abortado: hay PHP con errores de sintaxis.'; rm -rf "\$REMOTE"; exit 2; }
echo "  php -l OK"

# --- backup del sitio vivo (tarball limpio, no más .bak sueltos) ---
BK="\$HOME_/backups"; mkdir -p "\$BK"; chown "\$USER_:\$USER_" "\$BK"
ITEMS=""; for i in public_html src bin lib.php rh-auth.php config; do [ -e "\$HOME_/\$i" ] && ITEMS="\$ITEMS \$i"; done
tar -C "\$HOME_" -czf "\$BK/\$TS.tgz" \$ITEMS
chown "\$USER_:\$USER_" "\$BK/\$TS.tgz"
echo "  backup: \$BK/\$TS.tgz"
ls -1t "\$BK"/*.tgz 2>/dev/null | tail -n +\$((KEEP+1)) | xargs -r rm -f

# --- instalación (SIN borrar: preserva assets/, icon/, modules/, vendor/, symlinks) ---
cp -a "\$REMOTE/image/public_html/." "\$HOME_/public_html/"
cp -a "\$REMOTE/image/src/."         "\$HOME_/src/"
[ -d "\$REMOTE/image/bin" ] && cp -a "\$REMOTE/image/bin/." "\$HOME_/bin/"
cp -a "\$REMOTE/image/lib.php"       "\$HOME_/lib.php"
cp -a "\$REMOTE/image/rh-auth.php"   "\$HOME_/rh-auth.php"
[ -f "\$REMOTE/image/config/settings.example.json" ] && cp -a "\$REMOTE/image/config/settings.example.json" "\$HOME_/config/settings.example.json"
cp -a "\$REMOTE/image/config/version.json" "\$HOME_/config/version.json"

# --- dueño correcto del sitio ---
chown -R "\$USER_:\$USER_" "\$HOME_/public_html" "\$HOME_/src" "\$HOME_/bin" \
        "\$HOME_/lib.php" "\$HOME_/rh-auth.php" "\$HOME_/config/version.json" "\$HOME_/config/settings.example.json" 2>/dev/null || true

rm -rf "\$REMOTE"
echo "  instalado en \$HOME_"
REMOTE
}

# Cleanup temp dir created by build_image.
cleanup_stage() { [ -n "${STAGE_WORK:-}" ] && rm -rf "$STAGE_WORK" || true; }
