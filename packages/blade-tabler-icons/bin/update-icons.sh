#!/usr/bin/env bash
#
# Re-syncs resources/svg/{outline,filled} from the latest @tabler/icons
# release on the npm registry, so the package can be updated without a
# full git clone of tabler/tabler-icons.
#
# Usage: bin/update-icons.sh [version]
#   version defaults to "latest"

set -euo pipefail

VERSION="${1:-latest}"
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WORK_DIR="$(mktemp -d)"
trap 'rm -rf "$WORK_DIR"' EXIT

echo "Resolving @tabler/icons@${VERSION}..."
TARBALL_URL=$(curl -s "https://registry.npmjs.org/@tabler/icons" \
    | php -r '
        $d = json_decode(stream_get_contents(STDIN), true);
        $version = $argv[1] === "latest" ? $d["dist-tags"]["latest"] : $argv[1];
        echo $d["versions"][$version]["dist"]["tarball"];
    ' "$VERSION")

echo "Downloading ${TARBALL_URL}..."
curl -s -o "$WORK_DIR/icons.tgz" "$TARBALL_URL"

echo "Extracting..."
mkdir -p "$WORK_DIR/extracted"
tar -xzf "$WORK_DIR/icons.tgz" -C "$WORK_DIR/extracted"

echo "Syncing resources/svg/outline and resources/svg/filled..."
rm -rf "$ROOT_DIR/resources/svg/outline" "$ROOT_DIR/resources/svg/filled"
cp -r "$WORK_DIR/extracted/package/icons/outline" "$ROOT_DIR/resources/svg/outline"
cp -r "$WORK_DIR/extracted/package/icons/filled" "$ROOT_DIR/resources/svg/filled"

echo "Rebuilding resources/svg/prefixed mirror (filled-<name>.svg copies)..."
rm -rf "$ROOT_DIR/resources/svg/prefixed"
mkdir -p "$ROOT_DIR/resources/svg/prefixed"
COLLISIONS=0
for src in "$ROOT_DIR/resources/svg/filled"/*.svg; do
    base="$(basename "$src")"
    if [ -e "$ROOT_DIR/resources/svg/outline/filled-$base" ]; then
        echo "COLLISION: upstream outline set ships filled-$base — mirror copy skipped (upstream wins)."
        COLLISIONS=$((COLLISIONS + 1))
    else
        cp "$src" "$ROOT_DIR/resources/svg/prefixed/filled-$base"
    fi
done

OUTLINE_COUNT=$(find "$ROOT_DIR/resources/svg/outline" -name '*.svg' | wc -l | tr -d ' ')
FILLED_COUNT=$(find "$ROOT_DIR/resources/svg/filled" -name '*.svg' | wc -l | tr -d ' ')
PREFIXED_COUNT=$(find "$ROOT_DIR/resources/svg/prefixed" -name '*.svg' | wc -l | tr -d ' ')

echo "Done. outline=${OUTLINE_COUNT} filled=${FILLED_COUNT} prefixed=${PREFIXED_COUNT} collisions=${COLLISIONS}"
echo "Review the diff, bump the composer.json version/changelog, and commit."
