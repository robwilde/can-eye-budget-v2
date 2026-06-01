# Recurring Transaction Detection — Improvement Plan

> Implement incrementally, test-first: write the failing test for each task, make it pass, then commit before moving on.

**Goal:** Make import-time recurring-transaction detection actually find the recurring bills in a statement by matching on the *stable payee text* (ignoring per-transaction reference codes), tolerating small amount drift / step-changes, and accepting a ±2-day cadence — without over-merging unrelated payees.

**Architecture:** Extract description→payee normalisation into a dedicated, unit-tested `MerchantSignature` support class; switch the existing `IdentifyRecurringTransactionsStage` from exact-cleaned-string grouping to signature grouping; replace the brittle "every amount within ±5%" gate with dominant-amount clustering that survives outliers and premium step-changes; gap-fill the interval→frequency mapping and add a day-of-month monthly detector; add a noise filter for round-ups/internal transfers. No schema changes — same `AnalysisSuggestion` payload shape.

**Tech Stack:** PHP 8.4, Laravel 12, Pest 4. Runs inside DDEV (`op test*`). No new packages.

---

## Background — why it misses so much (measured on the real Beyond Bank CSV)

The detector is a single pipeline stage: `app/Services/PipelineStages/IdentifyRecurringTransactionsStage.php`. It:
1. groups unmatched txns by `normalizeDescription | direction | account_id` (an **exact** string key),
2. rejects a group unless **every** amount is within ±5% of the median (`AMOUNT_TOLERANCE = 0.05`, `->every(...)`),
3. maps the **median interval** to hard frequency ranges with **gaps** (10–11, 17–18, 24–26, 36–79 days are unmatchable),
4. gates on a confidence ≥ 0.40 that is heavily penalised by interval variance.

`normalizeDescription` → `cleanRawDescription` only strips a trailing 4+ digit run (`/\s+\d{4,}.*$/`), a trailing date, and a trailing 2-letter token, then dedupes repeated words. That regex truncates at the **first** long digit run, which is usually *mid-string*, and never touches `Ref#…`, `NET#…`, `MOBILE#…`, `DT.xxxx`, `bcx:int…`, or policy codes.

Measured against `context/Optimus-2026-01-to-05.csv` (291 rows, Jan–May 2026):

| | Current normaliser | Signature-based (proposed) |
|---|---|---|
| Distinct debit keys | 97 | 55 |
| **Suggestions actually emitted** | **6** (2 are the *same* rent split by a `NET#`+amount change; 2 are noise Twitch pairs) → ~3 useful | **13 fixed-amount bills** + 10 variable-cadence series |

Two concrete failure modes, both observed:

