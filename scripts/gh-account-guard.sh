#!/usr/bin/env bash
# gh-account-guard.sh — verify this repo can authenticate as its required
# GitHub account, and repair the situation when it cannot.
#
# Why this exists
# ---------------
# Two accounts live in the gh keyring: robwilde (owns this repo, has push) and
# rob-ee-wilde (used on other projects, read-only here). `gh auth switch` sets
# the *globally active* account, so any other project or shell can silently
# break pushes here, producing:
#
#   remote: Permission to robwilde/can-eye-budget-v2.git denied to rob-ee-wilde.
#
# The global credential helper is `!gh auth git-credential`, which serves only
# the active account: asking it for username=robwilde while rob-ee-wilde is
# active returns no credential at all, so git falls through to a 403.
#
# `gh auth token --user <account>` returns one account's token WITHOUT changing
# the active account, which is what lets this repo pin its identity while
# rob-ee-wilde keeps working everywhere else.
#
# Usage
#   scripts/gh-account-guard.sh            # verify; exit non-zero on a problem
#   scripts/gh-account-guard.sh --fix      # verify, and switch the active
#                                          # account if that is the only repair
#                                          # left (last resort, global effect)
#   scripts/gh-account-guard.sh --quiet    # only speak up on failure
#
# The required account defaults to REQUIRED_ACCOUNT below and can be overridden
# per clone with:  git config canieye.githubAccount <login>

set -uo pipefail

REQUIRED_ACCOUNT_DEFAULT="robwilde"

fix=0
quiet=0
for arg in "$@"; do
	case "$arg" in
		--fix) fix=1 ;;
		--quiet) quiet=1 ;;
		-h | --help)
			sed -n '2,32p' "$0" | sed 's/^# \{0,1\}//'
			exit 0
			;;
		*)
			printf 'gh-account-guard: unknown argument %s\n' "$arg" >&2
			exit 2
			;;
	esac
done

say() { [ "$quiet" -eq 1 ] || printf '%s\n' "$*"; }
err() { printf 'gh-account-guard: %s\n' "$*" >&2; }

command -v gh >/dev/null 2>&1 || {
	err "gh CLI not found on PATH; install it or skip with GH_ACCOUNT_GUARD=0"
	exit 1
}
command -v git >/dev/null 2>&1 || {
	err "git not found on PATH"
	exit 1
}

required="$(git config --get canieye.githubAccount || true)"
[ -n "$required" ] || required="$REQUIRED_ACCOUNT_DEFAULT"

# Repo slug from origin, tolerating https and ssh remotes.
origin_url="$(git config --get remote.origin.url || true)"
slug="$(printf '%s' "$origin_url" |
	sed -E 's#^git@[^:]+:##; s#^[a-z]+://[^/]+/##; s#\.git$##')"
if [ -z "$slug" ]; then
	err "could not derive owner/repo from remote.origin.url ('$origin_url')"
	exit 1
fi

# 1. Is the required account authenticated at all? Nothing else can be fixed
#    from here if it is not, so fail with the exact command to run.
if ! gh auth token --user "$required" >/dev/null 2>&1; then
	err "account '$required' is not authenticated in the gh keyring."
	err "run: gh auth login --hostname github.com   (as $required)"
	exit 1
fi

# 2. Pin git's credentials for THIS repository to the required account. This is
#    the actual fix: it is local config, it survives the global active account
#    changing under us, and it leaves other repos alone. Idempotent.
helper_key="credential.https://github.com.helper"
want_helper="!f() { test \"\$1\" = get && printf 'username=%s\\npassword=%s\\n' '$required' \"\$(gh auth token --user '$required')\"; }; f"

# The desired state is exactly two entries: an empty one that discards the
# inherited global helper, then the pinned one. Compare against that, or the
# guard rewrites the config (and says so) on every single run.
current_helpers="$(git config --local --get-all "$helper_key" 2>/dev/null || true)"
want_helpers="$(printf '\n%s' "$want_helper")"
if [ "$current_helpers" != "$want_helpers" ]; then
	git config --local --unset-all "$helper_key" 2>/dev/null || true
	# An empty first entry resets the inherited global helper, so the pinned
	# one below is the only helper git consults in this repo.
	git config --local --add "$helper_key" ""
	git config --local --add "$helper_key" "$want_helper"
	say "gh-account-guard: pinned this repo's git credentials to '$required'."
fi

# 3. Confirm the pinned token actually grants push on this repo. Catches a
#    revoked or under-scoped token before git talks to the remote.
perms="$(GH_TOKEN="$(gh auth token --user "$required")" \
	gh api "repos/$slug" --jq '.permissions.push' 2>/dev/null || true)"
if [ "$perms" != "true" ]; then
	err "account '$required' cannot push to $slug (permissions.push=${perms:-unknown})."
	err "check the token's scopes, or that '$required' still has write access."
	exit 1
fi

# 4. gh CLI subcommands (gh pr create, gh api, ...) read the globally active
#    account and cannot be redirected by git config. Report the mismatch, and
#    only switch when explicitly asked, since the effect is global.
active="$(gh api user --jq .login 2>/dev/null || true)"
if [ "$active" != "$required" ]; then
	if [ "$fix" -eq 1 ]; then
		if gh auth switch --hostname github.com --user "$required" >/dev/null 2>&1; then
			say "gh-account-guard: switched active gh account to '$required' (global)."
		else
			err "failed to switch active gh account to '$required'."
			exit 1
		fi
	else
		say "gh-account-guard: note — git is pinned to '$required', but the active"
		say "  gh account is '${active:-unknown}', so gh subcommands still use that"
		say "  account. Run 'scripts/gh-account-guard.sh --fix' before gh pr/gh api."
	fi
fi

say "gh-account-guard: OK — git in this repo authenticates as '$required' for $slug."
