# Demo runbook

> **Laravel Cloud is the primary demo path (`docs/cloud-deploy.md`, #67). This tunnel is the fallback.**

How to put Tea Alley on the internet for phones (classmates on 4G, PWA), and how to go back to local development. Decided in #57; the reasons are in `docs/decisions.md`.

## How it works

```
phone ──https──▶ Cloudflare (Vancouver edge) ──quick tunnel──▶ cloudflared (this PC)
      ──https, loopback only, verified with Herd's CA──▶ Herd nginx 127.0.0.1:443 (Host: tea-alley.test)
          ├─ /app/…      ──https, verified with Herd's CA──▶ Reverb 127.0.0.1:8080   (WebSocket)
          └─ everything else ──▶ Laravel
```

- **One URL** (`https://<random words>.trycloudflare.com`). It changes every time `cloudflared` starts.
- **TLS** ends at Cloudflare; from `cloudflared` to Herd it's TLS again, on loopback, verified with Herd's CA. Phones never see `tea-alley.test`.
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
4. **Herd Studio off** in Herd's settings, then **restart Herd**. Check that `~\.config\herd\config\config.json` now says `"studio": false`. Herd 2 still serves `/herd-studio/loader.js` and proxies `/__herd/ai/ws` (its AI agent's socket) with Studio off, so the demo snippet blocks both in nginx. The script refuses to go public unless every encoded, doubled-slash and upper-case form of both paths returns 404, as a plain GET and as a WebSocket upgrade, locally and through the tunnel.
5. **The tunnel**, in its own terminal:
   ```powershell
   cloudflared tunnel --url https://127.0.0.1:443 --origin-ca-pool "$env:USERPROFILE\.config\herd\config\valet\CA\LaravelValetCASelfSigned.crt" --origin-server-name tea-alley.test --http-host-header tea-alley.test
   ```
   Use Herd's HTTPS listener (`:443`), **not** its plain-HTTP one (`:60`). On Windows, Herd 2's `:60` block can't serve static files: every `/build/` asset is a 500, so pages load without CSS or JS. TLS to Herd is verified against Herd's own CA.
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
   - **Then:** backs up `.env` to `.env.demo-backup` (git-ignored), sets the demo keys, installs the demo snippet into Herd (`server_snippets/tea-alley-demo.conf`: blocks Herd Studio, proxies only `/app/` to Reverb), restarts nginx, stops if Herd Studio still answers, runs `npm run build` (Echo on port 443) and `php artisan optimize`.
   - **Last, it checks through the tunnel:** `/up` is 200; a missing page is 404; the built CSS and JS load (200, right Content-Type); the WebSocket gets 101; `/apps/` is 404 (Reverb's HTTP API stays private); `tea-alley.test` is 400; every Herd Studio variant is 404 (GET and upgrade). Run `.\scripts\demo-mode.ps1 check` while demo mode is on to repeat these checks any time.
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

## Before demo day

- [ ] **Store hours cover the presentation (Fri Dec 4, 4:00–6:50 PM).** In `tea_alley` (Admin → Store hours), Friday must be open, with `opens_at` at or before 4:00 PM and `closes_at` at or after **7:05 PM**: ordering stops `ordering_cutoff_minutes` (15) before closing. Otherwise the menu says ordering is closed and nobody can order. (On Nov 18 it was 11:00–22:00, which is fine.)

## Demo day

1. **In the afternoon, still local:** `php artisan db:seed --class=DemoOrdersSeeder`, so the 14 days end today and "today" already has sales.
2. Demo mode **ON** (above). On the barista board, open **Show ordering QR**, press **Full screen**, and project it: classmates scan it to open the menu. It always shows the current `APP_URL`, so a new tunnel URL needs nothing but a reload of that page.
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
| Pages without styles; `/build/…` assets are **500** | The tunnel points at Herd's `:60` listener. Restart `cloudflared` with the `:443` command (step 5). |
| **Polling** instead of **Live** | Reverb isn't running (a Herd restart can stop it), or the demo snippet isn't installed (Herd's `server_snippets/tea-alley-demo.conf`). Echo uses port 443 only from a build made in demo mode. |
| `check` says Herd Studio isn't blocked | The demo snippet is missing or Herd's nginx didn't reload: `herd restart nginx`, then `check` again. If it still fails, `off`. Don't share the URL meanwhile. |
| Paid on Stripe but the order stays "Payment pending" | `stripe listen` isn't forwarding to `https://<tunnel>/stripe/webhook`, or its secret doesn't match `STRIPE_WEBHOOK_SECRET`. |
| 429 from Cloudflare | More than 200 requests in flight on the quick tunnel. |
