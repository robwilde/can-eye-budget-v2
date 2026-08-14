# Redbark payload capture

Captured **2026-08-14** from `https://api.redbark.com/v1` with the account holder's own key.
All four endpoints returned **HTTP 200**, so the plan is API-enabled (Developer or Professional;
the Saver plan 403s on everything).

The fixtures in `tests/Fixtures/Redbark/` are those responses, redacted and truncated as described
under [Redaction](#redaction). The account holds **3 accounts on 1 connection** (Beyond Bank
Australia, upstream provider `fiskil`).

## Commands

`REDBARK_API_KEY` is a **shell** variable for these calls only. The application never reads it —
each user's key lives encrypted in `redbark_feeds.api_key`. It is not in `.env.example` for that
reason.

```bash
export REDBARK_API_KEY='rbk_live_...'
mkdir -p tests/Fixtures/Redbark

curl -sS -D /tmp/redbark-accounts.headers \
  -H "Authorization: Bearer $REDBARK_API_KEY" -H 'Accept: application/json' \
  'https://api.redbark.com/v1/accounts?limit=200&offset=0'

curl -sS -D /tmp/redbark-connections.headers \
  -H "Authorization: Bearer $REDBARK_API_KEY" -H 'Accept: application/json' \
  'https://api.redbark.com/v1/connections'

ACCOUNT_ID=<id from accounts.json>
CONNECTION_ID=<connectionId from that same row>
FROM=$(date -d '90 days ago' +%F)
TO=$(date +%F)

curl -sS -H "Authorization: Bearer $REDBARK_API_KEY" -H 'Accept: application/json' \
  "https://api.redbark.com/v1/balances?accountIds=$ACCOUNT_ID"

curl -sS -H "Authorization: Bearer $REDBARK_API_KEY" -H 'Accept: application/json' \
  "https://api.redbark.com/v1/transactions?connectionId=$CONNECTION_ID&accountId=$ACCOUNT_ID&from=$FROM&to=$TO&limit=500&offset=0"
```

