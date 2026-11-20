# PromptEdit × Pixelforge

A Laravel 12 + Vue 3 + Inertia app that lets signed-in users generate images with
[Pixelforge](https://pixelforge.promptedit.dev/docs), an asynchronous, token-priced image API.
Users pay Pixelforge's cost plus a 25% markup from a prepaid credit balance.

- **Generate**: prompt, size, quality and up to 4 reference image URLs, with a live price range before submitting.
- **History**: live status (submitting, queued, processing, succeeded, failed), the estimated range next to the actual charge, and the image.
- **Credits**: balance, fake "Buy credits" packs and the full ledger.
- **Profit report** (admins): credit spend, provider cost and profit per UTC day and per user, checked against Pixelforge's `GET /v1/usage`.

## How money is handled

Correct money comes first, so these are the rules the code is built around.

**Exact integers.** Every amount is stored as an integer number of **nanodollars** (1 USD = 10⁹). Pixelforge reports costs as exact
decimal strings with nine decimals, so nanodollars represent them without rounding. No floats are used for money anywhere. Rates
are applied with `bcmath` (`app/Pixelforge/Rates.php`).

**Cost.** `cost = Σ tokens × rate ÷ 1,000,000`, using the `usage` Pixelforge returns on success and the `rates` frozen on the
generation when it was created. Later price changes don't affect it.

**Price.** `charge = ceil(cost × 1.25)` to the nanodollar. At Pixelforge's current rates every cost is a multiple of 500
nanodollars, so the markup is always exact and the ceiling never applies. It is a guard for future rates. Prices are displayed
rounded *up* to at most six decimals, so a displayed price is never lower than the charge.

**Estimate.** Prompt tokens (±15%) and reasoning tokens are only known after completion, so the up-front price is a range
(`app/Pixelforge/PriceEstimator.php`). It reproduces the docs' worked example exactly (max cost `0.373875000`). The form calls the
same server-side estimator, so the page and the charge cannot disagree.

**Ledger** (`app/Billing/Ledger.php`, `credit_transactions`). Balances are the sum of an append-only ledger:

| Moment | Entry |
|---|---|
| Submit | `hold` the **maximum** possible price, under a row lock on the user, so concurrent submissions can't overspend |
| Succeeded | `release` the hold, then `charge` the actual price |
| Failed / rejected | `release` the hold. Failed generations are free |

`(generation_id, type)` is unique and entries are written with `ON CONFLICT DO NOTHING`, so a generation can never be charged twice,
however many times its events arrive.

## How generations stay correct under failure

Every status or money change goes through one class: `app/Actions/ApplyGenerationState.php`. The webhook, the submit job and the
reconciler all hand it a Pixelforge generation object.

- It locks the generation row, so concurrent deliveries are handled one at a time.
- Status only moves forward (`pending → queued → processing → succeeded|failed`), and final states never change. A late
  `generation.processing` after `generation.succeeded` is ignored.
- A webhook can arrive before the `POST /v1/generations` response. Every request sends `metadata.generation_id`, so the event is
  matched to its row anyway.

**Submitting** (`app/Jobs/SubmitGeneration.php`) happens in a queued job with one `Idempotency-Key` per generation, reused on every
attempt. A retry after a timeout or lost response returns the original generation instead of creating and billing a second one.

| Pixelforge says | We do |
|---|---|
| `202` | record id, rates, status |
| `429 rate_limit_exceeded` / `concurrency_limit_exceeded`, `503 overloaded` | wait `Retry-After` (exponential backoff with jitter, capped at 60s) and retry for up to 10 minutes, then fail it **for free**. Pixelforge guarantees nothing was created |
| connection error / 5xx | retry. If still failing, leave it pending: the outcome is unknown, and the reconciler replays the same key to find out |
| `400 content_policy_violation`, `422`, `daily_limit_exceeded` | fail it immediately for free, with a readable reason |

**Trying again** after a generation failed depends on whose fault it was (`Generation::TRANSIENT_FAILURES`):

- **Outside the user's control:** `internal_error` (Pixelforge's docs say a retry usually works), `overloaded`, rate and
  concurrency limits, connection and server errors, and abandoned submissions. These get a **Try again** button that resubmits
  the same request, once per failed attempt. `daily_limit_exceeded` is offered only after the quota resets at midnight UTC.
