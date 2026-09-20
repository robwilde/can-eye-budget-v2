# Dev Log


## 2026-09-20 — Issue #433: Sentry error monitoring — PR #434

### The Change

Nothing in this application reported exceptions to an error tracker. `bootstrap/app.php` carried an empty `withExceptions()` closure and `LOG_STACK=stderr` hands logs to the Docker json-file driver, so a staging failure was visible only by reading a container log and vanished with the container. `sentry/sentry-laravel` is now a runtime dependency — `require`, not `require-dev`, because it has to run in the production image — and `Integration::handles($exceptions)` in `bootstrap/app.php` routes every exception Laravel decides to report through to Sentry. Registering through `Integration::handles()` rather than a bespoke `reportable` callback keeps Laravel's own `shouldntReport` list authoritative, so validation, authentication and 4xx HTTP exceptions never reach Sentry and no custom ignore list exists to drift. One hook covers all three container roles: web requests, Horizon queue workers and scheduled commands all report through the same handler.

A single project DSN serves every environment. Events separate themselves through the `environment` tag, which is deliberately left unset in config so the SDK derives it from `APP_ENV` — `local` under DDEV, `staging` in the Dokploy containers, where `docker/entrypoint.sh` already exports `APP_ENV` before any artisan call. No per-environment Sentry variable exists to be forgotten or set wrong. A blank `SENTRY_DSN` disables the SDK outright rather than failing at boot.

Three things are off on purpose. `send_default_pii` stays `false` because this is a finance application and user identities and IP addresses must not leave it. SQL bindings stay `false` in both breadcrumbs and tracing, because those parameters carry transaction amounts and narrations. `enable_logs` stays `false`: stderr into the container log remains the log surface, and Sentry receives exceptions only, not the log stream. Tracing and profiling are env-gated and off by default, so `SENTRY_TRACES_SAMPLE_RATE` can enable them per environment later with no code change.

**Files modified:**
- `composer.json` / `composer.lock` — `sentry/sentry-laravel ^4.27` in `require`, pulling `sentry/sentry 4.31.0`
- `config/sentry.php` (new) — published unmodified from the SDK, then a policy docblock prepended and `declare(strict_types=1)` added by Pint
- `bootstrap/app.php` (+9/-2) — `use Sentry\Laravel\Integration;` and the `withExceptions` body
- `.env.example` (+4) — commented `SENTRY_DSN=` block after the `GMAIL_*` lines
- `docs/dokploy-staging-plan.md` (+1) — `SENTRY_DSN` row in the environment matrix

### The Reasoning

- **The published config was already the policy.** Every key this change depends on ships at the required default in `sentry/sentry-laravel` 4.27: `dsn` already reads `env('SENTRY_LARAVEL_DSN', env('SENTRY_DSN'))`, `environment` and `release` are already null, both sample rates are already `env(...) === null ? null : (float) env(...)`, `send_default_pii` and `enable_logs` are already `false`, `ignore_transactions` already holds `/up`, and both `sql_bindings` switches are already `false`. So the file was published and verified rather than rewritten, and the only edits are a docblock explaining *why* those defaults are being relied on and the `declare(strict_types=1)` this repository requires. Restating defaults as explicit values would have created a second copy to drift against the SDK for no behavioural gain.
- **`SENTRY_LARAVEL_DSN` is deliberately unused.** It exists in the published config's fallback chain, but introducing it would mean two variables meaning the same thing, with the more obscure one silently winning. Only `SENTRY_DSN` is documented and only `SENTRY_DSN` is set.
- **No frontend SDK, no user context.** The UI is server-rendered Livewire, so `@sentry/browser` would add an npm dependency and publish the DSN to every visitor for little signal. User identity is a deliberate omission that follows from `send_default_pii=false`, not an oversight. Handled per-phase Redbark errors continue to land in `redbark_sync_logs` via `SyncRedbarkFeedJob::appendError` — they are not exceptions and are out of scope.
- **No new test.** The change is a framework hook with no branch of its own; a test would assert that a vendor method was called, which is implementation, not observable behaviour. The proof is live events, recorded below.

### Verification

Local, against the real DSN:

| check | result |
|---|---|
| `ddev artisan sentry:test` | `DSN discovered` → test event `f68145bae8f14e30aa7bc31b52d34a14` |
| `report(new RuntimeException('sentry-plan-local-probe'))` through the new hook | event `9e7abb5a96944c83ba8ea244cb7e62ac` |
| resolved SDK options at runtime | `environment='local'`, `send_default_pii=false`, `traces_sample_rate=NULL`, `release=NULL` |
| both SQL-binding switches and `enable_logs` | `false` |
| `ddev exec env SENTRY_DSN= php artisan sentry:test` | `Could not discover DSN!`, exit 1 — blank disables rather than breaking boot |

The second row is the one that matters: `report()` producing an event ID proves the `withExceptions` hook is wired, not merely that the SDK can reach Sentry.

**Quality gates:** Pint 455 files pass, PHPStan `No errors`, Pest 2280 passed (5849 assertions).

### Staging

Merged as `2ee98d6` and deployed to all three staging services. `SENTRY_DSN` went on via the `application.saveEnvironment` API endpoint; the Dokploy **MCP** wrapper for it returns HTTP 400 for every payload, including a byte-identical re-save, because it omits the `buildArgs`, `buildSecrets` and `createEnvFile` fields the endpoint requires. The call was made directly against the API with those three echoed back verbatim from each record. `application.update` was deliberately not used — it rewrites the whole application record, and a nulled `command`/`args` would replace the image `ENTRYPOINT` and bypass #430's role dispatch. Each service was then confirmed to hold exactly one extra variable, every other variable byte-identical, `command`/`args` still empty.

| check | result |
|---|---|
| `sentry:test` in `can-eye-web` | event `efde8019aa5b4fc9b026b6dd1addf69e` |
| `report()` through the hook in `can-eye-web` | event `0e8fdca7ca2c4c93809293a1149d22c9`, `environment='staging'` |
| failing closure job on `redis@default` | picked up by the live Horizon worker, recorded in `queue:failed` |
| in-process `queue:work --once` of the same failing job | event `0f878f05e25348b0ada74cbe91e583c7`, `environment='staging'` |
| container health through the rollout | all three `running (healthy)`; `/up` 200, `/` 200, `/login` 200, `/register` 404, `/horizon` 403 |

No `SENTRY_ENVIRONMENT` is set anywhere: `local` and `staging` both derive from `APP_ENV`, so one DSN keeps the two environments apart by itself. Probe failures were flushed (`No failed jobs found` afterwards, from a zero baseline) and no probe files remain in any container.


## 2026-09-13 — Issue #423 hardening: Pin the `env()` Null Coercion — PR #428

### The Change

`docker/entrypoint.sh:65-67` unsets `APP_ENV` for exactly the literals `env()` decodes as PHP `null`. That mirror is hand-written and, until now, entirely unenforced: the only record of the dependency was a comment above the block citing `Env.php:256` and `:266-268` — vendor line numbers that a `composer update` can move, or repoint at different behaviour, with **no diff to any file this repository owns and no failing check**. The comment would keep reading plausibly while describing a coercion that no longer exists, and the `APP_ENV=null` hole #427 closed would reopen silently. A new test pins the framework contract itself instead of citing where it lives. Merged as `97c2332` (commits `25a70f2`, `4087c4c`, footer `Refs #423`). Issue #423 stayed **closed** — this hardens the fix that closed it rather than reopening it.

**Files modified:**
- `tests/Unit/EnvCoercionTest.php` (+77) — new file; 14 cases across three `test()` blocks, plus an `envCoercionOf()` probe helper that snapshots and restores the variable it sets

Test-only change. No production code touched.

### The Reasoning

- **Three layers, and now all three are defended.** `tests/Unit/RayConfigTest.php` pins what `ray.php` computes *given* a value; `tests/Unit/EntrypointAppEnvTest.php` pins what the *shell* actually exports; this pins the *framework coercion* that justifies the shell block existing at all. None of the three substitutes for the others: a `RayConfigTest` row says nothing about whether the entrypoint exported anything, an `EntrypointAppEnvTest` row says nothing about whether `env()` still reads `null` as absence, and this file says nothing about either consumer. The mirror needed its own pin because it is the only one of the three whose other half lives in `vendor/`.
- **Asserted on behaviour, never on vendor source.** No assertion touches `Env.php` text or line numbers — every expectation is on what `env()` *returns*. That is the distinction that makes the test worth keeping: a vendor line moving, a reformat, or a refactor inside the switch is harmless and must not fail; the semantics changing is the thing that reopens the hole, and now only that fails. A test that grepped the vendor file would have inverted both.
- **The `env()` helper, not `Env::get()`.** `env()` is the surface the entrypoint is written against, so it is the surface asserted on — and it works in `tests/Unit` despite there being no booted application: `Illuminate/Support/helpers.php:150-153` defines it as a direct `Env::get()` delegation with no container involvement, and Composer's `files` autoload makes it resolvable even though `tests/Pest.php` binds `Tests\TestCase` only to `Feature` and `Browser`. Recorded honestly: the first draft used `Env::get()` and justified the choice with a docblock claim that the helper was *unavailable without a booted container*. That claim was **false**. Copilot flagged it, and the implementation was switched to the helper rather than the comment reworded into something narrower — the cheaper fix would have left the test asserting on the wrong surface for a reason that was never true. A dead `Env::getRepository()` call and a memoisation claim in the same docblock went the same way, both disproved by probe before removal rather than after.
- **Copilot returned Balanced, with 2 valid findings.** The first **Balanced** review since 2026-09-12T23:21Z, ending a six-review Lite streak. The accepted one that changed behaviour: the probe helper unconditionally `unset` its variable on the way out instead of restoring whatever was there before. It now snapshots the prior value and restores it in a `finally`, distinguishing genuinely absent (`getenv()` returning `false`) from a real prior value. Severity was scoped honestly rather than inflated to match the reviewer's label: `CAN_EYE_ENV_COERCION` is unique to this file, so no live order-dependency existed and no test was actually at risk — but restore-to-previous is only *accidentally* equivalent to unset here, and accidental equivalence is not a property worth depending on in a parallel runner.