Adding `&includePending=true` produced a **byte-identical** response — see
[the pending caveat](#the-one-thing-still-unconfirmed).

## Observed wire contract

### Envelopes — they are not uniform

| Endpoint | Envelope |
|---|---|
| `/accounts` | `{"data": [...], "pagination": {"total", "limit", "offset", "hasMore"}}` |
| `/transactions` | `{"data": [...], "pagination": {"total", "limit", "offset", "hasMore"}}` |
| `/connections` | `{"data": [...]}` — **no `pagination` key at all** |
| `/balances` | `{"data": [...]}` — **no `pagination` key at all** |

`pagination` carries three keys beyond the documented `hasMore`. `RedbarkService` reads only
`pagination.hasMore` and tracks offset client-side, so the extras are ignored; the two unpaginated
endpoints are already implemented as single requests.

### Field names — the documented shapes hold, with two additions

`/accounts` rows carry exactly the 8 documented fields; `/connections` exactly 9; `/balances`
exactly 4. `/transactions` rows carry the 16 documented fields **plus `customCategory` and
`customCategoryGroup`**, which were `null` on all 685 rows inspected. They are Redbark's
user-defined categories, unpopulated on this account, so nothing maps them; the DTO ignores unknown
keys, so they cost nothing until they are wanted.

### Literals, confirmed against live data

- **`amount` is a pre-signed decimal string, always 2 dp** — 685/685 rows matched
  `^-?[0-9]+\.[0-9]{2}$`.
- **Negative ⇔ `"debit"`, positive ⇔ `"credit"`, with zero exceptions** across 634 debits and 51
  credits on three accounts. The port's decision not to invert the sign, and to derive direction
  from the sign rather than the `direction` field, is correct and the two can never disagree.
- **`connections[].category` is `"banking"`.**
- **`status` was `"posted"` on every row.**
- **`currency` is a plain string `"AUD"`** on both `/accounts` and `/balances` rows. The
  object form (`{"code": "AUD"}`) that sure-finance defends against never appeared. The
  normalisation in `RedbarkCurrency` is kept as cheap insurance, not because it is needed today.
- **No `X-Redbark-*` response header appeared on any call**, truncation or otherwise.
- `valueDate` and `valueDatetime` were `null` on every row; `date` and `postDate` were always
  present, so the `date ?? postDate` fallback never fired.

### Identifiers

- Account and connection ids are **UUIDs**.
- Transaction ids are **`bank_tx_` + 64 hex chars**, i.e. content-derived hashes. That matters: a
  pending row's hash almost certainly changes when it settles, which is exactly the reissued-id case
  `SyncRedbarkFeedJob::claimSettledPending()` exists to absorb.

### Pagination, exercised for real

The credit-card account has 538 transactions in a 90-day window — more than the 500 page size.
Fetching both pages by hand:

| | rows | `pagination` |
|---|---|---|
| `offset=0` | 500 | `{"total":538,"limit":500,"offset":0,"hasMore":true}` |
| `offset=500` | 38 | `{"total":538,"limit":500,"offset":500,"hasMore":false}` |

**538 distinct ids, zero overlap** between the pages. The client's loop — advance offset by rows
actually returned, stop on `hasMore: false` — reproduces this exactly. The first real sync of this
account will page.

## Redaction

Applied before committing:

- `accountNumber` reduced to `****` plus its last four digits.
- `data` arrays truncated to 5 rows. `transactions.json` holds 3 debits and 2 credits from the
  account with a realistic mix, so the sign mapping is covered both ways.
- `pagination.total` is left as the wire reported it, so it may exceed the row count in the fixture.
  Nothing reads it.

Merchant names and descriptions are kept — the mapping tests need realistic strings.
**Note that descriptions include payee names and bank transfer reference numbers** (for example
`Ext Tfr - NET#... to ...`). That is the account holder's own data in a private repo, but it is real
and it is now in git history.

## Pending: the documented `"pending"` status never appears

My first pass concluded that no pending rows existed, because `status` was `"posted"` on all 685
captured rows and `includePending=true` returned a byte-identical response. **That conclusion was
wrong.** The pending rows were there, mislabelled.

An uncleared card authorisation arrives like this:

```json
{
  "status": "posted",
  "description": "AUTHORISATION",
  "merchantName": "HARRIS FARM MARKETS PTY LWEST END     AU",
  "amount": "-89.15",
  "date": "2026-08-13", "postDate": "2026-08-13"
}
```

which online banking lists under *Uncleared Transactions* as
`Hold HARRIS FARM MARKETS PTY L Auth 123023 VCC 023835`. Confirmed by amount: 9 of the 10 holds
shown in online banking appear in the feed as `AUTHORISATION` rows, all `"posted"`.

So for this institution:

- **`status` is useless for pending detection** — it is always `"posted"`.
- **`includePending` is a no-op** — holds are returned either way, so `REDBARK_INCLUDE_PENDING`
  changes nothing here. The config is kept because the flag may matter for other institutions.
- **The placeholder description is the only signal.** Across 886 captured rows, `"AUTHORISATION"`
  was the only description shared by more than two distinct merchants (17 rows, 10 merchants), and
  every one of them carried a `merchantName`. `App\Support\RedbarkNarration` encodes that: it marks
  such a row pending and takes the narration from `merchantName`, since `"AUTHORISATION"` alone
  tells the user nothing.

The real narration must still win for non-holds — the CSV statement carries
`VISA -Afterpay    afterpay.com AU  145377`, and cross-source matching depends on it — so the
fallback is placeholder-only, not merchant-first as sure-finance does it (`processor.rb:117`).

When a hold clears, the bank issues a **new** id (they are content hashes) and a real narration.
`claimSettledPending()` matches it to the stored hold on amount and an 8-day window, keeping the
original date, so the hold becomes the settled row rather than its duplicate.

## Decisions this capture settled

1. **Sign is not inverted.** sure-finance negates (`processor.rb:146`) because Rails-side "positive
   = money out". This app uses the CDR convention, and the capture confirms it: `"-770.00"` is a
   debit. sure-finance's test expectations are the negation of ours and must not be copied.
2. **`direction` is derived from the sign**, with the wire value preserved under
   `enrich_data.redbark.direction`. 685/685 rows agree, so this is belt-and-braces.
3. **Description precedence** is `description ?: merchantName ?: 'Transaction'`, the reverse of
   sure-finance (`processor.rb:117`). The capture supports it: `merchantName` is `null` on rows
   whose `description` carries the full bank narration.
4. **The `banking`/`documents` asymmetry is kept** — transactions accept `banking`, `documents`,
   blank or unknown; balances accept only `banking`, blank or unknown (`importer.rb:212` vs `:330`).
5. **410 maps to `bad_request`**, so a 410 on a batched `/balances` call also triggers the
   per-account fallback.
6. **An empty transactions response leaves the stored snapshot untouched** (`importer.rb:145`).
   Without this an empty refetch would drop every stored pending row via the stale-pending rule.
7. **429 and 5xx are not retried client-side**, unlike sure-finance's `with_retries`.
   `SyncRedbarkFeedJob` already has `$tries = 5` with backoff. `Http::retry(3, 2000)` still covers
   transient connection failures only.
