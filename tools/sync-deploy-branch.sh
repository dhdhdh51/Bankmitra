#!/usr/bin/env bash
#
# Regenerate the hosting branch (and its mirrors) from the current branch.
# ---------------------------------------------------------------------------
# The deploy branch is a FLATTENED copy of backend/ (plus database/) so that the
# root of the branch is the root of public_html. That makes it uploadable from a
# phone, but it also means the two branches can drift apart. Run this after any
# change under backend/ or database/ so the deploy branch stays truthful.
#
#   ./tools/sync-deploy-branch.sh
#
# Then push the result:
#   git push origin hosting
#
# The script only ever commits to $DEPLOY_BRANCH and returns you to the branch
# you started on. It refuses to run with a dirty working tree.
set -euo pipefail

# The canonical hosting branch. Simple name, no slash, because a slash makes the
# branch awkward to find in the GitHub mobile UI and produces an odd folder name
# in the downloaded ZIP.
DEPLOY_BRANCH="hosting"

# Kept in step with DEPLOY_BRANCH so an older bookmark does not serve stale code.
MIRROR_BRANCHES=("deploy/public_html")

SOURCE_DIR="backend"
EXTRA_DIRS=("database")
# Files that live in backend/ but must NOT reach a live host.
EXCLUDE_FROM_DEPLOY=("dev-server.php")

cd "$(dirname "$0")/.."
REPO_ROOT="$(pwd)"

if [[ -n "$(git status --porcelain)" ]]; then
    echo "ERROR: working tree is dirty. Commit or stash first." >&2
    git status --short >&2
    exit 1
fi

SOURCE_BRANCH="$(git rev-parse --abbrev-ref HEAD)"
if [[ "$SOURCE_BRANCH" == "$DEPLOY_BRANCH" ]]; then
    echo "ERROR: you are on $DEPLOY_BRANCH. Switch to the source branch first." >&2
    exit 1
fi
if [[ "$SOURCE_BRANCH" == "HEAD" ]]; then
    echo "ERROR: detached HEAD. Check out a named branch first." >&2
    exit 1
fi

SOURCE_SHA="$(git rev-parse --short HEAD)"
echo "Source: $SOURCE_BRANCH ($SOURCE_SHA)"

# Stage the flattened tree outside the repo so nothing can be clobbered.
STAGING="$(mktemp -d)"
cleanup() { rm -rf "$STAGING"; }
trap cleanup EXIT

git archive "$SOURCE_BRANCH" "$SOURCE_DIR" | tar -x -C "$STAGING" --strip-components=1
for d in "${EXTRA_DIRS[@]}"; do
    git archive "$SOURCE_BRANCH" "$d" | tar -x -C "$STAGING"
done
for f in "${EXCLUDE_FROM_DEPLOY[@]}"; do
    rm -f "$STAGING/$f"
done

# Carry over the deploy-branch-only files (README, .gitignore, patched
# .htaccess) from the existing deploy branch so hand edits are not lost.
DEPLOY_ONLY=(".gitignore" "READ-ME-FIRST.md" ".htaccess")
if git rev-parse --verify --quiet "$DEPLOY_BRANCH" >/dev/null; then
    for f in "${DEPLOY_ONLY[@]}"; do
        if git cat-file -e "$DEPLOY_BRANCH:$f" 2>/dev/null; then
            git show "$DEPLOY_BRANCH:$f" > "$STAGING/$f"
            echo "  kept $f from $DEPLOY_BRANCH"
        fi
    done
    git checkout -q "$DEPLOY_BRANCH"
else
    git checkout -q --orphan "$DEPLOY_BRANCH"
    git rm -rq --cached . 2>/dev/null || true
fi

# Replace the working tree wholesale, but never touch .git.
find . -maxdepth 1 -mindepth 1 ! -name .git -exec rm -rf {} +
cp -a "$STAGING"/. .

git add -A
if git diff --cached --quiet; then
    echo "Already in sync - nothing to commit."
else
    git commit -q -m "Sync hosting files from $SOURCE_BRANCH ($SOURCE_SHA)"
    echo "Committed: $(git log --oneline -1)"
fi

echo "Tracked files on $DEPLOY_BRANCH: $(git ls-files | wc -l)"
DEPLOY_SHA="$(git rev-parse HEAD)"
git checkout -q "$SOURCE_BRANCH"
echo "Back on $SOURCE_BRANCH."

# Point the mirrors at the same commit. They are not checked out, so moving the
# ref is enough and cannot touch the working tree.
for mirror in "${MIRROR_BRANCHES[@]}"; do
    if [[ "$mirror" == "$DEPLOY_BRANCH" ]]; then
        continue
    fi
    git branch -f "$mirror" "$DEPLOY_SHA"
    echo "Mirrored $mirror -> $(git rev-parse --short "$mirror")"
done

echo
echo "Next:"
echo "  git push origin $DEPLOY_BRANCH"
for mirror in "${MIRROR_BRANCHES[@]}"; do
    echo "  git push origin $mirror"
done
