---
name: model-router
description: Triage what you're about to do and decide whether a different model should do it instead — route mechanical, well-specified work down to Haiku/Sonnet, escalate hard architecture/debugging/security reasoning up to Opus/Fable, and fan independent work out across parallel subagents. Consult this on every request before starting substantive work, not just when the user mentions models, cost, speed, or subagents — the whole point is that the user should never have to ask. Especially relevant for bulk mechanical edits, repo-wide sweeps, migrations, test scaffolding, boilerplate, deep debugging, architecture decisions, security review, and any task that decomposes into independent chunks.
---

# Model router

You are one model in a family with very different cost and capability profiles. Doing every task yourself is the wrong default: a rename across 40 files does
not need frontier reasoning, and a subtle concurrency bug does not deserve a cheap one. Your job here is a fast call about *who should do this*, then get on
with it.

## Step 1 — the bail-out (do this first, in seconds)

**Handle it inline and stop reading if any of these hold:**

- It's conversational, a question, a small edit, or a few tool calls.
- The task depends on context you already have and a subagent doesn't (what the user said three turns ago, a file you already read, a decision you already made
  together).
- It's exploratory — you don't yet know what needs doing.
- It needs interactive back-and-forth with the user.
- You'd spend more words briefing a subagent than doing the work.

**This is the common case.** Most requests are not worth routing. Delegation is not free: a subagent starts cold, re-reads files you've already read, and
reports back through a narrow channel. That overhead is real, and it's why the bail-out comes first.

Only continue if the task is **substantial** *and* **specifiable** — you could write down what "done" looks like in a paragraph, and it's more than a handful of
tool calls.

## Step 2 — pick the model

| Model     | `model` value | Cost vs. Opus | Use for                                                                                            |
|-----------|---------------|---------------|----------------------------------------------------------------------------------------------------|
| Haiku 4.5 | `haiku`       | ~0.2×         | Mechanical, pattern-following work with a clear spec. 200K context.                                |
| Sonnet 5  | `sonnet`      | ~0.6×         | Real coding that doesn't need frontier reasoning. Strong default for bulk implementation.          |
| Opus 5    | `opus`        | 1×            | Hard reasoning, architecture, subtle debugging, security.                                          |
| Fable 5   | `fable`       | 2×            | Reserve for genuinely frontier problems the session model is failing at. Not a default escalation. |

**Route down** when the work is mechanical and well-specified: bulk renames and find-replace with a stated rule, applying one known pattern across many files,
scaffolding tests from an existing example, changelog and doc generation from a diff, config and boilerplate, mechanical migrations where the transform is
already decided. The tell is that *you already know the answer* and what remains is typing.

**Route up** when the session model is below the task: architecture with real tradeoffs, a bug that has survived one honest attempt, security-sensitive logic,
anything where being wrong is expensive and hard to detect. If you are already on Opus, escalating to Fable is rarely right — try harder yourself first.
Escalate on evidence of difficulty, not on the topic sounding hard.

**Fan out** when the work splits into chunks that don't need to see each other: independent files or modules, per-item work across a discovered list, several
unrelated investigations. Launch them in one message so they run concurrently, and pick the model per chunk — a sweep is often Haiku, an audit often Sonnet or
Opus.

Don't route down when correctness is hard to verify, the spec is fuzzy, or the work needs judgment about the surrounding codebase. A cheap wrong answer you then
have to find and fix costs more than doing it right.

## Step 3 — delegate

Use the Agent tool with an explicit `model`. Brief it properly: subagents inherit no conversation history, so state the goal, the exact files or scope, the
convention to follow, and what to report back. A vague brief to a cheap model is how routing goes wrong.

Say the call in **one line** before you spawn, so the user can see and override it:

> Mechanical rename across 41 files, rule is already fixed — sending to Haiku.
> Three independent modules to audit — fanning out to three Sonnet agents.
> This race condition survived my first pass — escalating to a fresh Opus agent with the repro.

One line. Not a section, not a table, not a justification — the user wants the work, not your reasoning about the work.

## Judgment

Verification stays with you. When a subagent reports back, you own whether the result is right — don't relay a cheap model's confident summary as if it were
checked. If you delegated, commit to it: don't redo the work or re-derive its findings.

Route on the shape of the work, not on how the request was phrased. "Just rename this everywhere" and "I need you to carefully update every reference" can be
the same mechanical task. Conversely, a casual "quick question about our auth" can be a security review.

If the routing call is genuinely close, do it yourself. A wrong delegation costs a full round trip; a slightly-too-expensive inline answer costs a little money
and no time.
