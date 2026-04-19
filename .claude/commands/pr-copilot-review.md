---
description: Create a PR targeting develop, request GitHub Copilot review, poll for its feedback, and classify which suggestions need code changes
---

# PR + Copilot Review Workflow

End-to-end flow for this repo: open a PR against `develop`, request a Copilot code review, watch for Copilot's comments, then summarise which suggestions actually require code changes versus which can be dismissed.

## Parameters

- `issue_number` (optional): The GitHub issue this PR closes (e.g. `193`). If not provided, infer from the current branch name (typical pattern: `feature/<num>-...`).
- Assumes `develop` as the target branch (project rule — no exceptions per `CLAUDE.local.md`).
- Assumes `robwilde` as the active `gh` account.

---

## Step 1 — Pre-flight checks

Run these in parallel:

```bash
gh auth switch --user robwilde                  # ensure correct gh account (no-op if already active)
git status --short                              # working tree should be clean
git rev-parse --abbrev-ref HEAD                 # capture current branch (refuse if main/develop)
git rev-parse --abbrev-ref @{u} 2>/dev/null     # confirm upstream exists (branch pushed)
git log develop..HEAD --oneline                 # verify commits to ship
git diff develop...HEAD --stat                  # files/lines changed
```

Abort if:
- Working tree dirty (ask user to commit/stash)
- Current branch is `main` or `develop`
- No upstream set (`git push -u origin <branch>` first)
- No commits ahead of `develop`

## Step 2 — Compose the PR

Draft title and body from the branch's commits + diff. Conventions observed on this repo:

- **Title:** `feat(#<issue>): <short description>` or `fix(#<issue>): …` / `test(#<issue>): …`
- **Body sections:** `## Summary`, `## Closes`, `## Test plan` (as markdown checklist), optional `## Notes`
- Backtick-escape any backticks in the heredoc body (use `\``) — common PR-body pitfall
- End the body with the `🤖 Generated with [Claude Code](https://claude.com/claude-code)` footer

## Step 3 — Create the PR (targeting `develop`)

```bash
gh pr create --base develop --title "<title>" --body "$(cat <<'EOF'
## Summary
- <bullet 1>
- <bullet 2>

## Closes
- #<issue>

## Test plan
- [x] <verification step>
- [x] <another>

## Notes
<optional>

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

Capture the returned PR URL + number — you'll need the number for Steps 4 and 5.

## Step 4 — Request Copilot review

**Critical:** the only working handle is literally `@copilot` (with the @). Plain `Copilot`, `copilot-pull-request-reviewer`, and GraphQL `requestReviews` with the bot node ID all fail — gh CLI translates `@copilot` into the internal bot-reviewer API call.

```bash
gh pr edit <pr_number> --add-reviewer "@copilot"
```

Expected success output: the PR URL is echoed back. If you see "Could not resolve user with login 'copilot'" you forgot the `@` prefix.

## Step 5 — Poll for Copilot's review

Copilot usually posts a review in **1–5 minutes** for a mid-size PR. Use `ScheduleWakeup` (dynamic `/loop`) with ~270s intervals — stays inside the 5-minute prompt-cache window.

On each wake-up, run:

```bash
# Top-level review body (summary + overall state)
gh pr view <pr_number> --repo robwilde/can-eye-budget-v2 \
  --json reviews,comments \
  --jq '{
    reviews: [.reviews[] | select(.author.login | startswith("copilot")) |
      {state, submittedAt, bodyLen: (.body | length)}],
    issueComments: [.comments[] | select(.author.login | startswith("copilot")) |
      {createdAt, bodyStart: .body[0:160]}]
  }'

# Inline code comments (file:line-anchored)
gh api repos/robwilde/can-eye-budget-v2/pulls/<pr_number>/comments \
  --jq '[.[] | select(.user.login | startswith("copilot")) |
         {path, line, side, body_start: .body[0:200]}]'
```

Stop polling as soon as Copilot's review is posted. Cap the loop at ~20 min — if nothing by then, either Copilot is disabled for the repo or GitHub is having an outage; inform the user.

## Step 6 — Classify suggestions

Group Copilot's findings into three buckets and present them to the user:

1. **Must fix** — genuine bugs, security issues, violations of repo conventions (check `CLAUDE.md` + `CLAUDE.local.md`), failing edge cases, or clear logic errors. Name the file:line and the concrete change needed.
2. **Worth fixing** — style/clarity improvements that align with `spatie-laravel-php-standards` or other active skills in this repo but aren't strictly wrong. User's call.
3. **Dismiss** — false positives (e.g. flagging intentional design-system values, design-handoff verbatim copies, or framework idioms Copilot misreads). Explain why each is a false positive so the user can dismiss with confidence.

**Do NOT make any code changes without user approval.** Present the classification, wait for the user to pick which suggestions to address, then implement only those.

## Step 7 — Apply approved changes

For each accepted suggestion:

1. Make the edit (use `Edit` tool, not `Write` — preserves surrounding context).
2. Run the scoped test suite: `op test.filter <keyword>` or `ddev exec php artisan test --compact tests/<path>` to verify the fix works.
3. Re-run any quality gates affected: `op lint.dirty`, `ddev exec vendor/bin/phpstan analyse --memory-limit=512M <path>`.
4. If Blade/CSS changed, confirm Tailwind build still emits the expected selectors: `ddev exec npm run build`.

Do **not** commit automatically — per `CLAUDE.local.md` the user must explicitly authorise every commit/push. Summarise the applied changes and ask.

---

## Common gotchas (from the session that spawned this command)

- `gh pr edit --add-reviewer Copilot` (no `@`) → `GraphQL: Could not resolve user with login 'copilot'`. Use `"@copilot"`.
- `gh api ... requested_reviewers -f 'reviewers[]=copilot-pull-request-reviewer'` → 422 "not a collaborator". Copilot is a Bot, not a User; standard reviewer endpoints reject it.
- GraphQL `requestReviews` mutation with the Copilot bot node ID (`BOT_kgDOCnlnWA`) → `NOT_FOUND Could not resolve to User node`. The mutation's `userIds` is strictly User-typed.
- Blade `{{ "It's payday" }}` escapes the apostrophe to `&#039;s`. For static UI copy, put the literal inside the Blade markup (`<span>It's payday</span>`), not inside an interpolated expression, so test assertions match.
- Pest forbids `it('…', static function () { })` — the framework rebinds each closure to the TestCase at runtime, and static closures can't be rebound. Use a file-level `/** @noinspection StaticClosureCanBeUsedInspection */` to silence the PhpStorm hint instead.
- `op test.filter "path/fragment"` doesn't work — the `--filter` flag is regex against test *names*, not paths. Use `ddev exec php artisan test --compact tests/Feature/<Path>` instead when you want to scope by directory.
