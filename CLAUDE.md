# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**Medicare** is a multi-tenant SaaS platform for managing ophthalmology clinics, built with Laravel 11 + Blade + Alpine.js.

## Common Commands

```bash
# Development
composer dev          # Start all dev services (server, queue, logs, vite) concurrently
npm run dev           # Vite dev server only
npm run build         # Production asset build

# Database
php artisan migrate
php artisan db:seed

# Code quality
./vendor/bin/pint     # Fix code style (Laravel Pint / PSR-12 + Laravel preset)
./vendor/bin/pest     # Run all tests
./vendor/bin/pest tests/Feature/SubscriptionTest.php  # Run single test file
./vendor/bin/pest --filter "test name"                # Run single test by name
```

## Architecture

### Multi-Tenancy Model

The platform uses a **session-based multi-tenancy** pattern:
- One SaaS owner entity (`ENT-0000000001`) manages all client entities
- Users belong to one or more entities via `entity_users` pivot (with roles)
- The active entity is stored in the session after entity selection
- `EnsureEntitySelected` middleware gates all `/panel/` routes

Key entity-scoped middleware stack: `auth → verified → EnsureEntitySelected → EnsureEntityRole`

### Route Groups

| Prefix | File | Purpose |
|--------|------|---------|
| `/panel/` | `web.php` | Authenticated clinic management |
| `/panel/manager/` | `manager.php` | SaaS owner admin |
| `/api/integrators/` | `api.php` | External equipment integrator API |
| `/tv/` | `web.php` | Waiting room TV display (public) |
| `/auth/` | `auth.php` | Authentication |

### Service Layer

All business logic lives in `app/Services/`. Controllers are thin — they delegate to services:

- `SubscriptionService` — Trial/active/expired subscription lifecycle
- `FeatureGateService` — Plan-based feature access (singleton, checks `subscription_settings`)
- `EntityRoleService` / `EntityUserService` — RBAC within entities
- `PatientService`, `ScheduleService` — Core clinical domain logic
- `UsageMeterService` — Track API usage quotas per billing period
- `TrialService` — Trial creation and expiry handling

### Key Enums

State management uses PHP 8.1 backed enums in `app/Enums/`:
- `SubscriptionStatus` — `Trial`, `Active`, `Cancelled`, `Expired`
- `ScheduleSituation` — `Scheduled`, `InProgress`, `Completed`, `Cancelled`
- `FeatureKey` — Feature flag identifiers for plan gating
- `EntityGate` — Permission gate identifiers
- `BillingCycle` — `Monthly`, `Yearly`, `Lifetime`
- `ExamCategory` — Exam classification types

### Code Patterns

**Auto-generated codes**: Entities, patients, and schedules get sequential human-readable codes (`ENT-0000000001`, `PAC-0000000001`, `SDL-0000000001`) via model observers.

**Presenter pattern**: `SchedulePresenter` and `PatientPresenter` (via `laracasts/presenter`) handle display formatting in views.

**Soft deletes**: `User`, `Entity`, `EntityUser`, integrations, and equipment support soft delete + restore. Controllers include `restore()` methods and routes like `/setting/{resource}/restore`.

**Field normalization**: Names and addresses are auto-uppercased; phone/registration fields strip non-numeric characters.

**API authentication**: External integrators use Sanctum tokens (`ApiAuthenticateWithIntegrator` middleware) with monthly quota limits tracked via `UsageMeterService`.

### Frontend Stack

- **Blade** templates with **Alpine.js** for reactivity
- **Bootstrap 5** + **Tailwind CSS** (both present)
- **Vite** bundles: `resources/js/vendor.js` (jQuery + libs), `resources/js/app.js` (global), plus per-module files in `resources/js/system/`
- Key JS libraries: DataTables, FlatPickr, SweetAlert2, jQuery toast, QRCode (TV pairing)

### Testing

Tests use **Pest 4** in `tests/Feature/` and `tests/Unit/`. Key test areas: subscriptions, ACL/roles, feature gates, usage metering, API integrator flows, exam limits, TV pairing.

## Environment

Default locale is `pt_BR` (Brazilian Portuguese). Default DB is SQLite. The `.env.example` documents all required variables.

Docker setup (`docker-compose.yml`): PHP 8.4 FPM + Nginx (port 8080) + Redis.
