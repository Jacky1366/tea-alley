# Laravel Cloud deploy runbook

Tea Alley's primary demo runs on [Laravel Cloud](https://cloud.laravel.com), deployed automatically from `main`. The Cloudflare tunnel (`docs/demo-runbook.md`) is the fallback. Decided in #67; the reasons are in `docs/decisions.md`.

Everything below is done by hand in the Cloud dashboard, in this order. **No secret values go in this file, the repo, an issue, or a PR**: only variable names. Secret values live in Cloud (environment variables or organization secrets) and in your password manager.

Cloud docs this is based on (read Nov 20, 2026), all under <https://laravel.com/cloud/docs/>: `deploy-guides/laravel`, `environments`, `compute`, `network`, `scheduled-tasks`, `resources/databases/laravel-mysql`, `resources/object-storage`, `resources/websockets`, `spending-limits`, `pricing`, `domains`. Where they are silent, this file says so.

## How it works

```
phone ──https──▶ Cloud edge (Cloudflare: DDoS, WAF) ──▶ Cloud compute network ──▶ App cluster (Laravel, 1 replica)
                                                                                    ├─ Laravel MySQL         (DB_*)
                                                                                    ├─ object-storage bucket (drink photos, MENU_PHOTO_DISK)
                                                                                    └─ scheduler             (orders:cancel-stale every 5 min)
phone ──wss──▶ Reverb cluster (ws-…-reverb.laravel.cloud:443, its own host)
```

- The app is reachable **only** through Cloud's edge (`network.md`: "the public can reach them only through the Cloud Edge Network"). That's why `TRUSTED_PROXIES=REMOTE_ADDR` is safe: the direct peer is always Cloud's proxy. Never `*` (see `config/tea-alley.php` and step 7, smoke test, check 2).
- The app's filesystem is ephemeral: every deploy resets it. Drink photos therefore live in a bucket, and `php artisan storage:link` is neither needed nor run.
- No queue worker: nothing is queued (broadcasts use `ShouldBroadcastNow`, mail is sent during the request).

## 1. Account, plan, spending limit, app

1. Sign up at <https://cloud.laravel.com> and create an organization.
2. **Plan:** Starter ($5/month plus usage; Flex compute, 1 replica, which is all Tea Alley needs).
3. **Spending limit: $15.** Organization → Settings → Billing → Spending limit. Turn on the alerts (50 / 80 / 90 / 100%) and "Stop all compute" at the limit. Storage stays online when compute stops.
4. Connect GitHub and give Cloud access to the `tea-alley` repository only.
5. **New application** from the repository. Environment: `main`, branch `main`. **Push to deploy: on** (the default). Region: the one closest to Vancouver (a US West region if offered). Put the database and the bucket in the same region or area.
6. **Don't deploy yet**: attach the resources and set the variables first (steps 2 to 4).

## 2. Resources

Create each one under **Resources** and attach it to the `main` environment.

| Resource | Settings | What Cloud injects |
|---|---|---|
| **Laravel MySQL** | Same region as the environment. Smallest Flex size. Backups: Dev preset. **Scale to zero: off during demo week** (step 8). | `DB_HOST`, `DB_USERNAME`, `DB_PASSWORD`, `DB_DATABASE` and the rest of the connection |
| **WebSocket cluster** (Reverb) | Resources → WebSockets → "+ New WebSocket cluster". **100 concurrent connections** ($5/month: 200K messages a day, 10 KB per message). Attach its app (default name `production`) to the environment. | `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET`, `REVERB_HOST` (`ws-…-reverb.laravel.cloud`), `REVERB_PORT` (443), `REVERB_SCHEME` (https), and the frontend `VITE_REVERB_*` variables |
| **Object storage bucket** | Visibility: **Public** (fixed at creation). **Disk name: `photos`**. Cloud makes it the environment's default disk (see below). Jurisdiction / location hint: closest to Vancouver (fixed at creation). | The `photos` filesystem disk (Cloud defines it itself; no `AWS_*` variables needed) |

No cache resource: sessions and cache use the database (step 3), which the deploy guide recommends for light workloads.

