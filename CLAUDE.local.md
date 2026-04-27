# Can Eye Budget - Local Development Guidelines

This file contains project-specific guidelines for the Can Eye Budget V2 project.

---

## DDEV Environment (CRITICAL)

**This project runs inside DDEV docker containers. NEVER run any of these directly via Bash:**
- `php artisan ...`
- `composer ...`
- `npm ...`
- `vendor/bin/pint ...`
- `vendor/bin/phpstan ...`

**NEVER use Laravel Boost MCP tools (`tinker`, etc.) as a workaround for running shell commands.**

**NEVER run `op test.*` (or any test invocation) with `run_in_background: true`.** Test runs share the
same DDEV web container; concurrent invocations stack their PHP processes and have caused host-level
CPU/memory saturation requiring a manual `ddev restart`. If a test run hangs, kill it (TaskStop or
Ctrl-C) before launching another. If polling for completion is needed, redirect output to a file and
`tail -f` it instead of re-spawning. The `flock` guard in `op.conf` will reject concurrent `op test*`
invocations with an explicit message — treat that message as a signal to stop, not to retry.

**Always use `op` (OpCode) aliases from `op.conf`.** These automatically route through `ddev exec`.

Common examples:
```bash
op test                    # Run all tests
op test.filter CategoryTest  # Run filtered tests
op lint.dirty              # Pint on dirty files
op migrate.fresh           # Fresh migrate + seed
op seed                    # Run seeders
op make.model User --migration --factory --seed
op make.test UserTest
```

Run `cat op.conf` or read the file if you need to check available aliases.

---

## Git Commands

**NEVER run any git commands (add, commit, push, checkout, branch, etc.) unless the user explicitly asks you to.** This includes:
- Creating or switching branches
- Staging files
- Making commits
- Pushing to remote
- Any other git operations

Wait for explicit instructions before touching git.

---

## Pull Request Base Branch

**All PRs MUST target the `develop` branch. NEVER target `main`.**

When creating a PR, always use:
```bash
gh pr create --base develop
```

This is non-negotiable. There are zero exceptions to this rule.

---

## GitHub Account

The repo belongs to `robwilde`. Before running `gh` commands, ensure the correct account is active:

```bash
gh auth switch --user robwilde
```

---

## GitHub Project Board

Track project tasks and roadmap at: https://github.com/users/robwilde/projects/2

Query project items via CLI:
```bash
# List all project items
gh project item-list 1 --owner robwilde --format json

# View project in browser
gh project view 1 --owner robwilde --web
```

---

## Dev Log Requirement

Since context resets between sessions, we use devlogs to track our work. Devlogs are saved as individual Obsidian-flavoured Markdown files in the Obsidian
vault.

**Vault Path:** `/var/home/mrwilde/Projects/Obsidian/ClaudeCodeVault`
**Devlog Directory:** `DevLogs/CanEyeBudget/`
**Full Path:** `/var/home/mrwilde/Projects/Obsidian/ClaudeCodeVault/DevLogs/CanEyeBudget/`

### Devlog File Format

Each devlog entry is a separate `.md` file using the naming convention: `YYYY-MM-DD_short-description.md`

Example: `2026-03-17_add-builder-avatar-upload.md`

Every devlog file **must** include YAML frontmatter and use Obsidian syntax (wikilinks, callouts, tags):

```markdown
---
date: 2026-03-17
project: Can Eye Budget v2
tags:
  - devlog
  - can-eye-budget-v2
---

---

## Code Quality: The Boy Scout Rule

Every session should improve the codebase, not just add to it. Actively refactor code you encounter, even outside your immediate task scope.

- **Don't Repeat Yourself (Rule of Three):** Consolidate duplicate patterns into reusable functions only after the 3rd occurrence. Do not abstract prematurely.
- **Hygiene:** Delete dead code immediately (unused imports, functions, variables, commented code). If it's not running, it goes.
- **Leverage:** Use battle-tested packages over custom implementations. Do not reinvent the wheel unless the wheel is broken.
- **Readable:** Code must be self-documenting. Comments should explain *why*, not *what*.
- **Safety:** If a refactor carries high risk of breaking functionality, flag it for user review rather than applying it silently.

---

### Operational Rule

After every interaction that includes a code change, you **must** create a devlog file at the vault path before finishing. This is mandatory.

**Goal:** If a new developer (or a new AI session) joins tomorrow, they should be able to browse `DevLogs/CanEyeBudget/` in Obsidian and understand the full state and history of the project immediately.

---

## MCP Tools Available

### Dokploy MCP

Manage staging deployments directly via MCP without using the Dokploy UI.

**Key IDs:**
- Project: `Bc6MlLuvuhhiZ9hVzCstL`
- Staging Environment: `GtCAOGo5_32kZ0SkazCqp`
- Web Application: `q6L3rVbJlA35H-GXkuQjA`
- Scheduler Application: `de50uCfU75TcCqfihx2Mk`
- Database: `comparebuild-comparebuildstagingdb-dq53mb`

**Common Operations:**
```
# Reload container (env var changes, no rebuild)
mcp__dokploy-mcp__application-reload

# Check application status
mcp__dokploy-mcp__application-one

# Update environment variables
mcp__dokploy-mcp__application-saveEnvironment

# Trigger full deploy (rebuilds image)
mcp__dokploy-mcp__application-deploy
```

**Important:** Use `application-reload` for env changes - it restarts the container without rebuilding (~5 sec vs ~3 min).

### Perplexity Search

Use for researching current documentation, best practices, or debugging issues:
- `mcp__perplexity__search` - Quick lookups
- `mcp__perplexity__reason` - Complex multi-step analysis
- `mcp__perplexity__deep_research` - Comprehensive research

### Laravel Boost

Local development helper with database access, route listing, and tinker execution:
- `mcp__laravel-boost__list-routes` - Show all routes
- `mcp__laravel-boost__database-schema` - View DB structure
- `mcp__laravel-boost__tinker` - Execute PHP in app context
- `mcp__laravel-boost__last-error` - Get last exception details

### 5. Playwright Testing Tips

**Client component hydration:** Pages using the server/client/skeleton triad pattern render the layout shell first, then the client component hydrates. Always use `browser_wait_for` with visible text before taking snapshots:
```
mcp__playwright__browser_wait_for with text="<unique text on page>"
```

**Auth redirect testing flow:**
1. Clear token via `browser_evaluate`
2. Navigate to protected page
3. Wait for login form: `browser_wait_for` with text="Sign in"
4. Check URL contains `?redirect=/original/path`

**Responsive testing viewports:**
- Mobile: 375 x 812
- Tablet: 768 x 1024
- Desktop: 1200 x 900 (or 1280 x 720)

**Screenshot naming convention:** `{page}-{viewport}.png` (e.g., `support-mobile-375.png`)
