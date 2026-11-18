# Sira AI — API

Laravel API for [Sira AI](https://github.com/eyramamusu/sira-ai-productivity), the productivity and habit tracking app for Android and iOS.

| Part     | Stack                                                       | Folder                          |
| -------- | ----------------------------------------------------------- | ------------------------------- |
| API      | Laravel 13, Sanctum tokens, MySQL 8 / MariaDB 10.4+         | `app/`, `routes/`               |
| Billing  | RevenueCat (Google Play Billing + StoreKit), verified here  | `app/Billing`                   |
| AI coach | Claude via the official Anthropic PHP SDK, server-side only | `app/Ai`                        |
| Push     | FCM (Android) and APNs (iOS), plus an in-app inbox          | `app/Push`, `app/Notifications` |

## Quick start (local)

Requirements: PHP 8.3+, Composer, MySQL 8 or MariaDB (SQLite also works for local dev).

```bash
composer install && cp .env.example .env && php artisan key:generate
```

Edit `.env`. For a quick local setup use `DB_CONNECTION=sqlite`, `SIRA_BILLING_DRIVER=fake` and `SIRA_AI_DRIVER=fake`; both fake drivers refuse to run outside `local`/`testing`. Then:

```bash
php artisan migrate --seed
```

```bash
php artisan serve --port=8010
```

The seeded demo account is `demo@sira.test` / `sira-demo-1` (local only).

The mobile app expects this repository to be checked out next to it, as `../sira-ai-productivity-be`, so its `npm run api:serve`, `npm run api:test` and Playwright end-to-end tests can start the API.

## Tests

```bash
php artisan test
```

# Command to use app on phone API server

cd public && APP_URL=http://192.168.100.3:8011 QUEUE_CONNECTION=sync SIRA_AVATAR_STORAGE=database php -S 0.0.0.0:8011 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php

Tests run on SQLite by default. To run them on MySQL, set `DB_CONNECTION=mysql` and the `DB_*` variables in the environment. CI does this with a MySQL 8 service.

## Project layout

```
app/        Models, policies, controllers (Api/*), Billing/*, Ai/*, Push/*, Notifications/*, Services/*
routes/     api.php (versioned under /api/v1), web.php (password reset page), console.php (schedule)
tests/      Feature tests
docs/       API reference, deployment & configuration
```

## Documentation

- [API reference](docs/API.md)
- [Deployment & configuration](docs/DEPLOYMENT.md)

## What needs credentials before launch

| Feature                                                     | Needs                                                                         | `.env`                   |
| ----------------------------------------------------------- | ----------------------------------------------------------------------------- | ------------------------ |
| AI coach                                                    | Anthropic API key                                                             | `ANTHROPIC_API_KEY`      |
| Purchases                                                   | RevenueCat secret API key, webhook auth                                       | see `docs/DEPLOYMENT.md` |
| Direct store verification (optional, instead of RevenueCat) | Google Play service account JSON; App Store Connect In-App Purchase key (.p8) | see `docs/DEPLOYMENT.md` |
| Password reset email                                        | SMTP / mail provider                                                          | `MAIL_*`                 |
| Server push                                                 | Firebase service account; APNs key (.p8)                                      | `FCM_*`, `APNS_*`        |

Without these, the API still runs: alerts collect in the in-app inbox, premium features return a paywall, and the coach returns HTTP 503 `ai_not_configured`.
