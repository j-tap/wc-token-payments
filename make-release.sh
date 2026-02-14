#!/usr/bin/env bash
# Сборка ZIP, коммит, тег, пуш и создание GitHub Release.
# Версия — только в readme.txt (строка "Stable tag: X.Y.Z").
# Использование: ./make-release.sh [версия]
#   без аргумента — версия берётся из readme.txt
#   с аргументом   — сначала в readme.txt пишется эта версия, затем релиз
# Требует: git, gh (GitHub CLI), composer, zip

set -e
NAME="wc-token-payments"
ROOT="$(cd "$(dirname "$0")" && pwd)"
README="$ROOT/readme.txt"

if [[ ! -f "$ROOT/wc-token-payments.php" ]] || [[ ! -f "$README" ]]; then
  echo "Run from plugin root (where wc-token-payments.php and readme.txt are)."
  exit 1
fi

# Версия: из аргумента или из readme.txt
if [[ -n "$1" ]]; then
  if [[ "$OSTYPE" == "darwin"* ]]; then SED_I=(-i ''); else SED_I=(-i); fi
  sed "${SED_I[@]}" "s/^Stable tag: .*/Stable tag: $1/" "$README"
fi
VERSION=$(grep -E '^Stable tag:' "$README" | sed 's/Stable tag: *//' | tr -d '\r')
if [[ -z "$VERSION" ]]; then
  echo "Could not read version from readme.txt (Stable tag: X.Y.Z)"
  exit 1
fi

OUT="${NAME}-${VERSION}.zip"
TAG="v${VERSION}"

# Проверка gh
if ! command -v gh &>/dev/null; then
  echo "Install GitHub CLI: https://cli.github.com/  (brew install gh)"
  exit 1
fi
if ! gh auth status &>/dev/null; then
  echo "Log in to GitHub: gh auth login"
  exit 1
fi

# Подставить версию из readme.txt в wc-token-payments.php
echo "Version: $VERSION (from readme.txt)"
if [[ "$OSTYPE" == "darwin"* ]]; then SED_I=(-i ''); else SED_I=(-i); fi
sed "${SED_I[@]}" "s/^ \* Version: .*/ * Version: $VERSION/" "$ROOT/wc-token-payments.php"
sed "${SED_I[@]}" "s/define('WCTK_VERSION', '[^']*');/define('WCTK_VERSION', '$VERSION');/" "$ROOT/wc-token-payments.php"

# Сборка ZIP
if [[ ! -f "$ROOT/vendor/autoload.php" ]]; then
  echo "Running composer install --no-dev..."
  (cd "$ROOT" && composer install --no-dev --quiet) || { echo "Run: composer install --no-dev"; exit 1; }
fi

TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT
cp -r "$ROOT" "$TMP/$NAME"
rm -rf "$TMP/$NAME/.git" "$TMP/$NAME/make-release.sh" "$TMP/$NAME"/*.zip 2>/dev/null || true
cd "$TMP"
zip -r "$ROOT/$OUT" "$NAME" -x "*.DS_Store" -q
echo "Built: $OUT"

# Git: коммит версии, тег, пуш, релиз
cd "$ROOT"
if [[ -n $(git status --porcelain wc-token-payments.php readme.txt) ]]; then
  git add wc-token-payments.php readme.txt
  git commit -m "Release $VERSION"
fi
if git rev-parse "$TAG" &>/dev/null; then
  echo "Tag $TAG already exists. Delete it to re-release: git tag -d $TAG && git push origin :refs/tags/$TAG"
  exit 1
fi
git tag "$TAG"
git push origin HEAD
git push origin "$TAG"
echo "Pushed $TAG"

gh release create "$TAG" "$ROOT/$OUT" --title "$TAG" --notes "Release $VERSION"
echo "Release $TAG created: $(gh release view "$TAG" --json url -q .url)"
