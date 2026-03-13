# Roadmap

## Phase 1 — Foundation (Week 1-2)

- Laravel 12 scaffold with Livewire starter kit
- Core Eloquent models: User, Account, Transaction, Category, Budget
- Integer-cents money storage pattern
- Pest testing with architecture presets and mutation testing

## Phase 2 — Basiq Integration (Week 3-4)

- BasiqService with token caching (SERVER_ACCESS / CLIENT_ACCESS)
- Consent redirect flow (Livewire component -> redirect -> callback -> job polling)
- SyncTransactionsJob with pagination and upsert logic
- Webhook registration for connection.created and transaction.created

## Phase 3 — Dashboard (Week 5-6)

- Account overview component
- Spending by category (ApexCharts pie/donut)
- Spending over time (ApexCharts area chart)
- Transaction list with filters and pagination
- wire:ignore + Alpine.js bridge pattern for charts

## Phase 4 — Production Deployment (Week 7)

- Switch to PostgreSQL
- Full Pest suite against PostgreSQL in CI
- Deploy on Dokploy with Docker Compose
- SSL, environment variables, auto-deploy
- Scheduled Basiq connection refreshes

## Post-Launch

To be determined.
