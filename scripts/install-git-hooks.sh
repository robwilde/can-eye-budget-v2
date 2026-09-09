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

# Locate the hooks directory via git, not by assuming "$root/.git/hooks":
# in a linked `git worktree` checkout .git is a FILE, that path does not exist,
# and mkdir -p under it fails. git also shares one hooks directory across every
# worktree, so --git-path resolves to the main repository's hooks dir.
dest="$(cd "$root" && git rev-parse --git-path hooks)"
case "$dest" in
	/*) ;;
	*) dest="$root/$dest" ;; # --git-path may return a relative path
esac

# Because that shared hooks directory belongs to the main repository, link to
# the main worktree's scripts rather than this checkout's: a linked worktree
# can be removed later, which would leave every worktree with a dangling hook.
main_root="$(git worktree list --porcelain | sed -n '1s/^worktree //p')"
[ -n "$main_root" ] || main_root="$root"
src="$main_root/scripts/git-hooks"

[ -d "$src" ] || {
	printf 'install-git-hooks: %s missing\n' "$src" >&2
	exit 1
}
mkdir -p "$dest"

skipped=""
for hook in "$src"/*; do
	[ -f "$hook" ] || continue
	name="$(basename "$hook")"
	target="$dest/$name"

	if [ -e "$target" ] && [ ! -L "$target" ]; then
		printf 'install-git-hooks: %s exists and is not a symlink; leaving it alone.\n' "$name" >&2
		printf '  Inspect %s and either delete it or chain it manually.\n' "$target" >&2
		skipped="$skipped $name"
		continue
	fi

	ln -sfn "$src/$name" "$target"
	chmod +x "$hook"
	printf 'install-git-hooks: linked %s\n' "$name"
done

# Assert every hook we ship is actually live. Reporting success while a hook
# was skipped is how a repo ends up believing it is guarded when it is not.
for hook in "$src"/*; do
	[ -f "$hook" ] || continue
	name="$(basename "$hook")"
	target="$dest/$name"
	if [ ! -L "$target" ] || [ "$(readlink "$target")" != "$src/$name" ]; then
		printf '\ninstall-git-hooks: FAILED — %s is not linked to this repo'"'"'s hook.\n' "$name" >&2
		[ -n "$skipped" ] && printf 'Skipped:%s\n' "$skipped" >&2
		exit 1
	fi
done

# Pin credentials now so the very first push works, rather than only after the
# hook has run once.
"$root/scripts/gh-account-guard.sh" || {
	printf '\ninstall-git-hooks: hooks are installed, but the account check failed.\n' >&2
	printf 'Resolve the message above before pushing.\n' >&2
	exit 1
}
