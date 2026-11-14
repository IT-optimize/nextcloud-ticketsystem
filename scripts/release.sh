#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Co-Optimize
# SPDX-License-Identifier: AGPL-3.0-or-later
# Builds the archive the Nextcloud app store expects and prints its signature.
#
#   scripts/release.sh            build build/ticketsystem-<version>.tar.gz
#   scripts/release.sh --sign     also sign it with ~/.nextcloud/certificates/ticketsystem.key
#
# The archive holds one folder, "ticketsystem", with only what Nextcloud needs to run
# the app: no sources, tests, node_modules or .git.
set -euo pipefail

APP_ID=ticketsystem
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

VERSION="$(sed -n 's:.*<version>\(.*\)</version>.*:\1:p' appinfo/info.xml | head -n1)"
fail() { echo "release: $*" >&2; exit 1; }

# --- Checks the app store would otherwise reject the upload for --------------
[[ "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.]+)?$ ]] || fail "version '$VERSION' in appinfo/info.xml is not a semantic version"
grep -q "github.com/OWNER/" appinfo/info.xml && fail "replace OWNER in the website, bugs and repository links in appinfo/info.xml"
grep -Eq "^## \[?$VERSION\]?" CHANGELOG.md || fail "CHANGELOG.md has no '## $VERSION' entry"
[[ -f LICENSE ]] || fail "LICENSE is missing"

# --- Production build of the interface ----------------------------------------
if [[ "${SKIP_BUILD:-}" != "1" ]]; then
	npm ci --no-audit --no-fund
	npm run build
	npm test
fi

# --- Assemble -----------------------------------------------------------------
OUT="$ROOT/build"
STAGE="$OUT/stage/$APP_ID"
rm -rf "$OUT/stage"
mkdir -p "$STAGE"
cp -r appinfo img js l10n lib templates LICENSE CHANGELOG.md "$STAGE/"
[[ -f README.md ]] && cp README.md "$STAGE/"
[[ -f SECURITY.md ]] && cp SECURITY.md "$STAGE/"
# Source maps only help while developing and double the download.
find "$STAGE/js" -name '*.map' -delete

ARCHIVE="$OUT/$APP_ID-$VERSION.tar.gz"
tar --owner=0 --group=0 --numeric-owner -czf "$ARCHIVE" -C "$OUT/stage" "$APP_ID"
rm -rf "$OUT/stage"
echo "Built $ARCHIVE ($(du -h "$ARCHIVE" | cut -f1))"

# --- Signature ----------------------------------------------------------------
if [[ "${1:-}" == "--sign" ]]; then
	KEY="$HOME/.nextcloud/certificates/$APP_ID.key"
	[[ -f "$KEY" ]] || fail "no private key at $KEY (see 'Publishing to the app store' in the README)"
	echo
	echo "Signature to paste into the app store upload form:"
	openssl dgst -sha512 -sign "$KEY" "$ARCHIVE" | openssl base64
fi