The bucket is the default disk, and that's fine. With only one bucket attached, Cloud always makes it the environment's default disk, and `FILESYSTEM_DISK` can't override that, so don't set it. The app doesn't rely on the default disk:
- Drink photos are saved to `MENU_PHOTO_DISK`.
- Livewire's temporary uploads (the `/admin` photo preview before Save) are pinned to the app's `local` disk in `AppServiceProvider` (#69). Otherwise they would go to R2 through signed browser uploads.

Keeping them on the local disk works with one replica: the upload and the Save land on the same instance.

## 3. Environment variables

Environment → Settings → Environment variables. Custom variables override injected ones, so **don't** set the injected ones (`APP_URL`, `DB_*`, `REVERB_APP_*`, `REVERB_HOST`, `REVERB_PORT`, `REVERB_SCHEME`). Cloud writes the variables into a `.env` file before the build, so the build (`npm run build`) sees them too. **Redeploy after any change**: config is cached during the build.

`${NAME}` references resolve only to injected variables or ones defined above them.

| Variable | Value | Where it comes from |
|---|---|---|
| `APP_NAME` | `Tea Alley` | |
| `APP_ENV` | `production` | |
| `APP_DEBUG` | `false` | |
| `APP_KEY` | *secret* | Use Cloud's if the variables page shows one. Otherwise run `php artisan key:generate --show` locally and paste the output (never commit it). Don't change it later: sessions and encrypted data depend on it. |
| `APP_LOCALE` / `APP_FALLBACK_LOCALE` | `en` / `en` | |
| `TRUSTED_PROXIES` | `REMOTE_ADDR` | #67. Never `*`. |
| `MENU_PHOTO_DISK` | `photos` | The bucket's disk name from step 2 |
| `TEA_ALLEY_DEMO_DATA` | `true` | Demo week only; lets the demo seeders run (step 5). Remove after the demo. |
| `SESSION_DRIVER` / `CACHE_STORE` / `QUEUE_CONNECTION` | `database` / `database` / `database` | Today's values. No worker is needed: nothing is queued. |
| `BROADCAST_CONNECTION` | `reverb` | |
| `VITE_APP_NAME` | `"${APP_NAME}"` | |
| `VITE_REVERB_APP_KEY` | `"${REVERB_APP_KEY}"` | Add the four `VITE_REVERB_*` lines **unless Cloud already lists them** after the WebSocket app is attached. The docs say they're injected but don't say they reach the build, and Echo needs them at build time. |
| `VITE_REVERB_HOST` | `"${REVERB_HOST}"` | With this set, Echo connects to Cloud's Reverb host instead of the page's host. |
| `VITE_REVERB_PORT` | `"${REVERB_PORT}"` | |
| `VITE_REVERB_SCHEME` | `"${REVERB_SCHEME}"` | |
| `MAIL_MAILER` | `smtp` | |
| `MAIL_HOST` / `MAIL_PORT` | `smtp.gmail.com` / `587` | |
| `MAIL_USERNAME` | the shop's Gmail address | |
| `MAIL_PASSWORD` | *secret* | A **Gmail app password** made for Cloud (Google Account → Security → App passwords), not the one used locally. Revoke it after the demo (step 8). |
| `MAIL_FROM_ADDRESS` | the shop's Gmail address | |
| `MAIL_FROM_NAME` | `"${APP_NAME}"` | |
| `PAYMENT_GATEWAY` | `stripe` | |
| `STRIPE_SECRET` | *secret* | A **restricted** test-mode key (`rk_test_…`) with only **Checkout Sessions: Write**, made in the Stripe Dashboard (Developers → API keys → Create restricted key). Not the `sk_test_` key. The checkout page shows the 4242 test-card hint for either test key. |
| `STRIPE_WEBHOOK_SECRET` | *secret* | The Dashboard endpoint's signing secret (step 6), not the `stripe listen` one |

Leave unset: `REVERB_SERVER_HOST` / `REVERB_SERVER_PORT` (only for running Reverb yourself), `LOG_CHANNEL` (Cloud configures its own log channel), `SESSION_SECURE_COOKIE` (secure by default in production), all `AWS_*`, `FILESYSTEM_DISK` (Cloud fixes the default disk while a bucket is attached; see step 2).

Secret values can also be organization **Secrets** attached to the environment (`secrets.md`).

## 4. Build and deploy commands, scheduler

Environment → Settings → Deployments.

**Build commands** (the deploy guide's set; `optimize` belongs in the build, not the deploy):

```
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
npm ci --audit false
npm run build
php artisan optimize
```

**Deploy command** (runs just before the new release goes live):

```
php artisan migrate --force
```

Nothing else: Cloud doesn't keep changes made by deploy commands (so no `storage:link`), restarts processes itself (no `queue:restart`), and the build already cached everything (no `optimize:clear`). Seeders are **never** part of a deploy: they're the one-time commands in step 5. Build and deploy commands each time out after 15 minutes. `public/hot` is git-ignored, so a build never contains it.

**Scheduler:** App cluster → **Scheduler: on** → save. Cloud then runs `schedule:run` every minute, which runs `orders:cancel-stale` every 5 minutes (the backup for Stripe's "expired" webhook).

**App cluster:** smallest Flex size, 1 replica. **Scale to zero: off** during demo week (step 8).

## 5. First deploy, then the one-time commands

1. **Deploy** (or push to `main`). Wait for it to go live, then open the `*.laravel.cloud` URL Cloud assigned. Check that `APP_URL` in the variables is that URL. If it isn't, set it and redeploy: links in emails use `APP_URL`, and TrustHosts accepts only its host.
   - The first Cloud deploy (2026-11-20) passed its **health check** with TrustHosts on. The `/up` exemption this step used to hold in reserve isn't needed.
2. Open **Commands → Open console** (interactive; transcripts are kept in the Commands history) and run, in order:

   ```
   php artisan db:seed --class=RoleSeeder --force
   php artisan db:seed --class=MenuSeeder --force
   php artisan db:seed --class=MenuDescriptionsSeeder --force
   php artisan db:seed --class=MenuPhotosSeeder --force
   ```

   - `MenuSeeder` creates the 30 drinks with both descriptions. `MenuDescriptionsSeeder` then reports "Descriptions filled for 0 drinks", which confirms it.
   - `MenuPhotosSeeder` should report "Photos attached: 30. Already had a photo, kept: 0." and no skipped photos.
   - `--force` only skips Laravel's "are you sure?" production prompt, which would otherwise fail a non-interactive command.
3. **Staff accounts.** Production staff need **real inboxes**: `@tea-alley.test` addresses can't receive mail. Use Gmail plus-aliases of the shop's address, for example `<shop gmail name>+barista@gmail.com`:

   ```
   php artisan tea-alley:create-staff <shop gmail name>+admin@gmail.com admin --name="Jacky"
   php artisan tea-alley:create-staff <shop gmail name>+manager@gmail.com manager --name="Manager"
   php artisan tea-alley:create-staff <shop gmail name>+barista@gmail.com barista --name="Barista"
   ```

   - Each account gets a random password nobody sees, and the address gets a "Reset Password" email. Open it and choose the password there, within 60 minutes. After that, use "Forgot password?" on the login page.
   - No password is ever typed into the console, so none is in its transcript.
   - The command refuses customer roles and existing emails.
4. **Store hours.** Log in as admin → `/admin` → Store hours. A fresh database has **every day closed** (the migration default), so nobody can order until this is done.
   - Set realistic hours for every day. The demo orders in the next step only fall inside open hours, so closed days get no history.
   - **Friday must cover Dec 4, 4:00–7:05 PM:** opens at or before 4:00 PM and closes at or after **7:20 PM** (ordering ends at closing minus the 15-minute cutoff).
5. **Demo customers and orders** (needs `TEA_ALLEY_DEMO_DATA=true` and the store hours):

   ```
   php artisan db:seed --class=DemoOrdersSeeder --force
   ```

   This creates the 20 spare customer accounts (`customer01@tea-alley.test` … `customer20`, password `password`, customers only) and two weeks of their orders. Run again, it replaces only those 20 accounts' orders. It sends no email and broadcasts nothing.

## 6. Stripe webhook

1. Stripe Dashboard, **test mode** → Developers → Webhooks → **Add endpoint**.
2. URL: `https://<cloud-url>/stripe/webhook`.
3. Events: the same three as `stripe listen` today: `checkout.session.completed`, `checkout.session.async_payment_succeeded`, `checkout.session.expired`.
4. Copy the endpoint's **signing secret** into `STRIPE_WEBHOOK_SECRET` (step 3) and redeploy.
5. No `stripe listen` is needed for Cloud. Locally it keeps working as before (README).

## 7. Smoke test

On a phone on 4G (not Wi-Fi), unless noted:

1. **Menu with photos:** the home page shows all 30 drinks with their photos. Image URLs point at the bucket's URL, not `/storage/`.
2. **Register:** a new account with a real address. The verification email arrives, and its link opens the Cloud URL.
3. **Order:** add a drink, check out, pay with `4242 4242 4242 4242` (any future date, any CVC).
4. **Webhook:** the order turns **Paid** on its own (Stripe Dashboard → the endpoint shows `checkout.session.completed` delivered with 200).
5. **Barista:** log in as the barista on a laptop, open `/barista`, Bump the order through to Ready. The "ready" email arrives.
6. **Live:** the order page on the phone and the barista board both show **Live**, not Polling, and update without a reload.
7. **Dashboard:** `/admin` as manager shows the demo orders' sales, trend and best sellers.
8. **Photo upload:** `/admin` → a drink → upload a JPEG. The preview and the table thumbnail show, and the menu shows the new photo. The bucket (Resources → bucket → browse) has a new file under `menu-items/` with a random name.
9. **Error pages and headers:** `https://<cloud-url>/no-such-page` shows the shop's 404 page, not a stack trace. Admin modals (e.g. a delete confirmation) open normally. Cloud's edge adds `X-Frame-Options: DENY` by default, and the app sends `SAMEORIGIN`; the docs don't say which wins, so this checks it.
10. **Client IPs, both directions** (`TRUSTED_PROXIES=REMOTE_ADDR`):
    - **(1) A visitor can't fake their IP.** From your PC (Git Bash), with `URL=https://<cloud-url>`:

      ```bash
      jar=$(mktemp)
      token=$(curl -s -c "$jar" "$URL/forgot-password" | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//; s/"$//')
      for i in 1 2 3 4 5 6; do
        curl -s -o /dev/null -w "$i: %{http_code}\n" -b "$jar" -c "$jar" \
          -H "X-Forwarded-For: 198.51.100.$i" \
          --data-urlencode "_token=$token" --data-urlencode "email=nobody$i@example.com" \
          "$URL/forgot-password"
      done
      rm -f "$jar"
      ```

      **Expected:** `302` five times, then **`429`**. Each request claims a different IP, but the app uses the address Cloud's proxy appended, so all six count as you. If the 6th isn't 429, the header is spoofable. Then set `TRUSTED_PROXIES` back to `127.0.0.1` (everyone shares one limit, which is safe) and open a ticket.
    - **(2) Each visitor gets their own IP.** Log in on the phone (4G) and on the PC. Then, in the Commands console:

      ```
      php artisan tinker --execute="dump(DB::table('sessions')->whereNotNull('user_id')->orderByDesc('last_activity')->limit(5)->get(['user_id', 'ip_address']))"
      ```

      **Expected:** the phone's session shows the phone's public IP (search "what is my IP" on the phone), and the PC's shows the PC's, and they differ. If both show a Cloudflare address (another proxy hop), every visitor shares one rate limit. **Note it as a follow-up, not a blocker.**

## 8. Demo day, and after

**Demo week (Dec 1–5):**

- App cluster **Scale to zero: off**, MySQL **Scale to zero: off**. No cold starts during the demo.
  - Turning scale to zero on barely saves anything anyway: the scheduler wakes the environment every 5 minutes for `orders:cancel-stale`, and its cache lookups can keep the database awake.
  - The docs don't mention sleep for Reverb clusters; it's a flat $5/month.
- **That afternoon:** `php artisan db:seed --class=DemoOrdersSeeder --force` (Commands console), so the two weeks end today and "today" already has sales.
- **QR code in the slides:** the Cloud URL (`/barista/ordering-qr` shows it as a QR).
- Fallback if Cloud is down: the tunnel (`docs/demo-runbook.md`).

**After the demo:**

1. **Revoke the Gmail app password** made for Cloud (Google Account → Security → App passwords).
2. Remove `TEA_ALLEY_DEMO_DATA` and redeploy.
3. Either **stop the environment** (Environment → Stop: all compute shuts down; the database and bucket keep their data and their storage cost), or **delete** the environment and its resources to stop all charges. Or, to keep it up cheaply, turn **Scale to zero back on** for the App cluster and MySQL.
4. In Stripe test mode, delete the webhook endpoint and the restricted key if the environment is gone.
5. Keep the spending limit.
