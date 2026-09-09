# Changelog

## 2.1.0 (2026-08-17)

Orchestrated mode got slow in a way the design did not require: leaves already own disjoint files, but the driver dispatched them one at a time and verified in lockstep, and a bare `gate-check` re-ran the whole tree's checks on every verify. This release makes the concurrency the contract already permitted actually happen.

Fixed, and it was a real hole rather than a slow path:

- **The driver's re-verification never ran.** `gate-check` skips any gate already checked with non-pending evidence, so a leaf that ticked its own boxes and wrote plausible evidence was taken entirely on trust: the command never executed and the run printed `ALL MET`. Layer 2 of the verification hierarchy had no invocation that implemented it for runnable gates. `--recheck` now re-executes every CHECK regardless of what the boxes claim, and a failure resets that gate's evidence to `pending`, which makes it unmet under the format's existing second rule, so the ledger, the exit code and the stop-hook agree. Checkbox semantics are unchanged from 2.0.0: gate-check only ever ticks boxes and never unticks them, since withdrawing the proof under a claim is enough to void it. Manual gates are unaffected by design, since there is no command to re-run: they still need the driver to read the evidence and judge it, which is one more reason to prefer runnable gates.

New in `scripts/gate-check.mjs`:

- **`--jobs <n>`**: checks run concurrently across gates and files (default `min(4, cores-1)`), instead of strictly serially.
- **Shared CHECK commands**: an identical CHECK named by several gates is executed once and its result shared, reported as `(shared result)`. Common when siblings assert against one build.
- **`Jobs: <n>` file header**: a gates file whose checks are not parallel-safe (one port, one browser, one database) caps its own concurrency, and neither lends results to other files nor borrows theirs, since a borrowed result is a concurrent one. A malformed value now warns instead of being silently ignored.

Method changes:

- **Rolling dispatch** replaces one-leaf-at-a-time. The driver launches every leaf whose dependencies are verified, verifies each as it returns rather than at a wave boundary, and immediately dispatches what that unblocks. The slowest leaf now delays only its own dependents.
- **`PLAN.md` carries dispatch data per leaf**: `Owns` (files it may write), `Needs` (leaf ids that must be verified first, machine-readable so the ready set is computed rather than reasoned about), `Tier` (model). Plus an explicit note that coordination files are owned too: the driver owns `PLAN.md` and branch gates, each leaf owns its own gates file.
- **Gate scoping is now a rule.** Leaf gates check only what that leaf owns; whole-project checks (full suite, repo-wide typecheck, build) move to branch gates and run once. Putting `npm test` in a leaf gate costs the tree that suite once per leaf, then again per leaf when the driver verifies.
- **Model tiering prose handed off** to the model-router skill. `orchestration.md` keeps only the two constraints specific to this skill: the driver stays on the strong model, and verification passes are never tiered down.

Unchanged: gate file format, exit codes, stop-hook compatibility, zero dependencies.

## 2.0.0 (2026-08-10)

Enforcement moved from prose into files, checks and an optional hook. Motivated by a controlled six-run test of v1 (two build tasks, three conditions each, independent code review plus adversarial verification plus live browser testing) whose headline results are in the README.

Breaking change to the method's semantics:

- The Depth Tree is now a decomposition tool, not an effort multiplier. Measured runs ignored the 2^(N-1) arithmetic (tree 6 cost about 1.0-1.5x tree 3). Depth now follows the task's natural joints; effort per leaf is enforced by that leaf's gates.

New:

- **Rule zero: gates before work.** Acceptance criteria go into `GATES.md` / `gates/*.md` as checkboxes with runnable `CHECK:` / `EXPECT:` lines and mandatory evidence. Done means the ledger is full, not that the output feels finished.
- **`scripts/gate-check.mjs`**: runs CHECK commands, flips boxes only on EXPECT match, records capped evidence, treats checked-without-evidence as unmet. Zero dependencies, Node 16+.
- **`scripts/stop-hook.mjs`** (Claude Code, optional): Stop hook that blocks ending the turn while gates are unmet. Progress-aware loop guard: counter resets when gate files change, releases with a warning after 6 blocked stops without progress, honors `ABANDON: <gate> <reason>` as an honest exit.
- **`scripts/install-hooks.mjs`**: idempotent install/uninstall into project `settings.local.json` (default), shared `settings.json` (`--shared`) or `~/.claude/settings.json` (`--global`).
- **Orchestrated mode** for tree 4+: `PLAN.md` contract fixed before fan-out, one gates file per leaf and per branch, leaves run as fresh subagents, parent re-runs each leaf's checks (self-certification counts for nothing). Templates in `templates/`.
- **Report audit rule**: every number in a final report is re-measured at report time or labeled unverified. In testing, wrong numbers in confident reports were the single most reproducible failure that survived v1.
- **Token economy** guidance, measured: checks as subprocesses instead of model re-reading, capped evidence, lean leaf briefs, append-only status logs, model tiering for mechanical leaves.
- References split out for progressive disclosure: `references/method.md`, `references/gates.md`, `references/orchestration.md`, `references/token-economy.md`.

Kept from v1: the four work passes, contracts before fan-out, continuation forcing (now mechanical: run gate-check, refute one passed gate), finish one line of attack, do not simulate work you can do, resource-anxiety rule (now with a concrete handover path), full-sweep counting.

## 1.0.0 (2026-08-10)

Initial release.

- SKILL.md with the Depth Tree method as the core: estimate T once at the root, split binary N layers deep, every leaf gets the full T, iterate each leaf until a pass finds nothing to improve.
- Nine enforcement rules grounded in 2025-2026 research on model laziness, underthinking, overthinking, long-horizon degradation and context anxiety.
- Spec-compliant frontmatter (name, description, license, metadata) so the skill loads in Claude Code, OpenAI Codex CLI, Cursor and the skills CLI (`npx skills add Leonxlnx/unlazy`).
- README with install matrix, method explanation, and an annotated research list.