### Verification

Red state was proven the only way it can be for a vendor contract: by mutating `vendor/laravel/framework/src/Illuminate/Support/Env.php` and watching the pins fail.

| mutation to vendor `Env.php` | result |
|---|---|
| `strtolower($value)` → `strtolower(trim($value))` | **1 failed** — `Failed asserting that null is identical to ' null '` |
| `strtolower($value)` → `$value` | **3 failed** — `NULL`, `nUlL`, `(NULL)` |

The vendor file was restored **byte-identical** after each mutation, verified with `cmp` both times. The `trim()` mutation was then re-run *after* the switch from `Env::get()` to `env()`, to confirm the coverage survived the implementation change rather than assuming it had — the delegation is direct, but that was checked rather than argued.

What would break these tests, each row of the file corresponding to one way the mirror can fail:

| change in the framework | which pin catches it |
|---|---|
| a `trim()` added before the match | `' null '` would start decoding as absent while the shell still exports it |
| `strtolower()` dropped | `NULL`/`(NULL)` would stop decoding as null while the shell still unsets them |
| either `null` case removed | the shell would unset a value the framework now reports as real |
| a new sentinel introduced | the framework would read as absent a value the shell exports |
| `false`/`true`/`empty` demoted to absence | the shell's deliberate decision to preserve them would become wrong |

**Quality gates:**
- `op test.parallel` — 2259 passed, 5743 assertions (2245 / 5729 → **+14/+14**, exactly the 14 cases in the new file)
- `op lint.check` — PASS, 451 files (450 → 451, the new test file)
- `op analyse` — PHPStan, 0 errors, 222 files
- Copilot review (**Balanced**) — 2 findings, both valid, both fixed in `4087c4c`
- **CI on `97c2332`:** green

---

## 2026-09-13 — Issue #423 follow-up: Treat the Null Sentinels as Absent — PR #427

### The Change

`docker/entrypoint.sh:65-67` now drops the `null` and `(null)` literals before the `production` fallback is reached, so a sentinel value can no longer satisfy the presence guard and skip the default. The block sits between the `.env` parser and the fallback added by #426:

```sh
case "${APP_ENV-}" in
    [Nn][Uu][Ll][Ll]|'('[Nn][Uu][Ll][Ll]')') unset APP_ENV ;;
esac
```

It applies to both sources — an already-exported value and a `.env`-parsed one — because it runs after each has had its turn. Merged as `659e2c1` (commit `9646a4e`, footer `Refs #423`). Issue #423 stayed **closed**: #426 closed it correctly, and this closes the same hole reached by a different route rather than reopening it.

**Files modified:**
- `docker/entrypoint.sh` (+14, -2) — the `case` block at lines 65-67 plus the comment above it recording that the two literals are Laravel's own encoding of absence, that matching is case-insensitive and untrimmed to mirror `Env.php`, and which neighbouring forms are deliberately *not* dropped
- `tests/Unit/EntrypointAppEnvTest.php` (+131) — new file; 19 cases executing the real entrypoint
- `.env.example` (+6, -4) — the sentinel residue documented by #426 removed from the caveat, since it no longer exists
- `docs/dokploy-staging-plan.md` (+1, -1) — `RAY_ENABLED` row re-pointed at the normalising entrypoint

### The Reasoning

- **The layer mismatch is the whole bug.** The shell guard asked *"is the variable present?"*; `env()` asks *"did a meaningful value arrive?"*. `Env.php:256` is `switch (strtolower($value))`, and `:266-268` maps the literals `null` and `(null)` to PHP `null`. Those literals are Laravel's own encoding of **absent**. So the two layers disagreed on the definition of absence, and the disagreement was the hole: `APP_ENV=null` was present, the `production` fallback therefore did not fire, `env('APP_ENV')` still returned `null`, and the `??` in `ray.php:40` handed resolution back to `config('app.env')`.
- **The earlier decline was wrong, and it is worth recording why.** During #426 this was declined on the grounds that `null` is "a value a source supplies", and therefore outside the no-source defect #423 described. That reading is mistaken. It is true at the shell layer and false at the layer that consumes the variable — and the consumer is the only layer whose opinion decides whether Ray transmits. Aligning the guard with `env()`'s own definition of absent is *agreement* with `env()`, not a shell-side override of it; the entrypoint is not inventing a normalisation rule, it is declining to hand `env()` a string that `env()` has already told us it treats as nothing.
- **Mirrors `Env.php` exactly rather than approximating it.** Matching is case-insensitive, because `Env.php:256` lowercases before switching — `NULL`, `nUlL` and `(NULL)` all normalise. Matching is *untrimmed*, because there is no `trim()` in that path — `' null '` is not a sentinel to `env()` either, so it is not one here. Approximating in either direction would put the shell and PHP back out of step, which is the defect being fixed.
- **The neighbouring sentinels are deliberately preserved.** `empty`, `false`, `true` and the parenthesised `(empty)`/`(false)`/`(true)` forms are left alone. `env()` reports each of them as a *value* — `''`, `false`, `true` — not as absence, and each compares unequal to `'local'`, so Ray already fails closed on all of them. Only `null` and `(null)` vanish into PHP `null`, and only those two are dropped.

### Verification

Composed through the real shell block and then `ray.php:40`, against a config cache deliberately baked at `local` so that `config('app.env')` is the stale `'local'`:

