#!/bin/sh
#
# Builds design/carl-design-brief.zip -- the package handed to Claude Design
# for handoff Section 13.5 (the logo and the palette).
#
# Claude Design has no access to this repository, so the brief has to travel
# with copies of the files it refers to. Those copies are assembled here rather
# than committed: Section 13.4 made the same call about the field sheet, and
# for the same reason -- a checked-in duplicate of tokens.css is a second
# artefact from one source, and it is the one that goes stale silently because
# nothing tests it.
#
# So design/handoff/ holds only what was actually written for the designer.
# Everything else in the zip is copied from the live source at build time, and
# the contrast baseline is measured from the live tokens.css rather than typed.
#
# Re-run this after changing the brief, the palette, or any file it quotes.
#
# Usage:  sh design/build-handoff-zip.sh

set -eu

root=$(cd "$(dirname "$0")/.." && pwd)
staging=$(mktemp -d)
package="$staging/carl-design-brief"
output="$root/design/carl-design-brief.zip"

trap 'rm -rf "$staging"' EXIT

mkdir -p "$package/reference" "$package/existing-canvas"

# --- what was written for the designer -----------------------------------
cp "$root/design/handoff/BRIEF.md"                     "$package/"
cp "$root/design/handoff/RETURN-FORMAT.md"             "$package/"
cp "$root/design/handoff/START-HERE.md"                "$package/"
mkdir -p "$package/validate" "$package/return-template"
cp "$root/design/handoff/validate/contrast-check.html" "$package/validate/"
cp "$root/design/handoff/return-template/tokens.css"   "$package/return-template/"

# --- copied from the live source, so a quote can never drift -------------
cp "$root/public/assets/css/tokens.css"           "$package/reference/tokens.css"
cp "$root/public/assets/css/carl.css"             "$package/reference/carl.css"
cp "$root/app/views/layout.php"                   "$package/reference/layout.php.txt"
cp "$root/app/src/Support/Tokens.php"             "$package/reference/Tokens.php.txt"
cp "$root/app/src/Reminders/DigestMessage.php"    "$package/reference/DigestMessage.php.txt"

# Only the colour-handling head of each; the designer does not need the rest.
sed -n '1,70p'   "$root/public/assets/js/charts.js"      > "$package/reference/charts.js-colour-block.txt"
sed -n '40,130p' "$root/app/src/Reports/Document.php"    > "$package/reference/Document.php-colour-block.txt"

# The artboards from the earlier canvas, for continuity. The field sheet HTML
# is 2.5 MB of generated output and is deliberately left out.
cp "$root/design/canvas.json" "$root/design"/*.dc.html "$package/existing-canvas/"

# --- measured, not typed --------------------------------------------------
php "$root/design/handoff/contrast.php" "$root/public/assets/css/tokens.css" \
    > "$package/reference/contrast-baseline.md"

# --- package --------------------------------------------------------------
rm -f "$output"
(cd "$staging" && zip -r -q "$output" carl-design-brief -x '*.DS_Store')

echo "Built $output"
echo "  $(find "$package" -type f | wc -l | tr -d ' ') files, $(du -h "$output" | cut -f1)"
