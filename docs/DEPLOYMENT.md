# Deployment & configuration

## Free hosting on Render

The repo includes a `Dockerfile` and a Render Blueprint (`render.yaml`) for a zero-cost setup: Render's free web service, a free MySQL-compatible database, and a free outside cron.

All production values are listed in `.env.production.example`; on Render they are environment variables, never a committed file.

**1. Database.** Create a free **TiDB Cloud Starter** cluster (MySQL-compatible, 5 GiB, doesn't sleep; AWS Frankfurt, next to the Render service) and, in its SQL editor, `CREATE DATABASE sira_ai;`. From *Connect → Connect With: .env*, note the host and the username (it includes the cluster prefix, e.g. `xxxxxxxx.root`); the port is 4000. TLS is required: on Render the system CA bundle already trusts TiDB's certificate, so no certificate file goes into the image.

Optional, to check the connection from your computer before deploying: download the CA certificate from the same dialog to `storage/certs/isrgrootx1.pem` (git-ignored), copy `.env.production.example` to `.env.tidb` (git-ignored), fill in the database values, set `MYSQL_ATTR_SSL_CA` to the certificate's full path, and run `php artisan db:show --env=tidb`. It only reads. Keep `MYSQL_ATTR_SSL_CA` out of your normal `.env`: the local XAMPP server doesn't use TLS.

**2. Web service.** In Render: *New → Blueprint*, connect this repository, and fill in the `sync: false` values:

| Variable | Value |
| --- | --- |
| `APP_KEY` | Output of `php artisan key:generate --show`, run locally. Never reuse a development key. |
| `APP_URL` | The URL Render gives the service, e.g. `https://sira-api.onrender.com`. The app's `environment.prod.ts` must use the same host. |
| `DB_HOST`, `DB_USERNAME`, `DB_PASSWORD` | From step 1 (`DB_PORT=4000` and `DB_DATABASE=sira_ai` are preset) |
| `MAIL_HOST`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS` | SMTP login from a free mail provider (e.g. Brevo: `smtp-relay.brevo.com`, with a verified sender address). Without it, password reset emails aren't delivered. |
| `ANTHROPIC_API_KEY` | Optional. Leave empty and the coach answers *not configured*. |

`CORS_ALLOWED_ORIGINS` is preset to the native apps plus `https://sira-ai-0kb.pages.dev`; change it in `render.yaml` if the web app moves.

**Allow Render to reach TiDB.** TiDB only accepts connections from IP addresses on its allow list, and your computer's address isn't Render's. In Render, open the service's *Connect → Outbound* tab and copy its outbound IP ranges; in TiDB Cloud, add each one to the cluster's public endpoint allow list (*Networking*). Don't allow all addresses (`0.0.0.0/0`).

Every start runs migrations (`migrate --force`) and rebuilds the config cache, so changed variables apply after a redeploy or restart. Render's free tier has no shell or pre-deploy command, which is why migrations run at startup; they only ever apply new migration files. The first deploy creates all tables in the empty `sira_ai` database. Check `https://<service>.onrender.com/up` and `/api/v1/health`.

**3. Scheduler.** The free tier has no cron. Create a free job at [cron-job.org](https://cron-job.org) that calls `https://<service>.onrender.com/api/v1/cron/run` every 5 minutes with the header `X-Cron-Secret: <SIRA_CRON_SECRET>` (Render generated it; copy it from the service's *Environment* tab). This runs the weekly review, subscription checks and pruning, and keeps the free instance from sleeping (it sleeps after 15 idle minutes; the first request after that takes up to a minute). One service running all month fits in Render's 750 free hours.

How the free setup differs from a normal server:

- `QUEUE_CONNECTION=sync`: notifications are sent during the request instead of by a worker.
- `SIRA_AVATAR_STORAGE=database`: profile photos are stored in the `user_avatars` table and served from `/api/v1/avatars/{token}`, because Render's disk is wiped on every deploy and restart.
- `TRUSTED_PROXIES=*`: Render terminates HTTPS in front of the container.
- Purchases, server push and the AI coach stay off until their accounts and keys are added (see below).

## Your own server

Requirements: PHP 8.3+ with `pdo_mysql`, `openssl`, `mbstring`, `curl`; MySQL 8 or MariaDB 10.4+; a web server (Nginx/Apache) serving `public/` over **HTTPS**; cron; a mail provider.

```bash
cd /var/www/sira-api
composer install --no-dev --optimize-autoloader
cp .env.example .env    # then fill in values (see below)
php artisan key:generate
php artisan migrate --force
php artisan storage:link    # serves uploaded profile photos from /storage
php artisan config:cache && php artisan route:cache
```

Cron (runs subscription re-verification, the Monday weekly review, and notification/token pruning, and drains the notification queue every minute):

```
* * * * * cd /var/www/sira-api && php artisan schedule:run >> /dev/null 2>&1
```

Notifications are queued (`QUEUE_CONNECTION=database`). The schedule runs `queue:work --stop-when-empty` every minute, so cron alone is enough. If you run a process manager (Supervisor, systemd), run `php artisan queue:work` there instead and remove that line from `routes/console.php`.

Make `storage/` and `bootstrap/cache/` writable by the web user. Keep store keys outside the web root, for example in `storage/keys/` (already git-ignored).

## Required `.env` values

| Variable                                                                          | Purpose                                                                                                                                    |
| --------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------ |
| `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://api.example.com`        | Production mode                                                                                                                            |
| `DB_CONNECTION=mysql`, `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`     | Database (use a least-privilege user)                                                                                                      |
| `MAIL_MAILER`, `MAIL_HOST`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS` | Password reset emails                                                                                                                      |
| `SIRA_PASSWORD_RESET_URL`                                                         | Optional. Leave empty: reset emails link to the page the API hosts at `APP_URL/reset-password`, which works in any phone browser. Only set it if you host a web build of the app (its route is `/reset-password?token=…&email=…`) |
| `CORS_ALLOWED_ORIGINS`                                                            | Keep `capacitor://localhost,https://localhost` for the apps; add any web origin                                                            |
| `SANCTUM_TOKEN_EXPIRATION_MINUTES`                                                | Token lifetime (default 60 days)                                                                                                           |

## Billing

Pick a driver with `SIRA_BILLING_DRIVER`:

- `revenuecat` (matches the shipped app): set `REVENUECAT_SECRET_API_KEY`, `REVENUECAT_ENTITLEMENT` (default `premium`) and `REVENUECAT_WEBHOOK_AUTH`. In RevenueCat, point the webhook at `https://<api>/api/v1/webhooks/revenuecat` and use the same auth value. The app sets the RevenueCat app user id to `sira_<user id>`.
- `store`: verifies directly with Google and Apple. Use it if you replace RevenueCat with a direct billing plugin.
  - Google Play: create a service account with _View financial data_ in Play Console, then set `GOOGLE_PLAY_SERVICE_ACCOUNT_JSON` (file path), `GOOGLE_PLAY_PACKAGE_NAME`, and `GOOGLE_PLAY_RTDN_TOKEN`. Configure Real-time Developer Notifications with a Pub/Sub push subscription to `https://<api>/api/v1/webhooks/google-play?token=<GOOGLE_PLAY_RTDN_TOKEN>`.
  - App Store: in App Store Connect create an In-App Purchase key, then set `APPLE_IAP_ISSUER_ID`, `APPLE_IAP_KEY_ID`, `APPLE_IAP_PRIVATE_KEY_PATH` (.p8), `APPLE_BUNDLE_ID`, and `APPLE_IAP_ENVIRONMENT` (`sandbox` for TestFlight). Set the Server Notifications V2 URL to `https://<api>/api/v1/webhooks/app-store`.
- `fake`: local development and tests only. It throws when `APP_ENV` isn't `local` or `testing`.

Product ids: `SIRA_PRODUCT_MONTHLY` / `SIRA_PRODUCT_ANNUAL` (default `sira_premium_monthly` / `sira_premium_annual`). Create the same ids in both stores, and in RevenueCat as the `MONTHLY` / `ANNUAL` packages of the current offering. Prices are set in the stores.

Free-plan limits: `SIRA_FREE_MAX_HABITS`, `SIRA_FREE_MAX_GOALS` (default 3).

## AI coach

| Variable              | Default           | Notes                                                                            |
| --------------------- | ----------------- | -------------------------------------------------------------------------------- |
| `SIRA_AI_DRIVER`      | `anthropic`       | `fake` only works in local/testing                                               |
| `ANTHROPIC_API_KEY`   | —                 | **Required** for the coach; without it the coach returns 503 `ai_not_configured` |
| `SIRA_AI_MODEL`       | `claude-opus-5-5` | Any Messages API model id                                                        |
| `SIRA_AI_EFFORT`      | `medium`          | `low` is cheaper for chat-only use                                               |
| `SIRA_AI_DAILY_LIMIT` | `50`              | Requests per user per day                                                        |

The provider is behind `App\Ai\CoachProvider`. To use a different vendor, add an implementation and bind it in `AppServiceProvider`. Requests use the server-side refusal fallback (`fallbacks: "default"`), so a policy decline on the main model is retried on a fallback model instead of failing.

## Notifications

Two kinds, and the first needs no setup at all:

- **On-device reminders** (local notifications, work offline): morning plan, task reminders with a chosen lead time, habit reminders on their chosen days, evening streak check, goal deadlines, focus timer. The app plans them in `src/app/core/notification-plan.ts` and re-plans whenever data or settings change.
- **Server push** (FCM on Android, APNs on iOS): new sign-in and password-change alerts, subscription started / payment problem / renewal off / ended, and the Monday 09:00 weekly review (user's time zone). Every one is also stored in the in-app inbox (`GET /notifications`), which works without push credentials.

| Variable | Default | Notes |
| --- | --- | --- |
| `SIRA_PUSH_DRIVER` | `native` | `log` writes pushes to the log instead of sending them |
| `FCM_SERVICE_ACCOUNT_JSON` | — | Path to a Firebase service account key with the *Firebase Cloud Messaging API Admin* role (Android) |
| `APNS_TEAM_ID`, `APNS_KEY_ID`, `APNS_PRIVATE_KEY_PATH` | — | Apple Developer → Keys → a key with *Apple Push Notifications service* enabled (.p8) (iOS) |
| `APNS_ENVIRONMENT` | `production` | `sandbox` for builds run from Xcode; TestFlight and App Store builds use `production` |
| `APPLE_BUNDLE_ID` | `com.sira.productivity` | APNs topic |

A platform without credentials falls back to logging, so you can enable one platform at a time. Tokens the stores report as dead are deleted automatically, and a phone's token is removed when that session signs out.

To turn push on in the mobile app (Firebase `google-services.json`, the iOS Push Notifications capability, and `push: true` in the app environment), see `docs/DEPLOYMENT.md` in [sira-ai-productivity](https://github.com/eyramamusu/sira-ai-productivity).