| `APP_ENV` | before (#426) | after (#427) |
|---|---|---|
| `production` | false | false |
| `null` | TRUE — Ray transmits | false |
| `(null)` | TRUE — Ray transmits | false |
| `NULL` | TRUE — Ray transmits | false |
| `staging` | false | false |
| `''` empty | false | false |
| `false` | false | false |
| `local` | TRUE | TRUE (correct — developer machine) |
| unset | false | false |

Three rows flip and the rest hold: exactly the sentinel set, nothing else.

**The entrypoint is now under test.** `tests/Unit/EntrypointAppEnvTest.php` — 19 cases, 57 assertions — runs the **real** `docker/entrypoint.sh` under `env -i` with `php` and `chown` stubbed onto `PATH`, and uses the script's own `exec "$@"` hand-off to probe the value actually exported. Executed rather than line-sliced deliberately: the block has already drifted three times (`33-55` → `33-59` → `33-71`), and an excerpt-based assertion would keep passing after the code it claimed to cover had moved. Parallel-safe via a temp directory keyed on `getmypid()` plus 8 random bytes (`tests/Unit/EntrypointAppEnvTest.php:30`). Red-before-green confirmed: all 7 sentinel cases fail against the pre-fix entrypoint.

That closes a gap `RayConfigTest` never covered. `RayConfigTest` pins what `ray.php` does *given* an `APP_ENV`; nothing previously proved the entrypoint exported anything at all, in any case — the export behaviour from #416, #419 and #426 had been verified only by hand.

**Quality gates:**
- `op test.parallel` — 2245 passed, 5729 assertions (2226 / 5672 → +19/+57, exactly the new file), stable across three runs
- `op lint.check` — PASS, 450 files (449 → 450, the new test file)
- `op analyse` — PHPStan, 0 errors, 222 files
- `shellcheck docker/entrypoint.sh` — 0 findings, and 0 under explicit `-s sh`
- Copilot review (Lite) — 0 findings
- **CI on `659e2c1`:** green

---

## 2026-09-13 — Issue #423: Default `APP_ENV` to `production` — PR #426

### The Change

`docker/entrypoint.sh` exported `APP_ENV` only when `.env` existed. A container where the variable was neither exported **nor** present in `.env` left it unset by design, so `ray.php:40` fell through to `config('app.env')` — and a cache baked at `local` and then shipped elsewhere resolved `local`, made `enable` evaluate `true`, and had **Ray transmitting from a non-local container**. The only mitigation was an out-of-band `RAY_ENABLED=false` an operator had to remember to export. The fix adds a fallback immediately after the `.env` parse block, at `docker/entrypoint.sh:57-59`:

```sh
if [ -z "${APP_ENV+x}" ]; then
    export APP_ENV=production
fi
```

Resulting precedence: **exported OS var > `.env` > `production`**. Merged as `dc3f09f` (commits `977904a`, `c2710dd`, `8578a03`).

**Files modified:**
- `docker/entrypoint.sh` (+10, -6) — the fallback block plus comment corrections: the guarantee re-scoped off "total on every path", and the stale-cache hazard reworded into the counterfactual it is
- `.env.example` (+5, -4) — Ray caveat re-scoped for the new default and the remaining sentinel residue named
- `docs/dokploy-staging-plan.md` (+1, -1) — `RAY_ENABLED` row rewritten for the fallback and its citation re-pointed

### The Reasoning

- **`production` invents nothing.** `config/app.php:31` is `'env' => env('APP_ENV', 'production'),` — the framework's own default for this key. The entrypoint is not introducing a convention, it is making Laravel's existing one reachable before `config:cache` runs, where `env()` can still see it. It is also the fail-closed choice, and the only behaviour it changes is the previously broken case: a container with no `APP_ENV` source running off a stale cache.
- **The guard tests presence, not truthiness.** `${APP_ENV+x}` distinguishes unset from empty, so an exported `''` and an exported `false` are left exactly as they were. Both are deliberate non-local signals under the `??` semantics from #416 — `env()` reports each as a value, and each compares unequal to `'local'` — and overwriting them with `production` would silently discard an operator's explicit choice while changing nothing about the outcome.
- **Three Copilot findings (Lite), all accepted.** The first draft's claim that `APP_ENV` resolution was now "total on every path" was overstated, because of the `null`/`(null)` sentinels: the guard tests presence, `APP_ENV=null` *is* present, so the fallback does not fire, yet `env()` still returns `null`. Verified against `Env.php:266-268` rather than argued, and that correction produced `c2710dd` — the guarantee is total with respect to *presence*, which is what #423 asked for, but is not a total Ray guarantee, and the docs now say only the narrower thing and name the residue. (That residue is what #427 subsequently closed.) The stale-cache sentence in the comment block was likewise reworded to "Absent this block a cache built at `local` …", since sitting above a block that forecloses it, the bare conditional read as a live warning — `8578a03`.
- **Half of one finding was declined, with reasoning.** The suggestion to also amend the *parser* comment was refused: its "each resolves to a non-local value here" is scoped to the three unparsed forms named immediately before it — `${VAR}` interpolation, double-quote escapes, multi-line values — and each of those genuinely does yield a literal non-local string. The sentinel exception is stated once, generally, above; repeating it inside the parser comment would add length and no information.
- **No test added, deliberately.** `tests/Unit/RayConfigTest.php:135` already pinned `'production environment beats a stale cache baked at local' => ['production', 'local', false]` — the exact assertion this change relies on. What was genuinely unpinned at that point was the entrypoint's own export behaviour, and covering that needed a different kind of test than `RayConfigTest` is; #427 added it.

### Verification

Exercised as the real script, with the entrypoint's own `exec "$@"` handing off to a probe that prints the exported value:

| exported `APP_ENV` | `.env` | exported after the block |
|---|---|---|
| unset | absent | `production` |
| `staging` | absent | `staging` |
| unset | `APP_ENV=local` | `local` |

Row 1 is #423 closed — the no-source path no longer leaves the variable unset for the cache to answer. Rows 2 and 3 prove the fallback is strictly last: neither an exported value nor a `.env` value is overwritten by it.

**Quality gates:**
- `op test.parallel` — 2226 passed, 5672 assertions (unchanged; no PHP behaviour changed)
- `op lint.check` — PASS, 449 files
- `op analyse` — PHPStan, 0 errors, 222 files
- `shellcheck docker/entrypoint.sh` — 0 findings, and 0 under explicit `-s sh`
- **CI on `dc3f09f`:** green

---

## 2026-09-13 — Issue #419: Export `APP_ENV` Before `config:cache` — PR #420

### The Change

`docker/entrypoint.sh` now exports `APP_ENV` as a real OS variable before it runs `php artisan config:cache`, so `env('APP_ENV')` always resolves and the env-first precedence added by #416 actually governs instead of falling through to the config cache. The block sits at `docker/entrypoint.sh:33-51`, ahead of the first `php artisan` call at `docker/entrypoint.sh:62` and the `config:cache` call at `docker/entrypoint.sh:68`:

```sh
if [ -z "${APP_ENV+x}" ] && [ -f .env ]; then
    app_env_value=$(grep -E '^[[:space:]]*APP_ENV=' .env 2>/dev/null | head -n 1 | cut -d= -f2- | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//' -e 's/^"\(.*\)"$/\1/' -e "s/^'\(.*\)'\$/\1/") || app_env_value=''
    if [ -n "$app_env_value" ]; then
        export APP_ENV="$app_env_value"
    fi
fi
```

**Files modified:**
- `docker/entrypoint.sh` (+36, -6) — the export block plus a comment block at lines 17-32 recording why `env()` cannot see `.env` post-cache, that an exported value is authoritative, that a present-but-empty value is exported deliberately, and what the no-value path actually resolves to
- `.env.example` (+5, -4) — Ray note re-scoped: the `APP_ENV` half of the old caveat is closed by the entrypoint export, the `RAY_ENABLED`-only-in-`.env` half is not and is called out explicitly
- `docs/dokploy-staging-plan.md` (+1, -1) — `RAY_ENABLED` row rewritten for the export, citing `docker/entrypoint.sh:33-51`
- `DEVLOG.md` — this entry; the #416 entry's `docker/entrypoint.sh:32` citation re-pointed to `:68` because the insertion moved `config:cache`

### The Reasoning

- **Three behaviours, one precedence rule.** An already-exported `APP_ENV` is never overwritten, because on Dokploy the container environment is the authoritative signal. Absent that, `.env` is consulted — and a key that is present but empty is exported as empty, because `ray.php` treats an empty `APP_ENV` as a deliberate non-local signal rather than as absence. Absent both, the variable is left unset: no invented value, and the boot is not failed over a missing developer convenience.
- **What the no-source path actually resolves to.** The first draft's comment claimed it "falls through to `ray.php`'s `production` default, which fails closed". That is wrong, and Copilot caught it. The `'production'` literal in `ray.php:36` is only reached when no `config` repository is bound; under a normal bootstrap `app()->has('config')` is true, so a null `env('APP_ENV')` resolves to `config('app.env')` — the *cached* value. With no `.env` that is `production` (measured, case C), so it does fail closed in the realistic deployment, but it is not guaranteed by the literal. Leaving the variable unset is the instructed behaviour; the comment was corrected to describe the real mechanism and to name the stale-`local`-cache case as the residual, mitigated by exporting `RAY_ENABLED=false`.
- **Parsed, not sourced, and parity-checked against phpdotenv.** `.env` is untrusted shell input: it can contain `#`, `$`, quotes and backticks that a `.` would execute or mangle. The value is extracted with `grep`/`sed` instead. The first draft matched only `APP_ENV=value`; Copilot pointed out that phpdotenv also accepts `export APP_ENV=`, whitespace around `=`, and inline comments. Measured against the framework rather than assumed, all of those hold, and unquoted values end at the *first* `#` (`APP_ENV=loc#al` → `'loc'`). The parser now matches `^[[:space:]]*(export[[:space:]]+)?APP_ENV[[:space:]]*=`, takes the quoted span for quoted values while discarding a trailing comment, and truncates unquoted values at `#` before trimming.
- **Last definition wins — caught by measurement, not review.** The first draft used `head -n 1` and a comment asserting "first match wins, matching phpdotenv's non-overwriting load". Measured: a `.env` with `APP_ENV=local` then `APP_ENV=production` resolves to `'production'`. Laravel's loader overwrites within the file, so last wins. Changed to `tail -n 1` and the comment corrected.
- **`[[:space:]]` over `\s`, measured rather than assumed.** The first draft used `\s` and a comment asserting BusyBox grep lacks it. Checked in the actual `php:8.4-fpm-alpine` image (BusyBox v1.37.0) and `\s` *does* match — so the comment was wrong and was corrected. `[[:space:]]` is kept because only it is POSIX-guaranteed, not because this image needs it.
- **`${APP_ENV+x}` rather than `-z "$APP_ENV"`.** The script is `#!/bin/sh` with `set -e`. `${APP_ENV+x}` distinguishes unset from empty, is `set -u`-safe if that flag is ever added, and treats an exported-but-empty `APP_ENV` as set, so it is left alone as authoritative.
- **Key presence is decided on the matched line, not the parsed value.** The `[ -n ... ]` test is applied to the matched `.env` *line*, so "no such key" (leave unset) stays distinguishable from "key present, value empty" (export empty). Testing the parsed value instead — as the first draft did — silently collapsed the second case into the first and reopened the fail-closed hole.
- **`|| app_env_line=''` is load-bearing under `set -e`.** When no `APP_ENV` line matches, `grep` exits 1. The pipeline's status is `tail`'s, so the assignment happens to survive, but relying on that is invisible. The explicit guard makes the no-match path unambiguously non-fatal — pinned by case D, which exits 0 with a `.env` containing no usable `APP_ENV`.
- **Ahead of every `php artisan` call, not merely ahead of `config:cache`.** The first draft sat after `storage:link` and `migrate`, both of which boot the framework and evaluate `ray.php`; Copilot caught that those two processes would still have run under the old precedence. The block moved to `docker/entrypoint.sh:33-51`, immediately after role validation and before the first artisan invocation at line 62. It sits outside every role branch, so `web`, `horizon` and `scheduler` all reach it.

### Verification

Entrypoint behaviour is not covered by the PHP suite, so the change was exercised as the real script inside `php:8.4-fpm-alpine` (Alpine 3.24.1, `/bin/sh -> busybox`) — the same base as the Dockerfile runtime stage — with the repo mounted, a controlled `.env`, and the entrypoint's own `exec "$@"` handing off to a probe that prints `env('APP_ENV')`, `config('app.env')` and the real root `ray.php` `enable`. The repository `.env` was never touched; an isolated copy was used.

| Case | exported `APP_ENV` | `.env` | `env('APP_ENV')` | `enable` | exit |
|---|---|---|---|---|---|
| A | `production` | `APP_ENV=local` | `'production'` | `false` | 0 |
| B | unset | `APP_ENV=local` | `'local'` | `true` | 0 |
| C | unset | absent | `NULL` | `false` | 0 |
| D | unset | `#APP_ENV=staging` + `MY_APP_ENV=decoy` | `NULL` | `false` | 0 |
| E | unset | `APP_ENV=local` | `'local'` | `true` | 0 for `web`, `horizon`, `scheduler` |
| F | unset | `APP_ENV="local"` / `'local'` / padded | `'local'` | `true` | 0 |
| G | unset | `APP_ENV=` (empty) | `''` | `false` | 0 |
| H | unset | `export APP_ENV=local` | `'local'` | `true` | 0 |
| I | unset | `APP_ENV=local # dev only` | `'local'` | `true` | 0 |
| J | unset | `APP_ENV=local` then `APP_ENV=production` | `'production'` | `false` | 0 |

A proves the exported value wins; B proves #416 stays fixed; C and D prove the fail-closed path does not abort the boot under `set -e`; E covers all three roles including the `su-exec` hand-off; F covers the quoting and whitespace forms; G, H, I and J cover the four parsing gaps found in review and by measurement.

**Parser/framework parity.** Fifteen `.env` forms, comparing what the entrypoint exports against what phpdotenv resolves for the same file: **0 mismatches**, covering `export` prefixes, whitespace around `=`, quoted and unquoted inline comments, `APP_ENV=loc#al` → `'loc'`, empty and whitespace-only values, duplicate keys, commented keys, decoy keys, and an absent key.

**The empty-value hole, isolated.** With a cache baked at `local` and a `.env` reading `APP_ENV=`:

| entrypoint behaviour | `env('APP_ENV')` | `config('app.env')` | `enable` |
|---|---|---|---|
| empty discarded, `APP_ENV` left unset (first draft) | `NULL` | `'local'` (stale) | **`true`** |
| empty exported (shipped) | `''` | `'local'` (stale) | **`false`** |

**Ordering.** `sh -x` trace of a real run: `export 'APP_ENV=local'` appears before `php artisan storage:link --force`, confirming the move ahead of the first framework boot.

**The mechanism, isolated.** With a config cache deliberately baked at `local` while `.env` reads `production`:

| exported `APP_ENV` | `env('APP_ENV')` | `config('app.env')` | `enable` |
|---|---|---|---|
| unset — the pre-fix condition | `NULL` | `'local'` (stale) | **`true`** |
| `production` — what the entrypoint now guarantees | `'production'` | `'local'` (stale) | **`false`** |

That is #419 reproduced and closed: the export makes `ray.php:36` immune to a stale cache.

**Scope stated honestly.** Running the pre-fix and post-fix entrypoints A/B over the same `.env`-only scenario, the final `enable` is `false` either way, because the entrypoint rebuilds the config cache from `.env` on every boot and that rebuild already masked the end-to-end symptom in the default flow. The measured delta is `env('APP_ENV')`: `NULL` before, `'production'` after. The value of the change is that Ray's decision no longer depends on the config cache being current — which is exactly the "exported, build-time-independent environment signal" #419 asked for — not that it flips the observable outcome of the happy path.

**Quality gates:**
- `op test.parallel` — 2226 passed, 5672 assertions (unchanged from baseline; no PHP behaviour changed)
- `op lint.check` — PASS, 449 files
- `op analyse` — PHPStan, 0 errors
- `tests/Unit/RayConfigTest.php` — 23 passed, 23 assertions
- `shellcheck docker/entrypoint.sh` — 0 findings, and 0 under explicit `-s sh`

---

## 2026-09-13 — Issue #416: Env-First `APP_ENV` Resolution for Ray — PR #418

### The Change

The root `ray.php` `enable` default now resolves `APP_ENV` env-first, so a config cache can no longer decide Ray is off on a developer's machine. Final expression at `ray.php:36`:

```php
'enable' => env('RAY_ENABLED', (env('APP_ENV') ?? (app()->has('config') ? config('app.env', 'production') : 'production')) === 'local'),
```

Any non-null `env('APP_ENV')` wins; `config('app.env')` is consulted **only** when `env('APP_ENV')` is `null`.

**Files modified:**
- `ray.php` (+24, -1) — The one-line default from #397 replaced with the env-first `??` expression, plus a comment block at lines 13-34 recording the precedence rule, the `??`-vs-`?:` choice (lines 21-24), the `RAY_ENABLED`/`config/ray.php` escape hatch, and the framework-less path
- `tests/Unit/RayConfigTest.php` (+81) — 12 new datasets over the existing `rayConfigForEnvironment()` isolation pattern (`$_ENV` + `$_SERVER` + `putenv`); the new helper binds and restores its own container because `tests/Unit` boots no app, so `app()->has('config')` would otherwise depend on test ordering
- `.env.example` (+14, -6) — Ray note rewritten for `??` semantics including the empty/`false` cases, scoped to exported OS variables, citing `ray.php:36`
- `docs/dokploy-staging-plan.md` (+1, -1) — `RAY_ENABLED` row rewritten for the resolution order and the `config:cache` interaction, citing `ray.php:36`, `ray.php:137`, `ray.php:142`

### The Reasoning

- **The bug is the cache, not the default.** `docker/entrypoint.sh:68` runs `php artisan config:cache` on *every* container start. Once a cache exists, `LoadEnvironmentVariables` returns early and never reads `.env`, so an `APP_ENV` that lives only in `.env` is invisible to `env()`: the old `env('APP_ENV', 'production')` collapsed to `production` and left Ray off even locally. Verified against a genuine `config:cache` rather than taken on trust — post-cache, `env('APP_ENV')` is `NULL` while `config('app.env')` is still `'local'`.
- **Env-first, not the issue's option 2.** Config-first fixes #416 but regresses the fail-closed guarantee #391 introduced: a stale cache baked at `local` would re-enable Ray against an exported `APP_ENV=production`. Env-first fixes the bug *and* keeps that guarantee for exported OS variables.
- **`??` rather than `?:`, caught in review.** Laravel's `Env` treats only `null` as absent, so the raw return is not always a non-empty string: `APP_ENV=''` yields `''`, `APP_ENV=false` yields boolean `false`, and `APP_ENV=empty` yields `''`. `?:` discarded all three as falsy and fell through to the cache — which, with a cache baked at `local`, re-enabled Ray: a narrow reopening of the same #391 failure mode this change claims to preserve. `??` keeps those values, and they compare unequal to `'local'`, so Ray stays off.
- **Honest residue.** Laravel coerces the literal strings `null` and `(null)` to PHP `null`, so `APP_ENV=null` still falls back to the cache even with `??`. That is inherent to `env()` — an unset variable and the literal `null` are indistinguishable at this call site. Documented in the comment block rather than papered over.
- **`app()->has('config')` is load-bearing; the draft's other guards are not.** Measured with the autoloader loaded and no application bootstrapped: `app()` is defined and returns a bare `Illuminate\Container\Container`, and `app()->has('config')` is `false` without throwing. So the recovered draft's `function_exists('app')` guard and `catch (\Throwable)` arm are dead code and were deliberately omitted; the `has('config')` check is genuinely required. No closure and no function declaration are used — `ray.php` is re-`include`d by a shutdown hook, so a declared function would fatal on redeclare.
- **No `config/ray.php`.** Spatie's `SettingsFactory::searchConfigFilesOnDisk()` (`vendor/spatie/ray/src/Settings/SettingsFactory.php:50-79`) searches upward from `config/`, so a `config/ray.php` would *shadow* the root file entirely. Measured, not assumed.

### Verification

**Quality gates:**
- `op test.parallel` — 2226 passed, 5672 assertions (baseline 2214 / 5660 → +12/+12, exactly the 12 new datasets)
- `op lint.check` — PASS, 449 files
- `op analyse` — PHPStan, 0 errors

**Caveat reported rather than glossed:** PHPStan's configured paths are `app`, `bootstrap`, `config`, `database/factories`, `database/seeders`, `routes` — root `ray.php` and `tests/` are *not* in scope, so the green gate does not itself analyse the changed files. Analysed explicitly, `ray.php` holds at 24 pre-existing `larastan.noEnvCallsOutsideOfConfig` notices, identical to HEAD (24 → 24), and `tests/Unit/RayConfigTest.php` reports no errors. Zero new errors introduced.

**Tests proven load-bearing** by swapping the expression and re-running the file:

| `ray.php` variant | `RayConfigTest` result |
|---|---|
| old `develop` one-liner | 1 failed — catches #416 |
| option 2 (config-first) | 3 failed — catches the #391 regression |
| `?:` (the reviewed hole) | 3 failed (`''`, `'false'`, `'empty'`) |
| shipped `??`, env-first | 23 passed |

**CI on `f7dda87`:** `ci (8.4)`, `ci (8.5)`, `quality` — all completed/success.

### The Known Limitation

Env-first protects **exported OS variables only**. A config cache baked at `local` and then run somewhere non-local, with `APP_ENV` present only in `.env`, still enables Ray — because post-cache `.env` is never read, and that same fallback is precisely what fixes #416. The mitigation is to export `RAY_ENABLED=false` as a real OS variable, which the suite pins.

Worth stating plainly: a `.env`-only `RAY_ENABLED=false` was never a working off-switch post-cache. Under the old expression `env('RAY_ENABLED')` was equally `NULL`, and Ray was off only because `env('APP_ENV', 'production')` collapsed to `production` — which *is* bug #416. The switch was inert before this change too.

Raised by Copilot review `5190351459` and left as an open owner decision rather than silently closed.

---
## 2026-09-13 — Issue #396: Worker Liveness Probes — PR #409

### The Change

Implemented role-specific liveness probes for `horizon` and `scheduler` containers, replacing the unconditional `exit 0` that masked crashed workers.

**Files created:**
- `tests/Feature/Console/SchedulerHeartbeatTest.php` — 47 lines; pins the `scheduler:heartbeat` task is registered and runs every minute
- `storage/framework/.gitignore` — Excludes `scheduler.heartbeat` from version control

**Files modified:**
- `docker/healthcheck.sh` (+28, -2) — Added role-specific probes: horizon uses `php artisan horizon:status` (exit 0/1/2 per state), scheduler checks heartbeat file freshness (120s threshold), web unchanged, unknown roles still exit 1 with diagnostics
- `bootstrap/app.php` (+12) — Scheduled `scheduler:heartbeat` task runs every minute, writes timestamp to `storage/framework/scheduler.heartbeat`
- `Dockerfile` (+4) — Added 4-line `HEALTHCHECK` documentation explaining probe strategy, detection windows, and acceptable fault tolerance
- `docs/dokploy-staging-plan.md` (+3, -6) — Updated probe descriptions (lines 313, 327, 365) to reflect new role-specific mechanisms instead of "exit 0"

### The Reasoning

- **Horizon via `php artisan horizon:status`**: Measured at 0.13s boot cost, 51MB peak RSS; 36× timeout margin on the 5s HEALTHCHECK window. Correctly marks unhealthy if Horizon supervisor crashes or Redis becomes unreachable (acceptable behaviour — queue workers need Redis).
- **Scheduler via filesystem heartbeat**: A `pgrep` check would only detect process existence (scheduler can sit wedged while alive). Heartbeat file proves active dispatch. Writes every 60s, threshold 120s tolerates tick jitter and slow boots without hiding a dead scheduler. Shell `stat` read costs 0.01s (no Laravel boot needed).
- **Threshold choice (120s)**: Scheduler runs every 60s (framework minimum), HEALTHCHECK probe interval is 15s × 5 retries = 75s worst case. Math: 60s tick + jitter > 120s threshold catches stale. Documented in Dockerfile HEALTHCHECK comment.
- **Privilege drop safety**: Workers run as `www-data` (from PR #393); heartbeat file location under `storage/` (writable by `www-data` via `docker/entrypoint.sh` chown before privilege drop).

### Verification

**Quality gates (all passing):**
- Pint: 446 files, 0 issues
- PHPStan: 0 errors
- Tests: 2200 passed (5633 assertions, up from 2199)

**Real container testing:**
- Horizon running → exit 0 (healthy)
- Horizon killed (t+18s) → exit 2 (unhealthy)
- Horizon with Redis stopped → exit 1 (unhealthy)
- Scheduler running → exit 0 after ~43s initial heartbeat
- Scheduler killed → exit 1 after 120s threshold (measured ~125s)
- **Scheduler never started → exit 1** (⭐ regression fix for issue #396)
- Web role: unchanged (no regression of #400/#402)

**Shell syntax:**
- `sh -n docker/healthcheck.sh` — clean
- `shellcheck docker/healthcheck.sh` — clean

**Copilot review:**
- Status: Changes recommended (5 findings)
- All addressed in follow-up commit `0a91a71`:
  1. Added test coverage (`SchedulerHeartbeatTest`)
  2. Removed `withoutOverlapping()` (would cause 24h stale window if scheduler crashed while holding lock)
  3. Added `scheduler.heartbeat` to `.gitignore`
  4. Documented HEALTHCHECK strategy in Dockerfile comment
  5. Terminology: "Redis key TTL" → "Horizon stale-master cutoff"
- Effort level: Lite

**Detection latencies (with `--start-period=90s --interval=15s --timeout=5s --retries=5`):**
- Web: ~75s
- Horizon: ~90s (14s stale window + 75s probe)
- Scheduler: ~195s (120s threshold + 75s probe)

All within acceptable bounds for staging deployment.

### The Tech Debt

- None introduced. Scheduled task pattern matches existing `Schedule::call(...)->everyFiveMinutes()->withoutOverlapping()` style in `routes/console.php`.

---
## 2026-09-13 — Dokploy staging hardening — PRs #388–#400

### The Change

Nine PRs merged into `develop`, in this order: #390, #393, #388, #389, #392, #395, #397, #398, #400. Together they make the container image role-aware, move the deployment's observability onto the container log stream, turn developer tooling off outside a developer machine, and close two ways a public staging domain could be poked at (open registration, an unthrottled `/up`).

**Files created:**
- `docker/healthcheck.sh` (#390) — Role-aware health check. The image's single `HEALTHCHECK` previously probed the web endpoint in every container, so `horizon` and `scheduler` containers reported `unhealthy` forever. Copilot's Balanced review caught that the first role test was fail-open — an unrecognised `CONTAINER_ROLE` fell through to the web probe — fixed in `d94425a` with a `case` that rejects unknown roles.
- `app/Http/Controllers/HealthCheckController.php`, `resources/views/health-up.blade.php` (#392) — Explicit controller and view backing `/up`, replacing the framework's implicitly registered route so the endpoint can carry middleware.
- `tests/Feature/HealthCheckTest.php` (#392) — Pins the throttle, the 200, and the maintenance-mode exemption.
- `tests/Concerns/DisablesRegistration.php`, `tests/Feature/Auth/RegistrationDisabledTest.php` (#395) — Exercise the flag through a real config load and route-registration pass, not by poking `config()` after boot.
- `tests/Unit/RayConfigTest.php` (#397) — Pins the fail-closed Ray default.

**Files modified:**
- `Dockerfile` (#390, #393) — `HEALTHCHECK` delegates to `docker/healthcheck.sh`. Worker containers drop to `www-data` via `su-exec`; the web container stays root so nginx can bind :80.
- `docker/entrypoint.sh` (#390, #393, #400) — Migration gating moved off `RUN_MIGRATIONS` onto `CONTAINER_ROLE`, then gained the privilege drop, then (#400) validation of `CONTAINER_ROLE` against `web|horizon|scheduler` *before any side effect*, symmetric with `docker/healthcheck.sh`. Previously a typo silently skipped migrations and dropped privileges with no diagnostic.
- `.env.example` (#388, #389, #395, #398) — `LOG_STACK=stderr` and `LOG_LEVEL=info`; `RAY_ENABLED=false` and `BOOST_ENABLED=false`; `FORTIFY_REGISTRATION_ENABLED`; then the Ray note corrected by #398.
- `docs/dokploy-staging-plan.md` (#390, #388, #389, #392, #395, #398) — Staging environment matrix and rationale kept in step with each change. #395 conflicted here; resolved by keeping the `CONTAINER_ROLE` and `FORTIFY_REGISTRATION_ENABLED` rows and dropping the stale `RUN_MIGRATIONS` row.
- `bootstrap/app.php` (#392) — `/up` registered explicitly behind `throttle:60,1`, with the maintenance-mode exemption preserved.
- `config/fortify.php`, `resources/views/welcome.blade.php`, `tests/Feature/Auth/RegistrationTest.php` (#395) — Registration gated behind `FORTIFY_REGISTRATION_ENABLED`; the landing page's register links guarded with `Route::has` so disabling the flag does not turn `/` into a 500.
- `ray.php` (#397) — Line 13 default changed to `env('RAY_ENABLED', env('APP_ENV', 'production') === 'local')`, and the hardcoded `local_path` removed.
- `.gitignore` (#395) — Added `.env.testing`.

Three of the nine were review or correction follow-ups rather than new behaviour. Copilot's Balanced review corrected a false rationale in #388 (`57ffd90`) and an overstated latency claim in #389 (`6426fe9`). On #392 it flagged that the maintenance test wrote the checkout-wide `storage/framework/down` marker and could hand parallel workers spurious 503s; `8df4b96` binds an in-memory `MaintenanceMode` fake instead, plus an `expect(isDownForMaintenance())->toBeTrue()` guard so the test cannot pass vacuously. #398 is a pure docs correction: #397 silently falsified text #389 had just landed — `.env.example` and `docs/dokploy-staging-plan.md:373` still claimed `ray.php` defaults Ray to `true`. It also corrected a second error in that text: demonstrating the 2s Ray timeout needs a routable-but-dropping address, because unresolvable `host.docker.internal` fails DNS in ~0.024s, refused `127.0.0.1` in ~0.002s, and Ray caches unavailability for 30s per process (`Client.php:67`).

### The Reasoning

- One mechanism now drives three behaviours. `CONTAINER_ROLE` gates migrations, selects the health probe, and decides the privilege drop. The previous split — `RUN_MIGRATIONS` for one, an implicit web assumption for the other — allowed a container to be configured correct on one axis and wrong on another.
- Role validation belongs at the top of both scripts, and must fail closed. A fail-open role test degrades exactly where it matters: a misconfigured worker looks healthy (#390's first cut) or quietly runs with no migrations and reduced privileges (#400). #400 was proved safe by differential testing — all four in-contract cases are byte-identical to the prior script, and only `weeb` changed behaviour (rc 0 with all side effects → rc 1 with none).
- Merge order alone does not keep prose true. #389 documented Ray's default; #397 then changed that default and left the documentation stating the opposite. Neither PR was wrong in isolation, and no reviewer on either one could have seen it — the falsification only exists in the pair. #398 had to exist. The generalisation: when a PR changes a default that another PR *documents*, the documentation is a dependency, and something has to re-check it after both land.

### The Tech Debt

- ~~`CONTAINER_ROLE=""` (empty string) is still accepted as `web` by both `docker/entrypoint.sh` and `docker/healthcheck.sh`, because `${VAR:-default}` substitutes on empty as well as unset. The validation added in #400 catches typos, not blanks.~~ Closed by #402: both validation `case` statements now read `${CONTAINER_ROLE-web}` (no colon), which substitutes only when the variable is *unset*, so an empty value falls through to a dedicated `"")` branch and exits 1 before any side effect. Unset remains the web default — the `Dockerfile` sets no `ENV CONTAINER_ROLE`. Proved by the same differential method as #400: only the empty-string row changed on either script, every other row byte-identical.
- ~~Issue #396 remains open: the `horizon` and `scheduler` health checks return 0 unconditionally, so those containers carry no worker liveness signal — they are only "not failing the web probe", which is not the same as working.~~ Closed by PR #409: `docker/healthcheck.sh` now uses `php artisan horizon:status` for horizon and a filesystem heartbeat for scheduler, with documented latency bounds and comprehensive test coverage.
- `AGENTS.md:99` mandates squash-merge, but the repo has `allow_squash_merge: false`; all nine landed as merge commits. Related: `delete_branch_on_merge=false` meant GitHub did not auto-retarget stacked PRs, so #393 (stacked on #390) had to be retargeted by hand.

### Verification

Integrated on `develop` after all nine merges:

- `op test.parallel` — 2199 tests passed
- `op lint.check` — Pint clean on 445 files
- `op analyse` — PHPStan, no errors

---

## 2026-03-19 — Extract BasiqServiceContract Interface for Testability

### The Change

Extracted `BasiqServiceContract` interface from `BasiqService` (which is `final readonly` and cannot be mocked by Mockery). Refactored `ConnectBank` component and its tests to use the interface.

**Files created:**
- `app/Contracts/BasiqServiceContract.php` — New interface with all 7 public methods from `BasiqService`, carrying over PHPDoc `@throws` and generic return type annotations.

**Files modified:**
- `app/Services/BasiqService.php` — Added `implements BasiqServiceContract`.
- `app/Providers/AppServiceProvider.php` — Singleton binding now keyed on `BasiqServiceContract::class`. Added `alias()` so existing code resolving `BasiqService::class` (e.g. `BasiqServiceTest`) continues working.
- `app/Livewire/ConnectBank.php` — `connect()` method now type-hints `BasiqServiceContract` instead of `BasiqService`.
- `tests/Feature/Livewire/ConnectBankTest.php` — Replaced `Http::fake()` with Mockery mock of `BasiqServiceContract`. Uses `shouldReceive`/`shouldNotReceive` expectations. Extracted `fakeBasiqService()` helper with sensible defaults and optional callback for per-test overrides.

### The Reasoning

- `BasiqService` is `final readonly` — Mockery cannot extend it to create test doubles. The only way to mock it is via an interface.
- `Http::fake()` coupled tests to the Basiq API's HTTP contract (URLs, request shapes). Mocking at the interface boundary tests the component's logic, not the service's HTTP internals.
- The `alias()` binding in AppServiceProvider ensures backward compatibility: any code that resolves `BasiqService::class` from the container still gets the same singleton instance.

### The Tech Debt

- None introduced. This is a net improvement in testability.

---

## 2026-03-19 — PR #39: Address Copilot Review Comments

### The Change

Addressed all 7 Copilot review comments on PR #39 across 3 categories:

**Files modified:**
- `app/Services/BasiqService.php` — Added `@throws RequestException` to `createUser()`, `getAccounts()`, `paginateTransactions()`, `getJob()`. Also added `@throws ConnectionException` to `paginateTransactions()` which had no throws annotations at all.
- `app/Livewire/Actions/Logout.php` — Added `RedirectResponse|Redirector` return type (union needed because Livewire swaps the redirect service at runtime), inline `@phpstan-ignore` for the unused type branch. Pint also applied `final class` and `declare(strict_types=1)`.
- `phpstan-baseline.neon` — Cleared to empty `ignoreErrors: []` since the Logout return type is now properly declared.
- `DEVLOG.md` — Fixed stale references: `final readonly` → `final` (extends Dto), `tests/Unit/DTOs/` → `tests/Feature/DTOs/`, architecture constraint descriptions updated.

### The Reasoning

- **Union return type on Logout**: PHPStan (via Larastan) sees `redirect('/')` as returning `RedirectResponse`, but Livewire replaces the redirect service at runtime, returning its own `Redirector` (extends `Illuminate\Routing\Redirector`). A strict `RedirectResponse` return type passes static analysis but fails at runtime in Livewire test context. The union covers both code paths.
- **Inline `@phpstan-ignore` over baseline**: The suppression is documented at the source with the reason, rather than hidden in a baseline file.

### The Tech Debt

- None introduced.

### Verification

- `op lint.dirty` — Pint clean
- `op analyse` — PHPStan clean, no baseline entries needed
- `op test` — 195 tests pass (458 assertions), full suite green

---

## 2026-03-19 — Issue #12: Refactor DTOs to Use Spatie Laravel Data v4

### The Change

Refactored 4 hand-rolled DTOs (`BasiqUser`, `BasiqAccount`, `BasiqTransaction`, `BasiqJob`) to extend `Spatie\LaravelData\Dto`. Replaced manual `fromArray()` factory methods with the inherited `from()` and `collect()` pipeline.

**Files modified:**
- `app/DTOs/BasiqUser.php` — Extends `Dto`, removed `fromArray()` (direct mapping via `from()`)
- `app/DTOs/BasiqAccount.php` — Extends `Dto`, replaced `fromArray()` with `prepareForPipeline()` for `class.type` fallback
- `app/DTOs/BasiqTransaction.php` — Extends `Dto`, replaced `fromArray()` with `prepareForPipeline()` for `enrich` destructuring
- `app/DTOs/BasiqJob.php` — Extends `Dto`, uses `#[Computed]` for derived `status` property
- `app/Services/BasiqService.php` — All `fromArray()` calls → `from()`, `collect()->map()` → `BasiqAccount::collect()`
- `tests/Arch.php` — DTO arch constraint changed from `toBeReadonly()` → `toExtend(Dto::class)->toBeFinal()`

**Files moved:**
- `tests/Unit/DTOs/*Test.php` → `tests/Feature/DTOs/*Test.php` (Dto::from() requires the Laravel service container)

### The Reasoning

- `readonly class` is incompatible with `extends Dto` in PHP 8.4 (parent must also be readonly). Used `final class` with `public readonly` constructor-promoted properties instead — still immutable at the property level.
- DTO tests moved to Feature/ because `Dto::from()` resolves through the service container (`app(DataConfig::class)` internally), which isn't available in Pest Unit tests (per `tests/Pest.php:14-16`).
- `prepareForPipeline()` chosen over `#[MapInputName]` for `BasiqAccount` and `BasiqTransaction` because they need multi-source fallbacks and destructuring that dot-notation mapping can't express.

### The Tech Debt

- None introduced. This is a pure refactor — all 195 tests pass, no API changes to `BasiqService` consumers.

---

## 2026-03-13 — Issue #1: Scaffold Laravel 12 with Livewire Starter Kit

### The Change

Completed the scaffolding verification and configuration on top of the initial Laravel 12 + Livewire starter kit install.

**Files modified:**
- `config/database.php` — Configured SQLite WAL mode, busy timeout (10s), synchronous normal
- `routes/web.php` — Added smoke-test route behind auth middleware

**Files created:**
- `app/Services/.gitkeep`, `app/DTOs/.gitkeep`, `app/Enums/.gitkeep` — Base directory structure
- `app/Livewire/SmokeTest.php` — Counter component (multi-file format)
- `resources/views/livewire/smoke-test.blade.php` — Smoke test Blade view
- `resources/views/smoke-test.blade.php` — Page wrapper using app layout
- `tests/Feature/SmokeTestComponentTest.php` — 3 Pest tests: renders, increments, auth guard

### The Reasoning

- **SQLite WAL mode**: Enables concurrent reads/writes critical for web apps. `busy_timeout=10000` prevents "database locked" errors. `synchronous=normal` balances performance and durability.
- **Multi-file component for SmokeTest**: The project uses view-based (⚡) SFC for full-page settings components, but standalone reusable components belong in `app/Livewire/` as separate classes — matching the existing `Logout` action pattern.
- **Directory structure**: `Services/`, `DTOs/`, `Enums/` created early so the team has clear conventions from day one.

### The Tech Debt

- None introduced. The smoke test component can be removed once real features are in place.

### Verification

- `npm run build` — Vite + Tailwind CSS v4 compiles cleanly
- `./vendor/bin/pest --compact` — 36 tests pass (82 assertions)
- `./vendor/bin/pint --dirty --format agent` — All PHP files pass formatting

## 2026-03-13 — Issue #2: Create Account Model, Migration, Factory, and Seeder

### The Change

Built the Account domain layer: enums, migration, model, factory, seeder, and full test coverage.

**Files created:**
- `app/Enums/AccountClass.php` — 9-case string-backed enum matching Basiq API types (kebab-case values)
- `app/Enums/AccountStatus.php` — 3-case string-backed enum (active, inactive, closed)
- `database/migrations/2026_03_13_000000_create_accounts_table.php` — accounts table with user FK (cascade delete), unique nullable basiq_account_id, 3-char currency default AUD, bigint balance in cents
- `app/Models/Account.php` — Eloquent model with fillable, enum casts, BelongsTo user relationship
- `database/factories/AccountFactory.php` — Default transaction state + 8 composable states (savings, creditCard, loan, mortgage, investment, withBasiq, inactive, closed)
- `database/seeders/AccountSeeder.php` — 6 diverse accounts for test@example.com user
- `tests/Feature/Models/AccountTest.php` — 17 feature tests covering factory states, relationships, cascade delete, enum casting, uniqueness
- `tests/Unit/Enums/AccountClassTest.php` — 3 unit tests for case count, backing values, from() resolution
- `tests/Unit/Enums/AccountStatusTest.php` — 2 unit tests for case count and backing values

**Files modified:**
- `app/Models/User.php` — Added `accounts(): HasMany` relationship
- `database/seeders/DatabaseSeeder.php` — Added `AccountSeeder::class` call after user creation

### The Reasoning

- **Cents as integers**: `bigInteger('balance')` stores cents to avoid floating-point precision issues in financial calculations. All downstream code must divide by 100 for display.
- **Enum casts**: Using PHP 8.1 backed enums with Laravel's `casts()` method provides type safety from database to application layer. Invalid values throw exceptions immediately rather than silently passing.
- **Composable factory states**: States like `withBasiq()` can be chained with any account type (`Account::factory()->savings()->withBasiq()->create()`), keeping test setup expressive and DRY.
- **Cascade delete on FK**: When a user is deleted, all their accounts are automatically cleaned up at the database level — no orphaned records.

### The Tech Debt

- None introduced. The `.gitkeep` in `app/Enums/` from Issue #1 can be removed now that real enum files exist.

### Verification

- `php artisan migrate:fresh --seed --no-interaction` — Migration and seeder run cleanly
- `php artisan test --compact` — 57 tests pass (124 assertions)
- `vendor/bin/pint --dirty --format agent` — All PHP files pass formatting

## 2026-03-13 — PR #29: Address Copilot Review Comments

### The Change

Created missing `phpstan-baseline.neon` file referenced by `phpstan.neon.dist`.

**Files created:**
- `phpstan-baseline.neon` — Empty baseline with `parameters: ignoreErrors: []` so PHPStan can load successfully

### The Reasoning

- **Copilot flagged 4 comments on PR #29.** After assessment: 1 was valid (missing baseline file), 1 was valid but correct as-is (nullable institution — intentional for Basiq API compatibility), 1 was invalid (Copilot wrong about `notPath` in pint.json), and 1 was cosmetic (OPCODE_SYNTAX.md scope).
- **Only the baseline fix required a code change.** Without this file, PHPStan would fail immediately on any `vendor/bin/phpstan analyse` invocation with a file-not-found error.

### The Tech Debt

- None introduced. PHPStan has 3 pre-existing errors in `app/Models/User.php` that should be addressed in a future session.

### Verification

- `vendor/bin/phpstan analyse --no-progress --memory-limit=512M` — Runs successfully (3 pre-existing errors, no config/baseline errors)

## 2026-03-14 — Issue #3: Create Transaction Model, Migration, Factory, and Seeder

### The Change

Built the Transaction domain layer with a prerequisite Category model, enums, migrations, factories, seeders, and full test coverage.

**Files created:**
- `app/Enums/TransactionDirection.php` — 2-case string-backed enum (debit, credit)
- `app/Enums/TransactionStatus.php` — 2-case string-backed enum (posted, pending)
- `database/migrations/2026_03_14_000000_create_categories_table.php` — Self-referencing categories with nullable parent_id FK (nullOnDelete)
- `database/migrations/2026_03_14_000001_create_transactions_table.php` — Full transaction schema: user/account/category FKs, amount in cents, direction, description, post_date, Basiq fields, enrich_data JSON, composite index on [user_id, post_date]
- `app/Models/Category.php` — Eloquent model with self-referencing parent/children relationships + transactions HasMany
- `app/Models/Transaction.php` — Eloquent model with enum casts, date casts, array cast for enrich_data, BelongsTo relationships
- `database/factories/CategoryFactory.php` — Default category + withParent() state
- `database/factories/TransactionFactory.php` — Default debit transaction with Australian merchant data + 5 states (debit, credit, withCategory, fromBasiq, pending)
- `database/seeders/CategorySeeder.php` — 12 parent categories with 30+ subcategories
- `database/seeders/TransactionSeeder.php` — 20 varied transactions for test user across multiple accounts
- `tests/Feature/Models/TransactionTest.php` — 22 feature tests covering factory validity, all states, relationships, cascade/null-on-delete, enum casting, date casting, JSON casting, unique basiq_id
- `tests/Unit/Enums/TransactionDirectionTest.php` — 3 unit tests
- `tests/Unit/Enums/TransactionStatusTest.php` — 3 unit tests

**Files modified:**
- `app/Models/User.php` — Added `transactions(): HasMany` relationship
- `app/Models/Account.php` — Added `transactions(): HasMany` relationship
- `database/seeders/DatabaseSeeder.php` — Added CategorySeeder and TransactionSeeder calls

### The Reasoning

- **Categories as prerequisite**: The transaction schema specifies `category_id` as a FK to `categories`. Creating a minimal Category model/migration first keeps the FK constraint valid and avoids tech debt of a missing reference.
- **Consistent FK behaviour**: `user_id` and `account_id` use `cascadeOnDelete` (matching Account pattern — when the owner goes, so do their transactions). `category_id` uses `nullOnDelete` because categories are classification metadata, not ownership — deleting a category shouldn't delete transactions.
- **Factory user consistency**: The TransactionFactory creates `$user` once and passes it to both `user_id` and `Account::factory()->for($user)`, ensuring the transaction's user and its account's user are always the same entity.
- **Composite index [user_id, post_date]**: Most budget queries will be "show me my transactions for this date range" — this index makes that query efficient.

### The Tech Debt

- None introduced.

### Verification

- `php artisan migrate:fresh --seed --no-interaction` — All 7 migrations and 3 seeders run cleanly
- `php artisan test --compact` — 85 tests pass (170 assertions)
- `vendor/bin/pint --dirty --format agent` — All PHP files pass formatting

## 2026-03-14 — Create comprehensive op.conf for Can Eye Budget V2

### The Change

Created a full `op.conf` with 35 command aliases organized into 7 sections.

**Files created/overwritten:**
- `op.conf` — Complete OpCode configuration with Testing (6), Code Quality (7), Database (6), Development (5), Artisan Helpers (5), Assets (3), and DDEV (6) commands

### The Reasoning

- **`ddev exec` prefix on all Laravel/PHP commands**: Ensures commands run inside the DDEV container where PHP, Composer, and Node are available. Host-level DDEV commands (`start`, `stop`, `launch`) run without the prefix.
- **`op` chaining in `ci` and `clear`**: Composite commands reference other op codes so changes to individual commands propagate automatically.
- **`#?` usage comments**: Every command has a description, and `make.*` commands include usage examples so `op ?` serves as a self-contained reference.
- **Dot-separated naming**: Groups related commands visually (`test.filter`, `migrate.fresh`, `lint.dirty`) while keeping tab-completion useful.

### The Tech Debt

- None introduced.

### Verification

- `op ?` — All 7 sections render with descriptions and usage hints
- `op -l` — All 35 commands listed

## 2026-03-15 — Issue #5: Create Budget Model, Migration, Factory, and Seeder

### The Change

Built the Budget domain layer: BudgetPeriod enum, migration, model, factory, seeder, and full test coverage.

**Files created:**
- `app/Enums/BudgetPeriod.php` — 3-case string-backed enum (Monthly, Weekly, Yearly)
- `database/migrations/2026_03_14_153216_create_budgets_table.php` — budgets table with user FK (cascadeOnDelete), nullable category FK (nullOnDelete), limit_amount in cents, period, start/end dates, composite index on [user_id, period]
- `app/Models/Budget.php` — Eloquent model with enum/date/integer casts, BelongsTo user & category, HasMany transactions (linked through shared category_id), `remaining()` method
- `database/factories/BudgetFactory.php` — Default monthly budget + 6 states (withCategory, monthly, weekly, yearly, overBudget, underBudget)
- `database/seeders/BudgetSeeder.php` — 4 sample budgets for test@example.com (Groceries $800, Fuel $300, Takeaway $150, Electricity $250)
- `tests/Feature/Models/BudgetTest.php` — 19 feature tests covering factory, relationships, cascade/null-on-delete, casts, remaining() calculation, factory states
- `tests/Unit/Enums/BudgetPeriodTest.php` — 3 unit tests for case count, backing values, from() resolution

**Files modified:**
- `app/Models/User.php` — Added `budgets(): HasMany` relationship
- `app/Models/Category.php` — Added `budgets(): HasMany` relationship
- `database/seeders/DatabaseSeeder.php` — Added `BudgetSeeder::class` call after TransactionSeeder

### The Reasoning

- **transactions() via shared category_id**: `HasMany(Transaction::class, 'category_id', 'category_id')` links budgets to transactions through their shared category rather than a direct budget_id FK on transactions. Keeps the transaction table clean and naturally groups spending by category.
- **overBudget/underBudget factory states**: Use `afterCreating` callbacks to create transactions with calculated amounts, producing deterministic remaining() values for test assertions.
- **Composite index [user_id, period]**: Budget lookups will typically filter by user and period type.

### The Tech Debt

- `remaining()` does not scope by date range — needs period-aware filtering for production use.
- ~~`remaining()` sums all transactions for the category regardless of user~~ — Fixed in PR #32 review.

### Verification

- `op test.filter BudgetTest` — 19 tests pass (28 assertions)
- `op test.filter BudgetPeriodTest` — 3 tests pass (7 assertions)
- `op test` — 121 tests pass (240 assertions), full suite green
- `op lint.dirty` — All PHP files pass formatting

## 2026-03-15 — Issue #7: Eager Loading Scopes and Relationship Traversal Tests

### The Change

Added `withRelations` scopes and feature tests for relationship traversals across all models.

*(Completed in prior sessions — see commits 6289832, 1a4b832, 21e4a82)*

## 2026-03-15 — Issue #8: Implement Integer-Cents Money Accessors

### The Change

Created a reusable `MoneyCast` Eloquent cast to centralise money column handling, replacing ad-hoc `'integer'` casts on all money columns.

**Files created:**
- `app/Casts/MoneyCast.php` — Custom `CastsAttributes` implementation with `get()`/`set()` (int coercion) and `static format(int $cents): string` using pure integer arithmetic (no floats)
- `tests/Unit/Casts/MoneyCastTest.php` — 11 unit tests: cast get/set, format edge cases (zero, negative, large, padded cents), and model integration assertions

**Files modified:**
- `app/Models/Account.php` — `'balance' => MoneyCast::class`
- `app/Models/Transaction.php` — `'amount' => MoneyCast::class`
- `app/Models/Budget.php` — `'limit_amount' => MoneyCast::class`

### The Reasoning

- **Behaviour-preserving refactor**: `MoneyCast::get()` and `set()` do `(int) $value` — identical to the built-in `'integer'` cast — so all existing code and tests continue working without changes.
- **`format()` uses pure integer arithmetic**: `intdiv()` + `%` avoids floating-point entirely. `number_format()` is called with an integer argument (no decimal places) so it only adds thousand separators.
- **Single source of truth**: Any future money formatting, validation, or conversion logic has one place to live rather than being scattered across Blade views or controllers.

### The Tech Debt

- None introduced. The `format()` method is available but not yet consumed by any views — that will come when UI components are built.

### Verification

- `op test.filter MoneyCastTest` — 11 tests pass (11 assertions)
- `op test.filter AccountTest` — 16 tests pass (26 assertions)
- `op test.filter TransactionTest` — 22 tests pass (36 assertions)
- `op test.filter BudgetTest` — 20 tests pass (29 assertions)
- `op test` — 150 tests pass (295 assertions), full suite green
- `op lint.dirty` — Pint fixed import ordering and style, re-verified all tests pass

## 2026-03-16 — Issue #9: Create Domain Enums

### The Change

Completed the domain enum layer: added missing `SyncStatus` enum, added `Fortnightly` case to `BudgetPeriod`, normalized `declare(strict_types=1)` across all enum files, and added corresponding tests and factory states.

**Files created:**
- `app/Enums/SyncStatus.php` — 4-case string-backed enum (Pending, InProgress, Completed, Failed) with kebab-case backing values matching `AccountClass` convention
- `tests/Unit/Enums/SyncStatusTest.php` — 3 unit tests for case count, backing values, from() resolution

**Files modified:**
- `app/Enums/AccountClass.php` — Added `declare(strict_types=1)` to match other enums
- `app/Enums/AccountStatus.php` — Added `declare(strict_types=1)` to match other enums
- `app/Enums/BudgetPeriod.php` — Added `Fortnightly` case, reordered to frequency-ascending (Weekly, Fortnightly, Monthly, Yearly)
- `database/factories/BudgetFactory.php` — Added `fortnightly()` state method
- `tests/Unit/Enums/BudgetPeriodTest.php` — Updated count to 4, added Fortnightly assertions
- `tests/Feature/Models/BudgetTest.php` — Added fortnightly factory state test

### The Reasoning

- **Naming: `TransactionDirection` not `TransactionType`**: Issue #9 spec says `TransactionType`, but the codebase already uses `TransactionDirection` — which matches the Basiq API field name and the DB column. No rename needed.
- **`SyncStatus` standalone**: No model or migration wiring yet — this enum is for Phase 2 Basiq sync integration. Created now to complete the domain enum inventory.
- **Frequency-ascending ordering**: Cases ordered Weekly → Fortnightly → Monthly → Yearly so the progression is self-documenting.
- **Kebab-case `'in-progress'`**: Matches the convention in `AccountClass` (`'credit-card'`, `'term-deposit'`).

### The Tech Debt

- `SyncStatus` awaits Phase 2 model wiring (Basiq sync tables).

### Verification

- `op test.unit` — 29 tests pass (56 assertions)
- `op test.filter BudgetTest` — 21 tests pass (30 assertions)
- `op lint.dirty` — All PHP files pass formatting

---

## 2026-03-16 — Issue #10: Set Up Pest Architecture Presets and Mutation Testing

### The Change

Added architecture enforcement tests and mutation testing capability.

**Files created:**
- `tests/Arch.php` — 6 architecture tests: `laravel` preset, `security` preset, services must be final, models cannot use `floatval()`, DTOs must be readonly, enums must be string-backed

**Files modified:**
- `phpunit.xml` — Added `Arch` test suite pointing to `tests/Arch.php` so arch tests run with the full suite
- `op.conf` — Added `test.mutate` alias (`--mutate --min=85 --covered-only`)

### The Reasoning

- **`phpunit.xml` change required**: `tests/Arch.php` sits at the test root, outside the `Unit/` and `Feature/` directories. Without registering it as its own test suite, PHPUnit (and therefore Pest) silently skips it. Adding a dedicated `Arch` suite keeps the file at the conventional location while ensuring it runs with `op test`.
- **Vacuous arch rules on empty namespaces**: `App\Services` and `App\DTOs` only contain `.gitkeep` — the arch tests pass now but will enforce conventions (final services, readonly DTOs) as soon as real classes are added.
- **`not->toUse(['floatval'])` on models**: Protects the integer-cents pattern established by `MoneyCast`. If someone accidentally calls `floatval()` in a model, the arch test catches it at test time.
- **Mutation testing via runtime flag**: No config file changes needed — `--mutate --min=85 --covered-only` is passed at runtime through the `op test.mutate` alias.

### The Tech Debt

- Mutation testing score threshold (85%) may need tuning once the first full run completes — it could be too low or too high for the current test suite.

### Verification

- `op test` — 160 tests pass (355 assertions), up from 154/307
- `op lint.dirty` — All PHP files pass formatting

## 2026-03-16 — Issue #11: Implement BasiqService with Token Caching

### The Change

Created the `BasiqService` — the first Basiq API integration piece. Handles authentication (server + client tokens) and provides a pre-configured HTTP client for downstream consumers.

**Files created:**
- `app/Services/BasiqService.php` — Final service class with `serverToken()` (cached 20min), `clientToken()` (uncached, user-scoped), and `api()` (returns authenticated `PendingRequest`)
- `tests/Feature/Services/BasiqServiceTest.php` — 10 tests covering auth requests, cache behaviour, PendingRequest config, error handling, and singleton resolution

**Files modified:**
- `config/services.php` — Added `basiq` config array (`api_key`, `base_url`)
- `.env.example` — Added `BASIQ_API_KEY=`
- `app/Providers/AppServiceProvider.php` — Registered `BasiqService` singleton binding

**Files deleted:**
- `app/Services/.gitkeep` — No longer needed now that a real service class exists

### The Reasoning

- **Constructor injection over config facade**: `BasiqService` receives `$apiKey` and `$baseUrl` as constructor params via the container binding. This makes the class fully testable without touching config, and follows Laravel's DI best practices.
- **1200s (20min) cache TTL**: Basiq server tokens expire after 60 minutes. The 20-minute TTL gives a safe 3x margin while avoiding redundant HTTP calls per request cycle.
- **Client tokens NOT cached**: They're user-specific and short-lived — caching would require per-user cache keys and invalidation logic that isn't justified at this stage.
- **`->throw()` on all HTTP calls**: Converts 4xx/5xx responses into `RequestException` for fail-fast behaviour. Callers handle errors at their level.
- **`api()` is public**: Downstream consumers like `SyncTransactionsJob` (Issue #12+) need `$basiq->api()->get(...)`.

### The Tech Debt

- None introduced. The service is ready for consumption by Issue #12+ (Basiq user creation, sync jobs).

### Verification

- `op test.filter BasiqServiceTest` — 10 tests pass (19 assertions)
- `op test.filter Arch` — 7 tests pass (52 assertions), architecture constraints hold
- `op test` — 170 tests pass (374 assertions), full suite green
- `op lint.dirty` — All PHP files pass formatting
- Pre-existing CI issues: `pint --test` reports 46 files with style issues (all predating this change), PHPStan has 1 pre-existing error in `Logout.php`

## 2026-03-19 — Issue #12: Build BasiqService Data Retrieval Methods

### The Change

Extended `BasiqService` with four data retrieval methods and created four DTOs to map Basiq API JSON responses into typed PHP objects.

**Files created:**
- `app/DTOs/BasiqUser.php` — `final` DTO (extends Dto) for user creation responses (`id`, `email`, `?mobile`)
- `app/DTOs/BasiqAccount.php` — `final` DTO (extends Dto) for account data with nested `class.type` extraction
- `app/DTOs/BasiqTransaction.php` — `final` DTO (extends Dto) with nested `enrich` field destructuring (`merchant`, `anzsic`, full `enrichData`)
- `app/DTOs/BasiqJob.php` — `final` DTO (extends Dto) with `resolveStatus()` deriving status from step array (failed > pending > success)
- `tests/Feature/DTOs/BasiqUserTest.php` — 2 tests: full mapping, missing optional mobile
- `tests/Feature/DTOs/BasiqAccountTest.php` — 3 tests: nested class.type, top-level type fallback, all optionals null
- `tests/Feature/DTOs/BasiqTransactionTest.php` — 3 tests: full enrich, missing enrich, partial enrich
- `tests/Feature/DTOs/BasiqJobTest.php` — 4 tests: success/failed/pending resolution, step result preservation

**Files modified:**
- `app/Services/BasiqService.php` — Added `createUser()`, `getAccounts()`, `paginateTransactions()`, `getJob()` methods
- `tests/Feature/Services/BasiqServiceTest.php` — Added 11 feature tests covering all 4 methods including pagination, filter params, and error handling

**Files deleted:**
- `app/DTOs/.gitkeep` — Replaced by real DTO classes

### The Reasoning

- **DTOs use raw strings, not enums**: Type/status/direction fields stay as strings at the transport layer. Enum casting happens at the persistence layer (models) — this keeps DTOs as pure data containers decoupled from domain logic.
- **`balance` as string**: Basiq returns balance as a decimal string. Cents conversion belongs in the model layer where `MoneyCast` handles it, not in the DTO.
- **`paginateTransactions` uses `LazyCollection::make()` + Generator**: Cursor-based pagination via `links.next` yields one transaction at a time. Memory stays at O(page_size) regardless of total transaction count — critical for users with years of bank history.
- **`$query` cleared after first request**: Subsequent `links.next` URLs include their own query parameters, so re-sending the filter would duplicate/conflict.
- **`resolveStatus()` is private static**: Job status isn't a direct API field — it's derived from step statuses with clear priority: any `failed` → failed, any non-`success` → pending, all `success` → success.

### The Tech Debt

- None introduced. All DTOs are sealed (`final`, extend `Dto`), service methods follow the existing `api()->throw()` pattern.

### Verification

- `op lint.dirty` — Pint applied `final_class` to all 4 DTOs (project convention)
- `op test` — 195 tests pass (457 assertions), full suite green
- All arch constraints hold (DTOs extend Dto and are final, services final)