- **Caused by the request:** `content_policy_violation`, `generation_failed` (the model couldn't draw this prompt) and
  `validation_failed`. The same request would fail again, so these get **Edit prompt** instead. It opens the Generate page with
  the same settings, which every History card also offers as **Use these settings**.

**Webhooks** (`POST /webhooks/pixelforge`):

- `VerifyPixelforgeSignature` checks `HMAC-SHA256("<t>.<raw body>")` against the key's `whsec_` secret in constant time, and
  refuses timestamps more than 300s old. The route is outside the `web` middleware group, so no CSRF or session.
- Each event is inserted into `webhook_events` (unique `event_id`) and applied **in the same transaction**. A duplicate delivery is
  acknowledged with 200 and skipped. If applying fails, the insert rolls back and Pixelforge's retry is processed normally.

**Reconciler** (`php artisan pixelforge:reconcile`, scheduled every minute):

- Webhooks are not guaranteed, so any accepted generation quiet for 3 minutes is fetched with `GET /v1/generations/{id}` and applied.
- Pending generations whose submit job stopped are resubmitted with the same idempotency key. After an hour they are closed as
  failed, for free.
- It makes at most 20 API calls per run, to respect the 60/min account limit.

**Images** are copied to our own storage (`DownloadGenerationImage`), because Pixelforge's signed URLs expire 24h after completion.
If the copy never happened, history shows "Image link expired".

## Profit report and reconciliation

`app/Reports/ProfitReport.php` groups succeeded generations by the **UTC date of `completed_at`**, because Pixelforge bills a
generation on the day it completed, not the day it was created. Each day's provider cost is compared with `cost_usd` from
`GET /v1/usage` for this app's API key and marked ✓ or ✗. The Postgres session is pinned to UTC (`config/database.php`) so
`timestamptz` values can't shift across day boundaries.

## Decisions worth explaining

- **Hold the maximum, charge the actual.** Users are never charged more than the upper end of the range they were shown. Holding
  the maximum is what lets "block generation when the user can't afford it" stay true while a generation is in flight.
- **Sub-cent amounts are kept exact** rather than rounded to cents. Credits are dollars; the UI shows up to six decimals.
- **Process webhooks inline.** Applying an event is a few indexed writes in one transaction, well within Pixelforge's 10s budget.
  Slow work (downloading images) is queued.
- **One API key per environment.** Local and deployed apps each get their own key, webhook URL and secret, so `/v1/usage` for the
  deployed key matches the deployed app's report exactly.

## Running locally

Requirements: PHP 8.4+ with `bcmath` and `pdo_pgsql`, PostgreSQL, Node 20+.

```bash
composer install && npm install && npm run build
cp .env.example .env && php artisan key:generate
# set DB_* and PIXELFORGE_API_KEY / PIXELFORGE_WEBHOOK_SECRET in .env
php artisan migrate --seed     # creates admin@example.com / password (ADMIN_EMAIL / ADMIN_PASSWORD) with $10 of credit
php artisan storage:link
php artisan queue:work         # submissions and image downloads are queued
php artisan schedule:work      # runs pixelforge:reconcile every minute
```

Webhooks need a public URL. Use a tunnel such as `herd share`, Expose, ngrok or Cloudflare Tunnel, set
`https://<tunnel>/webhooks/pixelforge` as the key's webhook URL in the Pixelforge dashboard, and copy its signing secret into
`PIXELFORGE_WEBHOOK_SECRET`.

### Configuration

| Variable | Purpose |
|---|---|
| `PIXELFORGE_API_KEY` | API key for this environment |
| `PIXELFORGE_WEBHOOK_SECRET` | that key's `whsec_` signing secret |
| `PIXELFORGE_BASE_URL` | defaults to `https://pixelforge.promptedit.dev/v1` |
| `PIXELFORGE_IMAGE_DISK` | filesystem disk for copied images (`public` locally, an S3/R2 bucket in production) |
| `ADMIN_EMAIL`, `ADMIN_PASSWORD` | seeded operator account |

### Deploying (Laravel Cloud)

Servers on Laravel Cloud don't share a disk: the queue worker that copies an image and the web server that serves it are
separate machines, and their local files are lost on each deploy. So images must go to a bucket:

1. Create an object storage bucket with **public** visibility and attach it to the environment. Cloud registers it as a
   filesystem disk under the **disk name** you choose. It does not set the `AWS_*` variables, so the `s3` disk in
   `config/filesystems.php` stays unconfigured.
2. Set `PIXELFORGE_IMAGE_DISK` to that disk name and redeploy.
3. Run `php artisan pixelforge:copy-missing-images` to copy images saved before the bucket existed. This only works within
   24 hours of each image's completion, while Pixelforge's link is still valid. Older images are marked as expired.

Also enable a queue worker and the scheduler, run `php artisan migrate --force` and `php artisan db:seed --force`, and point
the production Pixelforge key's webhook at `https://<app>/webhooks/pixelforge`.

## Tests

```bash
php artisan test
```

The tests run against a real Postgres database (`promptedit_test`), so row locks, unique constraints and `ON CONFLICT` behave as in
production. They cover:

- the money maths, including the docs' worked example
- the error mapping and `Retry-After` handling
- duplicate, out-of-order and early webhooks
- bad and stale signatures
- free failures
- resubmission by the reconciler
- the balance checks
- the report matching `/v1/usage`

`QueueDispatchTest` runs a submission through the real `sync` queue driver instead of `Queue::fake()`. The fake skips building the
job payload, which once hid a job method named `backoff()` clashing with Laravel's own retry hook.

Pixelforge's test keywords (`[fail]`, `[slow]`, `[duplicate]`, `[blocked]`, `[overloaded]`, `[succeed]`) are useful for manual
end-to-end checks.