- **Over-truncation / false merge.** `cleanRawDescription("Osko Payment To 86400 Account 10386227 YOU - UBank Ref#…")` → `"OSKO PAYMENT TO"`, collapsing **17** unrelated payees (amounts $50–$300+) into one key that the amount gate then rejects. `"DIRECT DEBIT"` alone swallows 7 unrelated debits.
- **Under-grouping (the bugs you reported).** A varying code splits one payee into singletons that never group:
  - `Direct Debit Fair Go Finance - DT.4y16g4 FGF 2472` vs `… DT.4yx8ph FGF 2472` → two keys → **missed** (it's a $85 **weekly**, 21 occurrences).
  - `Direct Debit QBE Insurance … bcx:int<varies>` → **missed** ($60.58 monthly, 5 occurrences).
  - `Direct Debit NIB - 64699390` groups, but the premium **stepped $90.59 → $95.81** mid-series, so the `every ≤5%` gate rejects it.
  - `Direct Debit Golden Insurance - PLCY 082212484-015` groups, but a **double-debit** ($70.17 → $140.34) trips `every`.

Recurring bills that **should** surface (signature grouping + amount cluster + ±2-day cadence):

```
$85.00   weekly       Direct Debit Fair Go Finance        (21x)
$770.00  weekly       Ext Tfr → Sekisui House (rent)      (16x)
$750.00  weekly       Ext Tfr → Real Living (prior rent)  (6x)
$200.00  weekly       Direct Debit Spaceship              (16x)
$173.90  fortnightly  Direct Debit MCF                    (10x)
$90.59→$95.81 fortnightly Direct Debit NIB (step-change)  (11x)
$70.17   fortnightly  Direct Debit Golden Insurance       (9x, +1 double)
$28.99   monthly      Netflix                             (5x)
$47.20   monthly      Direct Debit TMR-Product Payt       (5x)
$60.58   monthly      Direct Debit QBE Insurance          (5x)
```

(Round-ups, internal "Transfer Optimus to CC to SAV", and YOU-UBank transfers are **variable-amount** and should NOT be offered as fixed recurring bills — see Task 6.)

---

## Design

### 1. `MerchantSignature` (new support class) — the heart of the fix
Pure function: raw bank description → a stable, uppercase **payee signature** used as the grouping key. Steps, in order:
1. If a Basiq `merchant_name` exists, use it (uppercased, trimmed) — already clean.
2. Otherwise normalise the raw description:
   - uppercase; collapse runs of whitespace to one space.
   - **Remove reference tokens anywhere in the string** via a token pass (not a greedy `.*$`): drop any *word* that
     - matches a known prefix: `REF#…`, `NET#…`, `MOBILE#…`, `BPAY#…`, `DT.…`, `BCX:…`, `PLCY…`, `ACCOUNT` followed by digits;
     - contains **any digit** (covers `64699390`, `082212484-015`, `DT.4Y16G4`, `10386227`, `724493`, `#2892`, `2472`, `050126`);
     - is a card mask `#\d{3,4}` or a `dd/mm`/`ddmmyy` date.
   - Keep alpha words (letters, plus internal `.&'/-`), e.g. `NETFLIX.COM`, `APPLE.COM/BILL`, `TMR-PRODUCT`.
   - Re-join; trim stray separators (`-`, `:`). Do **not** word-dedup (that mangles "MCF MCF"→"MCF" which is fine, but "to … to …" dedup distorts — drop the dedup step entirely).
3. Return the signature string. Empty signature (all tokens were codes) ⇒ treat as un-groupable (return raw uppercased, so it can only match an identical raw).

Required behaviours (these become the unit tests — use the real examples):

| Raw description | Signature |
|---|---|
| `Direct Debit NIB - 64699390` | `DIRECT DEBIT NIB` |
| `Direct Debit QBE Insurance - bcx:int 4821` | `DIRECT DEBIT QBE INSURANCE` |
| `Direct Debit Fair Go Finance - DT.4y16g4 FGF 2472` | `DIRECT DEBIT FAIR GO FINANCE FGF` |
| `Direct Debit Fair Go Finance - DT.4yx8ph FGF 2472` | `DIRECT DEBIT FAIR GO FINANCE FGF` (same as above) |
| `Osko Payment To 86400 Account 10386227 YOU - UBank Ref#885214478` | `OSKO PAYMENT TO YOU UBANK` |
| `Osko Payment To Nikolai Taylor Account 188722557 ANZ - Indooroo Ref#885214478` | `OSKO PAYMENT TO NIKOLAI TAYLOR ANZ INDOOROO` (must **not** equal the YOU-UBank one) |
| `VISA -Netflix.com   Melbourne    AU  724493 #2892` | `VISA NETFLIX.COM MELBOURNE AU` |

> Invariant the tests must lock: the two Fair Go variants collapse to **one** signature; the two different Osko payees stay **distinct**.

### 2. Amount: dominant cluster, not `every`
Replace `checkAmountConsistency(... ->every ...)` with cluster logic:
- Tolerance per amount = `max(50 cents, 2% of amount)` (your "±a few cents", widened proportionally for big amounts).
- Find the **dominant amount cluster** (the amount whose tolerance window contains the most occurrences). The "recurring amount" = median of that cluster.
- Accept the group if the dominant cluster covers **≥ 60%** of occurrences (survives a one-off double like Golden Insurance, and outliers).
- **Step-change handling:** if the *non-dominant* occurrences form a second cluster that is *contiguous in time after* the dominant one (e.g. NIB $90.59 then $95.81), use the **latest** stable amount for the suggestion and still accept. (Implement as: sort by date, take the amount of the most-recent cluster as the suggested amount.)

### 3. Cadence: gap-free frequency + day-of-month monthly
- Compute intervals between dominant-cluster occurrences (sorted by date). Use the **median** interval.
- Map to the nearest canonical frequency with a **±3-day tolerance and no gaps** (snap to the closest of: 7, 14, 21, 28–31, 91, 182, 365). Monthly is special: also accept when occurrences share a **day-of-month ±2** even if raw intervals wobble 28–31.
- A single missed/extra occurrence must not break detection (median is robust; don't require `every` interval).
- Keep requiring **≥ 3** occurrences for a confident suggestion (raise from 2 — 2 points is too weak a cadence signal and inflates false positives now that grouping is looser). Document this tradeoff.

### 4. Recall tuning
- Recompute confidence from: occurrence count, dominant-cluster share, and cadence regularity. Lower `MIN_CONFIDENCE` to **0.35** and stop hard-penalising minor interval variance (the day-of-month path covers monthly wobble). You'd rather over-offer and let the user **Dismiss** (Dismiss already persists a 90-day rejection via `hasRecentRejection`).

### 5. Noise filter (avoid offering non-bills)
Skip a group from *fixed-bill* suggestions when its signature is internal-transfer / round-up noise:
- signature starts with `ROUND UP TRANSFER`, or
- signature matches an internal own-account transfer pattern (`TRANSFER … TO … SAV`, `TRANSFER … TO CC`), or
- the dominant cluster covers < 60% (genuinely variable amount — e.g. YOU-UBank transfers).
Record skips as `PipelineAuditEntry` with a reason (mirrors existing `createSkipAudit`).

### 6. Shared normaliser (consistency)
`PlannedTransactionMatcher` (the match-at-import tagging) and this detector must agree on payee identity. After `MerchantSignature` exists, route both through it so a detected/accepted plan re-matches its own future imports. (Separate task; behind the same class.)

---

## Tasks

### Task 1: `MerchantSignature` support class (unit-tested)

**Files:**
- Create: `app/Support/Recurring/MerchantSignature.php`
- Create test: `tests/Unit/Support/Recurring/MerchantSignatureTest.php`

**Step 1 — Write failing tests** (one `it()` per row of the Design §1 table, plus the two invariants):
```php
use App\Support\Recurring\MerchantSignature;

it('strips trailing reference numbers', fn () => expect(MerchantSignature::for('Direct Debit NIB - 64699390'))->toBe('DIRECT DEBIT NIB'));
it('strips varying bcx:int codes', fn () => expect(MerchantSignature::for('Direct Debit QBE Insurance - bcx:int 4821'))->toBe('DIRECT DEBIT QBE INSURANCE'));
it('collapses Fair Go DT.xxxx variants to one signature', function () {
    expect(MerchantSignature::for('Direct Debit Fair Go Finance - DT.4y16g4 FGF 2472'))
        ->toBe(MerchantSignature::for('Direct Debit Fair Go Finance - DT.4yx8ph FGF 2472'));
});
it('keeps distinct Osko payees distinct', function () {
    $a = MerchantSignature::for('Osko Payment To 86400 Account 10386227 YOU - UBank Ref#885214478');
    $b = MerchantSignature::for('Osko Payment To Nikolai Taylor Account 188722557 ANZ - Indooroo Ref#885214478');
    expect($a)->not->toBe($b);
});
it('keeps merchant domains', fn () => expect(MerchantSignature::for('VISA -Netflix.com   Melbourne    AU  724493 #2892'))->toBe('VISA NETFLIX.COM MELBOURNE AU'));
```

**Step 2 — Run, expect fail:** `op test.filter MerchantSignatureTest` → class not found.

**Step 3 — Implement** `final class MerchantSignature { public static function for(string $raw): string }` per Design §1 (token pass: uppercase, split on whitespace, drop tokens with a digit / known ref prefixes / card mask / date, keep alpha-ish words, rejoin, trim separators).

**Step 4 — Run, expect pass.** Iterate the regex/token rules until all green.

**Step 5 — Commit:** `feat(#<issue>): add MerchantSignature payee normaliser`

---

### Task 2: Group the stage by signature

**Files:** Modify `app/Services/PipelineStages/IdentifyRecurringTransactionsStage.php` (`normalizeDescription`, `cleanRawDescription`, grouping in `execute`); test `tests/Feature/Services/PipelineStages/IdentifyRecurringTransactionsStageTest.php`.

**Step 1 — Failing test:** three CSV-source (`TransactionSource::Csv`) txns with descriptions `Direct Debit Fair Go Finance - DT.aaa FGF`, `… DT.bbb FGF`, `… DT.ccc FGF`, weekly, amount 8500 ⇒ expect **1** suggestion with `description = 'DIRECT DEBIT FAIR GO FINANCE FGF'`. (Currently produces 0.)

**Step 2 — Run, expect fail** (0 suggestions).

**Step 3 — Implement:** replace `cleanRawDescription` usage so `normalizeDescription` returns `MerchantSignature::for($transaction->description)` for the raw path; keep the merchant_name/clean_description preference but pass them through `MerchantSignature::for` too so all stages agree. Delete the old `cleanRawDescription` regex + word-dedup.

**Step 4 — Run, expect pass.** Re-run the **whole** existing stage test file — fix any expectations that change (e.g. descriptions no longer carry dangling `-`/`PLCY`).

**Step 5 — Commit:** `fix(#<issue>): group recurring detection by payee signature`

---

### Task 3: Dominant-amount clustering (replace `every`, handle step-change)

**Files:** Modify the stage (`analyzeGroup`, `checkAmountConsistency`, `calculateMedian` reuse); same test file.

**Step 1 — Failing tests:**
- *Premium step-change:* 6 fortnightly txns, four at 9059 then two at 9581 ⇒ expect 1 suggestion, `amount = 9581` (latest stable). (Currently 0 — `every` fails.)
- *One-off double:* 5 fortnightly at 7017 + one at 14034 ⇒ expect 1 suggestion, `amount = 7017`. (Currently 0.)
- *Genuinely variable:* amounts 5000/15000/6000/25000 ⇒ expect **0** (dominant share < 60%).

**Step 2 — Run, expect fail.**

**Step 3 — Implement** `dominantCluster()` per Design §2; replace the `every` gate; pick suggested amount = latest-cluster median.

**Step 4 — Run, expect pass.**

**Step 5 — Commit:** `fix(#<issue>): cluster recurring amounts and tolerate step-changes`

---

### Task 4: Gap-free frequency + day-of-month monthly

**Files:** Modify the stage (`FREQUENCY_RANGES`, `mapIntervalToFrequency`, add `isMonthlyByDayOfMonth`); same test file.

**Step 1 — Failing tests:**
- intervals with median 24 days (currently in a gap) ⇒ snaps to monthly. *(decide: 24→ either 3-weekly(21) or monthly(28); snap to nearest → monthly is 28, 21 is 21; 24 is closer to 21 → Every3Weeks. Encode the nearest-canonical rule explicitly and assert it.)*
- monthly bills on the 15th with intervals 28/31/30 ⇒ `EveryMonth` via day-of-month path.

**Step 2 — Run, expect fail.**

**Step 3 — Implement** nearest-canonical snap (candidates 7/14/21/30/91/182/365, accept if |median−candidate| ≤ 3, monthly window 26–35) + `isMonthlyByDayOfMonth` (all dominant occurrences share day-of-month ±2).

**Step 4 — Run, expect pass.**

**Step 5 — Commit:** `fix(#<issue>): gap-free cadence mapping with day-of-month monthly`

---

### Task 5: Recall tuning + noise filter

**Files:** Modify the stage (`MIN_CONFIDENCE`, `calculateConfidence`, add noise skip in `shouldSkip`); same test file.

**Step 1 — Failing tests:**
- A `ROUND UP TRANSFER TO …` weekly group ⇒ **0** suggestions (+ audit entry reason `noise_round_up`).
- A `TRANSFER OPTIMUS TO CC TO SAV` variable group ⇒ **0**.
- A clean 3× monthly bill that currently scores ~0.38 ⇒ now emitted (conf ≥ 0.35).

**Step 2 — Run, expect fail.**

**Step 3 — Implement** the noise predicate + lowered/rebalanced confidence; require ≥3 occurrences.

**Step 4 — Run, expect pass.**

**Step 5 — Commit:** `feat(#<issue>): filter transfer/round-up noise and widen recall`

---

### Task 6: Share the signature with `PlannedTransactionMatcher` (consistency)

**Files:** Modify `app/Services/PlannedTransactionMatcher.php` (+ its test) to key payee identity off `MerchantSignature::for(...)`.

**Step 1 — Failing test:** an accepted plan from `Fair Go DT.aaa` re-matches a later import `Fair Go DT.zzz` (same amount/cadence window).

**Steps 2–5:** implement, verify, commit `refactor(#<issue>): match planned txns by shared MerchantSignature`.

---

### Task 7: End-to-end fixture regression (synthetic, mirrors the real CSV)

**Files:**
- Create: `tests/Fixtures/StatementCsv-Recurring.csv` — **synthetic** rows (no real PII) reproducing the patterns: Fair Go weekly with varying `DT.xxxx`; QBE monthly with varying `bcx:int`; NIB fortnightly with a mid-series step-change; a one-off double; a round-up series; an internal transfer series.
- Create: `tests/Feature/Services/RecurringDetectionEndToEndTest.php` — import via the CSV job (mirror `ImportCsvTransactionsJobTest`), run the pipeline, assert the **set** of emitted recurring signatures equals the expected bills and **excludes** the noise series.

**Step 1 — Failing test** (assert ≥ the expected count of distinct bill signatures). **Steps 2–4:** make green. **Step 5 — Commit:** `test(#<issue>): end-to-end recurring detection on representative fixture`.

---

## Acceptance criteria

- On the synthetic fixture, every fixed-amount bill pattern (Fair Go, QBE, NIB-with-step, Netflix-style monthly, fortnightly loan) is surfaced as exactly **one** suggestion with the correct frequency and latest stable amount; round-ups and internal transfers are **not** surfaced.
- The two Fair Go `DT.xxxx` variants and the QBE `bcx:int` variants each collapse to a single signature; distinct Osko payees stay distinct (unit-locked in Task 1).
- `op test.filter MerchantSignatureTest`, `op test.filter IdentifyRecurringTransactionsStageTest`, and the end-to-end test all pass; full `op test` stays green; `op check.dirty` clean.
- Manual check against the real `context/Optimus-2026-01-to-05.csv` (local only): the Connect Bank suggestions list grows from ~3 to the ~10 real bills enumerated in Background.

## Risks / notes
- **DDEV:** all tests via `op test*` (never bare `php artisan`); no `run_in_background`.
- **PHPStan** excludes `tests/`, but `app/Support/Recurring/MerchantSignature.php` and the stage are in scope — run `op check.dirty`.
- **Over-merge risk:** the only real danger of signature grouping is collapsing two payees whose alpha words coincide. The Osko invariant test guards this; if it bites in the wild, add a secondary key on the first distinguishing alpha token.
- **Issue-first:** create the GitHub issue, branch `fix/<n>-recurring-detection`, reuse `#<n>` in every commit scope, PR → `develop`, label `bug`+`backend`, request Copilot.
- **After CSS-free PHP changes** no `op build` is needed; this is all backend.
```
