# Demo runbook

How to put Tea Alley on the internet for phones (classmates on 4G, the two-phone QR test, PWA), and how to go back to local development. Decided in #57; the reasons are in `docs/decisions.md`.

## How it works

```
phone ──https──▶ Cloudflare (Vancouver edge) ──quick tunnel──▶ cloudflared (this PC)
      ──http, loopback only──▶ Herd nginx 127.0.0.1:60 (Host: tea-alley.test)
          ├─ /app/…      ──https, verified with Herd's CA──▶ Reverb 127.0.0.1:8080   (WebSocket)
          └─ everything else ──▶ Laravel
```

- **One URL** (`https://<random words>.trycloudflare.com`). It changes every time `cloudflared` starts.
- **TLS** ends at Cloudflare. Phones never see `tea-alley.test`.
- **Host routing:** Herd picks the site from the rewritten `Host: tea-alley.test`. Laravel takes the public host from `X-Forwarded-Host`, trusted from loopback only (`bootstrap/app.php`), so every generated URL, redirect and link uses the tunnel URL.
- **One host in demo mode:** `APP_ENV=production` turns TrustHosts on, which accepts only `APP_URL`'s host. **Use the tunnel URL on every device, this PC included**, and on `stripe listen`. `https://tea-alley.test` answers 400 until demo mode is off.
- **Limits:** quick tunnels allow 200 requests in flight (fine for 40 phones), have no monthly cap and no uptime guarantee, and don't support SSE (we don't use it).

`scripts/demo-mode.ps1` does the `.env`, Herd, build and cache steps. It backs up `.env` first, changes only `APP_ENV`, `APP_DEBUG`, `APP_URL`, `PAYMENT_GATEWAY` and `VITE_REVERB_PORT`, and never prints a value.

## One-time setup

- [ ] `cloudflared` installed: `winget install --id Cloudflare.cloudflared --exact`. No account needed.
- [ ] **New Reverb credentials.** The ones in `.env.example` are public in the repo. In your own editor, give `.env` a new `REVERB_APP_ID`, `REVERB_APP_KEY` and `REVERB_APP_SECRET`, and set `VITE_REVERB_APP_KEY` to the same key. Random values, e.g. from `php -r "echo bin2hex(random_bytes(16));"` (run it in your own terminal). Then restart Reverb and rebuild.

## Turn demo mode ON

Do the steps in order.

1. **DBngin**: MySQL running. **Herd** running.
2. **Stop the dev stack** (`composer run dev`, `php artisan dev`, `npm run dev`). The Vite dev server must not run, because phones can't reach `:5173`.
3. **Reverb**, in its own terminal: `php artisan reverb:start`
4. **Herd Studio off** in Herd's settings. Through the tunnel it would be reachable from the internet. The script refuses to continue until both `/herd-studio/loader.js` and `/__herd/ai/ws` return 404.
5. **The tunnel**, in its own terminal:
   ```powershell
   cloudflared tunnel --url http://127.0.0.1:60 --http-host-header tea-alley.test
   ```
   Copy the `https://….trycloudflare.com` URL it prints.
6. **Stripe webhooks through the tunnel**, in its own terminal (`tea-alley.test` is refused in demo mode):
   ```powershell
   stripe listen --events checkout.session.completed,checkout.session.async_payment_succeeded,checkout.session.expired --forward-to https://<tunnel>/stripe/webhook
   ```
   `STRIPE_WEBHOOK_SECRET` must match `stripe listen --print-secret`.
7. **Switch:**
   ```powershell
   .\scripts\demo-mode.ps1 on https://<tunnel>
   ```
   - **Preflight:** Herd Studio off, `MAIL_MAILER` is `smtp`, Reverb on 8080, `stripe listen` forwarding to this tunnel, no Vite dev server, the tunnel running. It stops with a clear message, and changes nothing, if any of these fail.
   - **Then:** backs up `.env` to `.env.demo-backup` (git-ignored), sets the demo keys, installs the `/app/` proxy into Herd (`server_snippets/tea-alley-reverb.conf`), restarts nginx, runs `npm run build` (Echo on port 443) and `php artisan optimize`.
   - **Last, it checks through the tunnel:** `/up` is 200; a missing page is 404; the WebSocket gets 101; `/apps/` is 404 (Reverb's HTTP API stays private); Herd Studio is 404; `tea-alley.test` is 400.
8. **Smoke test on a phone on 4G** (Wi-Fi off): the menu has its styles, you can log in, and the order page shows **Live**.

**If the tunnel restarts**, the URL changes. Run `off`, then steps 5–7 again with the new URL.

## Turn demo mode OFF

1. `.\scripts\demo-mode.ps1 off`:
   - restores exactly the five keys from the backup, so a key `on` added is removed again;
   - removes the Herd snippet and restarts nginx;
   - runs `php artisan optimize:clear` and `npm run build`;
   - prints `php artisan about`.
   - If `.env` now matches the backup byte for byte, the backup is deleted. Otherwise it's kept and the differing line numbers are listed: edits made during the demo stay.
2. Stop `cloudflared` (Ctrl+C) and the tunnel's `stripe listen`. Start `stripe listen` again with `--forward-to https://tea-alley.test/stripe/webhook` (README).
3. Herd Studio back on if you want it. `composer run dev` again.
4. Check: `https://tea-alley.test` works, and `php artisan test` is green.

## Demo day

1. **In the afternoon, still local:** `php artisan db:seed --class=DemoOrdersSeeder`, so the 14 days end today and "today" already has sales.
2. Demo mode **ON** (above). Put the tunnel URL on the slide as a QR code.
3. **Never run `DemoOrdersSeeder` after the demo starts.** It deletes every order of the 20 spare customer accounts, including ones classmates place with them.
4. Spare accounts for anyone whose verification email doesn't arrive: `customer01@tea-alley.test` … `customer20@tea-alley.test`, password `password`.

## After the demo

- [ ] Demo mode **OFF**.
- [ ] Delete the Gmail app password **`tea-alley`** (Google Account → Security → App passwords).
- [ ] Optional: new Reverb credentials again.

## Troubleshooting

| Symptom | Cause |
|---|---|
| **400 Bad Request** | You opened `https://tea-alley.test` (refused in demo mode), or the tunnel restarted and `APP_URL` is the old URL: `off`, then `on` with the new one. |
| Pages without styles; requests to `:5173` | `public/hot` exists (the Vite dev server ran). Stop it; `on` removes a stale `public/hot` and builds. |
| **Polling** instead of **Live** | Reverb isn't running, or the `/app/` proxy isn't installed (Herd's `server_snippets/tea-alley-reverb.conf`). Echo uses port 443 only from a build made in demo mode. |
| Paid on Stripe but the order stays "Payment pending" | `stripe listen` isn't forwarding to `https://<tunnel>/stripe/webhook`, or its secret doesn't match `STRIPE_WEBHOOK_SECRET`. |
| 429 from Cloudflare | More than 200 requests in flight on the quick tunnel. |
