#!/usr/bin/env bash
# install-git-hooks.sh — install this repo's git hooks and pin the GitHub
# identity used for pushes.
#
# Installs a tiny shim into the hooks directory rather than a symlink into a
# working tree. Git shares ONE hooks directory across every worktree, so a
# symlink would have to name one particular checkout, and any choice is wrong
# somewhere:
#
#   - link to the invoking worktree  -> dangles when that worktree is removed
#   - link to the main worktree      -> breaks when main is on a branch that
#                                       does not contain scripts/git-hooks
#                                       (e.g. main on develop, this work in a
#                                       linked worktree)
#
# The shim instead resolves the pushing worktree at run time and execs that
# checkout's tracked hook, so it survives worktree removal, branch switches,
# and a repo move. If the current checkout has no such hook (an old branch),
# the shim exits 0 rather than making the repo unpushable.
#
# core.hooksPath is deliberately NOT repointed: GrumPHP installs pre-commit,
# commit-msg, post-commit and post-checkout straight into the hooks directory
# and repointing would disable them.
#
# Safe to re-run.
#
#   scripts/install-git-hooks.sh

set -uo pipefail

MARKER="canieye-git-hook-shim"

root="$(git rev-parse --show-toplevel)" || exit 1

# Hooks live in the git dir, which is NOT "$root/.git" in a linked worktree
# (there .git is a file). Ask git, and tolerate a relative answer.
dest="$(cd "$root" && git rev-parse --git-path hooks)"
case "$dest" in
	/*) ;;
	*) dest="$root/$dest" ;;
esac

# Hook names come from this checkout, since it is the one being installed from.
src="$root/scripts/git-hooks"
[ -d "$src" ] || {
	printf 'install-git-hooks: %s missing (run this from a checkout that has it)\n' "$src" >&2
	exit 1
}

mkdir -p "$dest" || {
	printf 'install-git-hooks: cannot create %s\n' "$dest" >&2
	exit 1
}

shim_for() {
	cat <<-SHIM
		#!/usr/bin/env sh
		# $MARKER — installed by scripts/install-git-hooks.sh. Do not edit.
		#
		# Resolves the hook from whichever worktree is pushing, so this file does
		# not depend on any single checkout still existing or still holding the
		# hook. Missing hook (e.g. an older branch) is not an error.
		root="\$(git rev-parse --show-toplevel 2>/dev/null)" || exit 0
		hook="\$root/scripts/git-hooks/$1"
		[ -x "\$hook" ] || exit 0
		exec "\$hook" "\$@"
	SHIM
}

skipped=""
for hook in "$src"/*; do
	[ -f "$hook" ] || continue
	name="$(basename "$hook")"
	target="$dest/$name"

	# Anything already there that is not ours stays put: it is most likely a
	# GrumPHP-managed hook, and silently replacing it would disable a gate.
	if [ -e "$target" ] || [ -L "$target" ]; then
		if ! grep -q "$MARKER" "$target" 2>/dev/null; then
			printf 'install-git-hooks: %s exists and is not ours; leaving it alone.\n' "$name" >&2
			printf '  Inspect %s and either delete it or chain it manually.\n' "$name" "$target" >&2
			skipped="$skipped $name"
			continue
		fi
	fi

	if ! shim_for "$name" >"$target"; then
		printf 'install-git-hooks: failed to write %s\n' "$target" >&2
		exit 1
	fi
	chmod +x "$target" "$hook"
	printf 'install-git-hooks: installed %s\n' "$name"
done

# Assert every hook we ship is actually live. Reporting success while a hook
# was skipped is how a repo ends up believing it is guarded when it is not.
for hook in "$src"/*; do
	[ -f "$hook" ] || continue
	name="$(basename "$hook")"
	target="$dest/$name"
	if [ ! -x "$target" ] || ! grep -q "$MARKER" "$target" 2>/dev/null; then
		printf '\ninstall-git-hooks: FAILED — %s is not installed at %s.\n' "$name" "$target" >&2
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
