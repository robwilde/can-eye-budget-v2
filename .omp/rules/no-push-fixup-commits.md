---
name: no-push-fixup-commits
description: "Never run git push while fixup!/squash! commits are on the branch; the user folds them with autosquash first"
condition: ["git\\s+(-C\\s+\\S+\\s+)?push\\b", "gh\\s+pr\\s+(create|merge)\\b", "gh\\s+api\\b[^\\n]*(git/refs|merges)"]
question: "Does this command push, or open or merge a PR from, a branch that still has commits whose subject starts with `fixup!` or `squash!` (for example, commits just made with `git commit --fixup`) that were not yet folded by an autosquash rebase?"
scope: "tool:bash"
---

**Hard rule: never push `fixup!` or `squash!` commits to GitHub.**

This repo merges PRs with `--merge`, not squash. Anything pushed with a `fixup!` subject lands in `develop` verbatim. That is what happened with `da0c327`.

Before any `git push`, `gh pr create` or `gh pr merge`:
1. Run `git log --format=%s origin/develop..HEAD | grep -E '^(fixup|squash)!'`. If it prints anything, **stop**.
2. Commit review fixes locally with `git commit --fixup=<sha>`. Leave them unpushed.
3. Tell the user the fixups are local and ready. Folding them (`git rebase -i --autosquash`) and force-pushing with `--force-with-lease` is the user's call, because rewriting a pushed branch needs their explicit approval.
4. Do not read "fix on branch" or "post the review" as permission to push fixups. Say the fixes are local, or put them in a patch.

The same applies when the prior commit was already pushed: a new fixup stays local until the user has rewritten the branch.