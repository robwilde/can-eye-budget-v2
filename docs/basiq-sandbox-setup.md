# Basiq Sandbox Setup

## Getting Started

1. Sign up at [dashboard.basiq.io](https://dashboard.basiq.io) for a free sandbox account
2. Navigate to **API Keys** in the dashboard and generate a new key
3. Copy your API key — you'll need it for your `.env` file

## Environment Configuration

Add these to your `.env` file:

```dotenv
BASIQ_API_KEY=your-sandbox-api-key-here
BASIQ_BASE_URL=https://au-api.basiq.io
```

The sandbox and production APIs use the same base URL. Your API key determines which environment you're operating in.

## Sandbox Test Credentials

Basiq provides a sandbox institution called **"Hooli Bank"** for testing:

| Field         | Value                |
|---------------|----------------------|
| Institution   | Hooli Bank (AU00000) |
| Login ID      | `gavinbelson`        |
| Password      | `hooli2016`          |
| Security code | `hooli2016`          |

These credentials simulate a successful bank connection with pre-seeded accounts and transactions.

## Consent UI Configuration

The consent UI is configured entirely in the **Basiq Dashboard** under **Customise UI**. Several fields are required but have **no validation warnings** — the
consent flow will silently fail if they are missing.

### Required Settings

1. Log in to [dashboard.basiq.io](https://dashboard.basiq.io)
2. Navigate to **Customise UI**

**Flow tab:**

- Set the **Redirect URL** to: `{APP_URL}/basiq/callback`
- For local DDEV: `https://can-eye-budget-v2.ddev.site/basiq/callback`

**Data tab:**

- Set **Data retrieval span days** (e.g., `365`). This field has no default and no warning if left empty — the consent flow will fail silently without it.

**Purposes section:**

- At least one purpose must be added via the **+** button. This is also required with no indicator.

> **Known issue:** The **+** button in the Purposes section does not work in Firefox. Use Chrome or another Chromium-based browser to configure this section.

> **Note:** The Redirect URL must be updated whenever your APP_URL changes (e.g., switching between local development, staging, and production environments).

## Webhook Setup

To enable real-time notifications from Basiq (e.g., when new transactions arrive):

1. Register a webhook:

```bash
op artisan basiq:register-webhook
```

2. The command outputs a `BASIQ_WEBHOOK_SECRET` value
3. Add it to your `.env` file:

```dotenv
BASIQ_WEBHOOK_SECRET=whsec_xxxxx
```

4. Restart Horizon to pick up the new environment variable

Without the webhook secret configured, the webhook endpoint will return 500 errors for all incoming requests.

## Seeding from Basiq Sandbox

To seed your local database with real Basiq sandbox data:

1. Connect a bank account through the consent flow first to get a Basiq user ID
2. Find the Basiq user ID in the `users` table (`basiq_user_id` column)
3. Run the seed command:
   ```bash
   op artisan app:seed-from-basiq {basiq_user_id} --sync
   ```

Alternatively, set `BASIQ_SEED_USER_ID` in your `.env` to avoid passing the ID each time.

## Sandbox Behaviour

- Connections made with sandbox credentials return realistic account and transaction data
- Webhooks fire normally in sandbox mode (use `basiq:register-webhook` to register)
- Token expiry and rate limits still apply
- No real bank data is accessed

## Testing Without Sandbox

All tests in this repository use `Http::fake()` to mock Basiq API responses. You do **not** need sandbox credentials to run the test suite:

```bash
op test.filter Basiq
```

The sandbox is only needed for manual integration testing or verifying the consent flow in a browser.
