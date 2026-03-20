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

| Field | Value |
|-------|-------|
| Institution | Hooli Bank (AU00000) |
| Login ID | `gavinbelson` |
| Password | `hooli2016` |
| Security code | `hooli2016` |

These credentials simulate a successful bank connection with pre-seeded accounts and transactions.

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
