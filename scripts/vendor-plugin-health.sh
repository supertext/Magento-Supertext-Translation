#!/bin/sh
# Copies the PHP part of Supertext Plugin Health (version reports to the Supertext Commando Center,
# the API key check result and the ping answer) from the Commando Center repository into
# lib/supertext-plugin-health. It isn't on Packagist, so the module ships this copy next to the SDK
# copy (namespace Supertext\PluginHealth, unchanged, like the SDK's). registration.php loads its
# autoload.php, so it works for Composer and app/code installs alike.
#
#   scripts/vendor-plugin-health.sh [commit-or-branch]   (default: main)
#   PLUGIN_HEALTH_DIR=/path/to/clone scripts/vendor-plugin-health.sh   (use a local clone as it is)
set -eu
REF="${1:-main}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

if [ -n "${PLUGIN_HEALTH_DIR:-}" ]; then
    SRC="$PLUGIN_HEALTH_DIR"
else
    git clone --quiet https://github.com/Supertext/Commando-Center-Supertext-Translation "$TMP/center"
    git -C "$TMP/center" -c advice.detachedHead=false checkout --quiet "$REF"
    SRC="$TMP/center"
fi
COMMIT="$(git -C "$SRC" rev-parse HEAD)"
VERSION="$(sed -n "s/.*PLUGIN_HEALTH_VERSION = '\([^']*\)'.*/\1/p" "$SRC/plugin-health/php/src/PluginHealth.php")"
if [ -z "$VERSION" ]; then
    echo "vendor-plugin-health: no PLUGIN_HEALTH_VERSION in $SRC/plugin-health/php/src/PluginHealth.php" >&2
    exit 1
fi

DEST="$ROOT/lib/supertext-plugin-health"
rm -rf "$DEST"
mkdir -p "$DEST"
cp -R "$SRC/plugin-health/php/src" "$DEST/src"
cp "$SRC/plugin-health/php/LICENSE" "$DEST/LICENSE"
printf '%s\n' "$COMMIT" > "$DEST/COMMIT"
printf '%s\n' "$VERSION" > "$DEST/VERSION"
cat > "$DEST/autoload.php" <<'EOF'
<?php

declare(strict_types=1);

// Autoloader for the bundled Supertext Plugin Health (written by scripts/vendor-plugin-health.sh).
spl_autoload_register(static function (string $class): void {
    $prefix = 'Supertext\\PluginHealth\\';
    if (str_starts_with($class, $prefix)) {
        $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, \strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});
EOF
cat > "$DEST/README.md" <<EOF
# Supertext Plugin Health (bundled copy)

PHP part of [Supertext Plugin Health](https://github.com/Supertext/Commando-Center-Supertext-Translation/tree/main/plugin-health)
$VERSION, commit \`$COMMIT\`, namespace \`Supertext\\PluginHealth\` (unchanged).

Don't edit these files: fix them in Commando-Center-Supertext-Translation/plugin-health, then run
\`scripts/vendor-plugin-health.sh\`.
EOF
if [ -n "$(grep -rL --include='*.php' '^namespace Supertext\\PluginHealth' "$DEST/src")" ]; then
    echo "vendor-plugin-health: a file in $DEST/src is outside the Supertext\\PluginHealth namespace" >&2
    exit 1
fi
echo "lib/supertext-plugin-health: Supertext Plugin Health $VERSION (PHP) from commit $COMMIT"
