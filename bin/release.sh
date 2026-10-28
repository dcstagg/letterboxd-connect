#!/usr/bin/env bash
# Publish a new version of Letterboxd Connect.
#
# The plugin is developed in the davidstagg.com site repo (it deploys there
# first). This copies it here, builds letterboxd-connect.zip, commits, tags
# v<version>, pushes, and creates a GitHub release with the ZIP attached and
# that version's changelog section as its notes.
#
#   bin/release.sh            copy, build, commit, tag, push, release
#   bin/release.sh --dry-run  copy and build only; nothing is committed or pushed
#
# Before running: bump "Version:" and LBC_VERSION in letterboxd.php and add a
# "## <version> — <date>" section to CHANGELOG.md, in the site repo.

set -euo pipefail
cd "$(dirname "$0")/.."

SOURCE="${LBC_SOURCE:-../davidstagg2027_wp/wp-content/plugins/letterboxd-connect}"
REPO="dcstagg/letterboxd-connect"
DRY_RUN=0
[ "${1:-}" = "--dry-run" ] && DRY_RUN=1

[ -f "$SOURCE/letterboxd.php" ] || { echo "No plugin at $SOURCE (set LBC_SOURCE)." >&2; exit 1; }
MIRROR=(--exclude=/.git --exclude=/docs --exclude=/bin --exclude=/dist
	--exclude=/.gitignore --exclude=/.gitattributes --exclude=.DS_Store)

# Plugin files edited here (rather than in the site repo) would be lost:
# stop if there are uncommitted changes that aren't already the site repo's.
if [ "$DRY_RUN" = 0 ] && [ -n "$(git status --porcelain -- . ':!docs' ':!bin')" ] \
	&& [ -n "$(rsync -ac --delete -n --itemize-changes "${MIRROR[@]}" "$SOURCE/" ./ | grep -v '^\.d' || true)" ]; then
	echo "Plugin files here have changes that aren't in $SOURCE; they'd be overwritten. Move them to the site repo or discard them first." >&2
	exit 1
fi

# The plugin's files, mirrored from the site repo. Everything else here
# (docs/, bin/, dotfiles) is this repo's own.
rsync -a --delete "${MIRROR[@]}" "$SOURCE/" ./

VERSION="$(sed -n 's/^ \* Version: *//p' letterboxd.php | head -1 | tr -d '[:space:]')"
CONSTANT="$(sed -n "s/^const LBC_VERSION *= *'\(.*\)';/\1/p" letterboxd.php)"
[ -n "$VERSION" ] || { echo "No Version: header in letterboxd.php." >&2; exit 1; }
[ "$VERSION" = "$CONSTANT" ] || { echo "Version: header ($VERSION) and LBC_VERSION ($CONSTANT) differ." >&2; exit 1; }
grep -q "^## $VERSION " CHANGELOG.md || { echo "CHANGELOG.md has no \"## $VERSION\" section." >&2; exit 1; }

# The website reads the changelog from docs/.
cp CHANGELOG.md docs/changelog.md

# The ZIP holds a letterboxd-connect/ folder, so WordPress installs it under
# that name (GitHub's own "Source code" ZIPs add the version to the folder).
rm -rf dist
mkdir -p dist/letterboxd-connect
rsync -a "${MIRROR[@]}" ./ dist/letterboxd-connect/
(cd dist && zip -qr -X letterboxd-connect.zip letterboxd-connect)
echo "Built dist/letterboxd-connect.zip ($VERSION, $(du -h dist/letterboxd-connect.zip | cut -f1))."

# This version's changelog section, for the release notes.
NOTES="$(awk -v v="$VERSION" '
	$0 ~ "^## " v " " { found = 1; next }
	found && /^## / { exit }
	found { print }
' CHANGELOG.md | sed -e '/./,$!d')"

if [ "$DRY_RUN" = 1 ]; then
	echo "Dry run: nothing committed or pushed. Release notes would be:"
	echo "$NOTES"
	exit 0
fi

if git rev-parse -q --verify "refs/tags/v$VERSION" >/dev/null; then
	echo "Tag v$VERSION exists already. Bump the version first." >&2
	exit 1
fi

git add -A
git commit -q -m "Letterboxd Connect $VERSION" || echo "Nothing new to commit."
git tag -a "v$VERSION" -m "Letterboxd Connect $VERSION"
git push -q origin main "v$VERSION"

# The repo belongs to the dcstagg GitHub account, which may not be gh's active one.
export GH_TOKEN="${GH_TOKEN:-$(gh auth token -u dcstagg)}"
gh release create "v$VERSION" dist/letterboxd-connect.zip \
	--repo "$REPO" --title "Letterboxd Connect $VERSION" --notes "$NOTES" --latest

echo "Released $VERSION: https://github.com/$REPO/releases/tag/v$VERSION"
