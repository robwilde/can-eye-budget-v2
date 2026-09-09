#!/usr/bin/env bash
# install-git-hooks.sh — install this repo's own git hooks and pin the GitHub
# identity used for pushes.
#
# Symlinks scripts/git-hooks/* into .git/hooks/ rather than setting
# core.hooksPath, because GrumPHP installs pre-commit, commit-msg, post-commit
# and post-checkout straight into .git/hooks and repointing core.hooksPath
# would stop them running.
#
# Safe to re-run.
#
#   scripts/install-git-hooks.sh

set -euo pipefail

root="$(git rev-parse --show-toplevel)"
src="$root/scripts/git-hooks"
dest="$root/.git/hooks"

[ -d "$src" ] || {
	printf 'install-git-hooks: %s missing\n' "$src" >&2
	exit 1
}
mkdir -p "$dest"

for hook in "$src"/*; do
	[ -f "$hook" ] || continue
	name="$(basename "$hook")"
	target="$dest/$name"

	if [ -e "$target" ] && [ ! -L "$target" ]; then
		printf 'install-git-hooks: %s exists and is not a symlink; leaving it alone.\n' "$name" >&2
		printf '  (GrumPHP-managed hook? merge manually if you meant to replace it.)\n' >&2
		continue
	fi

	ln -sfn "../../scripts/git-hooks/$name" "$target"
	chmod +x "$hook"
	printf 'install-git-hooks: linked %s\n' "$name"
done

# Pin credentials now so the very first push works, rather than only after the
# hook has run once.
"$root/scripts/gh-account-guard.sh" || {
	printf '\ninstall-git-hooks: hooks are installed, but the account check failed.\n' >&2
	printf 'Resolve the message above before pushing.\n' >&2
	exit 1
}
