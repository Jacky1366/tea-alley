# Tea Alley

Bubble tea shop ordering app built with Laravel, Livewire, and Flux.

## Local setup

Requires PHP 8.4 + Composer (Herd), MySQL 8.4 (DBngin) running on `127.0.0.1:3306` with user `root` and no password, and Node 22.

```bash
git clone https://github.com/Jacky1366/tea-alley.git
cd tea-alley

composer install
npm install

cp .env.example .env          # Windows cmd: copy .env.example .env
php artisan key:generate

# Create the database (or create "tea_alley" in TablePlus)
mysql -u root -e "CREATE DATABASE tea_alley"

php artisan migrate:fresh --seed
php artisan storage:link      # menu photos are served from storage/app/public
npm run build
```

Herd serves the app at https://tea-alley.test (secure the site once with `herd secure tea-alley`, or the lock icon in Herd's Sites list). The admin panel is at https://tea-alley.test/admin (manager and admin accounts only; sign in through the normal login page). Use `npm run dev` instead of `npm run build` while working on the frontend.

### Real-time updates (Reverb)

The barista board and the order status page update live through [Laravel Reverb](https://reverb.laravel.com). Keep it running in a second terminal while you work:

```bash
php artisan reverb:start
```

(`composer dev` starts it too, together with Vite and a queue worker.)

- Reverb listens on `127.0.0.1:8080`. Because `REVERB_HOST=tea-alley.test`, it uses Herd's certificate for `tea-alley.test` and serves `wss://` automatically; the site just has to be secured in Herd (see above). Laravel sends broadcasts to `https://tea-alley.test:8080`, so PHP must trust Herd's CA; Herd's PHP does on Windows (its `curl.cainfo` bundle includes it). If broadcasts fail with a certificate error on another machine, that's the cause.
- Without Reverb the app still works: both pages show **Polling** instead of **Live** and refresh every 5 seconds.
- Tests and CI never need Reverb: `phpunit.xml` sets `BROADCAST_CONNECTION=null`.

### Troubleshooting

**The indicator stays on Polling.**
1. Is `php artisan reverb:start` running, and does it say `Starting secure server on 127.0.0.1:8080 (tea-alley.test)`? If it doesn't say *secure*, the site isn't secured in Herd, or `REVERB_HOST` isn't `tea-alley.test`.
2. Did you rebuild after changing any `VITE_REVERB_*` value? They're built into the JS: run `npm run build` again (or restart `npm run dev`).
3. Still nothing: the browser console shows Echo's connection errors.

**Behind a tunnel (T3-6).** The browser connects to Reverb on the page's own host, on the port in `VITE_REVERB_PORT`. Through a single tunnel the page is on port 443, so the tunnel must route `/app/` to `127.0.0.1:8080`, and the assets must be built with `VITE_REVERB_PORT=443`:

```bash
VITE_REVERB_PORT=443 npm run build
```

Rebuild with the default (`npm run build`) when you go back to `https://tea-alley.test`.

### Payments (Stripe Checkout, test mode)

With `PAYMENT_GATEWAY=fake` (the default) orders are marked paid as soon as they're placed. To pay through Stripe instead, set these in your local `.env`. Never commit them: the repository is public.

| Variable | Value |
| --- | --- |
| `PAYMENT_GATEWAY` | `stripe` |
| `STRIPE_SECRET` | A **test-mode** secret key from the Stripe Dashboard (Developers → API keys). A restricted key with only *Checkout Sessions: Write* is enough. |
| `STRIPE_WEBHOOK_SECRET` | The `whsec_…` secret of the Stripe CLI: after `stripe login`, run `stripe listen --print-secret`. |

Only the Stripe webhook marks an order paid, so Stripe must be able to reach the app. Forward its events with the Stripe CLI in a second terminal:

```bash
stripe listen --forward-to https://tea-alley.test/stripe/webhook
```

- Pay with the test card `4242 4242 4242 4242`, any future date, any CVC.
- Orders still waiting for payment are cancelled by Stripe's `expired` event, or by `php artisan orders:cancel-stale` (every 5 minutes when the scheduler runs: `php artisan schedule:work`).
- On `local`, an admin's own pending order has a **Simulate payment** button: a demo backup when Stripe or the tunnel is down.
- Tests and CI never use Stripe: `phpunit.xml` sets `PAYMENT_GATEWAY=fake` and empty keys, and every test fails if anything tries to reach Stripe's API.
- The payment methods Checkout offers are set in the Stripe Dashboard (Settings → Payment methods), not in code: since API version 2026-10-28 Stripe rejects `payment_method_types`. Leave only **Cards** on for the demo. A delayed method would work too: the order is marked paid when `checkout.session.async_payment_succeeded` arrives.
- Behind a tunnel or in production, add a webhook endpoint in the Stripe Dashboard for `https://<APP_URL host>/stripe/webhook` (events `checkout.session.completed`, `checkout.session.async_payment_succeeded`, and `checkout.session.expired`). Its signing secret is different from the CLI's.

## Demo accounts

Seeded by `DemoUserSeeder` (never in production). All accounts are email-verified.

| Email | Password | Role |
|---|---|---|
| customer@tea-alley.test | password | customer |
| barista@tea-alley.test | password | barista |
| manager@tea-alley.test | password | manager |
| admin@tea-alley.test | password | admin |
