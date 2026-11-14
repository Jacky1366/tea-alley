# Tea Alley 茶巷 — Development Plan

Nov 10, 2026 · @Jacky Huang

> **Note:** This is the original plan. Where a ticket's GitHub issue, the decision log, or CLAUDE.md says something different, those win — the plan is the starting point for writing issues, not the final spec.

## Overview

這份 plan 把 Project Plan 的 Section 3（feature scope）和 Section 8（timeline）拆成 5 個 stage、30 張 ticket，每張都有 acceptance criteria，可以直接當 GitHub Issue 用。Stage 就是 sprint，每個 stage 結束都是可以 demo 的狀態。

兩個 graded milestone：Nov 13 progress presentation（5%）和 Dec 4 report（20%）+ final presentation（5%）。Stage 3 和 Stage 4 刻意重疊 Dec 1–3，report 從 Stage 0 就開始邊做邊寫。

## Production habits: keep vs skip

是 good habit，但要分清楚兩種 habit：**correctness habits**（讓系統在 5 個人用的時候也不會出錯）全部保留；**scale habits**（為了百萬用戶）全部跳過。Tea Alley 只有一間店、demo 時最多十幾個同學同時下單，但這十幾個人同時按「Place order」就足以觸發 race condition。

| Habit | Keep or skip | Why for Tea Alley |
| --- | --- | --- |
| Server-side price + tax calculation | Keep | 前端價格可以被改，checkout 一定要從 DB 重算 |
| DB transactions for order creation | Keep | Order + items + modifiers 要全部成功或全部失敗 |
| Row locks for promo `used_count` | Keep | 兩個人同時用最後一次的 promo code 會超用 |
| Idempotent Stripe webhook | Keep | Stripe 會重送 webhook，同一張單不能被標記 paid 兩次 |
| Double-submit protection on checkout | Keep | 手機上連按兩次會產生兩張訂單 |
| Policies on every route + role × route tests | Keep | RBAC 是課程核心，也是 Q&A 最常被問的 |
| Migrations + seeders, never hand-edit DB | Keep | 兩台電腦和 CI 都能一鍵重建 |
| Eager loading, no N+1 queries | Keep | 開 `preventLazyLoading` 幾乎零成本，順便學到 |
| Pest tests for money, tax, state machine | Keep | 這三個地方錯了，demo 時最明顯 |
| Rate limiting on login and checkout | Keep | Laravel 內建，一行設定 |
| Redis, caching layer | Skip | 單一店家用 MySQL 就夠，plan 已決定不建 cache |
| Load testing, horizontal scaling, CDN | Skip | 沒有這個流量 |
| Docker, Kubernetes, cloud deploy | Skip | Demo 用 tunnel，plan 已決定 |
| Microservices, separate API + SPA | Skip | 一個 Laravel app 服務三個介面最簡單 |
| Monitoring stack (Sentry, Grafana) | Skip | Laravel log 就夠了 |

## Engineering standards

每張 ticket 都要過同一套標準才能 merge；這些規則寫進 `CLAUDE.md`，Claude Code 每次開工都會讀。

**Definition of done（每張 ticket）**

- Acceptance criteria 全部達成，Jacky 本機跑過
- 有 Pest test，GitHub Actions 綠燈
- 通過 Project Plan Section 9 的 review checklist
- 新功能如果對應 Course Project Guide 的要求，更新 Section 7 對照表（寫上檔案路徑）

**Git workflow**

- `main` 開 branch protection：必須經過 PR + CI 通過才能 merge，不能直接 push
- Branch 命名：`feature/T1-2-drink-builder`、`fix/T1-5-tax-rounding`
- Commit 用 Conventional Commits：`feat: add drink builder`、`fix: round GST per line`、`test:`、`docs:`
- 一張 ticket 一個 PR；PR description 用 template 貼上 review checklist

**Testing strategy**

- **Unit tests**：`PricingService`、`TaxCalculator`、`ModifierValidator`、`OrderStatus` state machine。用 table-driven cases，邊界值一定要測
- **Feature tests**：auth、每個 route 的 role 權限、checkout 流程、webhook
- **不做**：browser / E2E tests（時間成本太高，用手動 demo script 代替）
- Tests 用獨立的 `tea_alley_test` MySQL 資料庫，跟 CI 同一個 engine

**Data rules**

- 金額一律存整數 cents（`unsignedInteger`），只在 view 層格式化成 `$6.25`
- 時間存 UTC，顯示用 `America/Vancouver`；「今天」的營收要用 Vancouver 的日期邊界算
- 下單時存 name / price snapshot，報表只讀 snapshot
- Migration 一旦 merge 進 `main` 就不再修改，要改就開新的 migration
- 程式碼裡只透過 `config()` 讀設定，`env()` 只出現在 `config/` 檔案

**Security rules**

- 每個新 route 都要有 middleware 或 policy，並有一個「錯的 role 會被擋」的 test
- Livewire component 的 public property 不能信任：價格、user id、order id 都要在 server 端重新查、重新授權
- 檔案上傳限制 mime type 和大小，存到 `public` disk 時用隨機檔名
- 不用 `{!! !!}` 輸出使用者資料；不用 `DB::raw()` 拼接 input
- `.env` 不 commit；只用 Stripe test keys；demo 時 `APP_DEBUG=false`

## Stage 0 — Foundation (Nov 10–12)

**Goal:** Nov 13 能 live demo：登入、Filament 管理菜單、客人看菜單頁。原本 plan 的 T0-1 到 T0-6 不變，前面加一張 T0-0 把規則先立好，後面每張 ticket 才會一致。

- [ ] **T0-0 Repo conventions**
  - `CLAUDE.md`：stack 與版本、資料夾結構、命名規則、上方的 engineering standards
  - `.github/pull_request_template.md` 貼上 review checklist；一個 issue template（goal / acceptance criteria / tables / out of scope）
  - `main` branch protection；secret scanning + push protection 已開
- [ ] **T0-1 Project skeleton + auth**
  - `laravel new`，選 Livewire starter kit + Pest；PHP 8.4（Herd）、MySQL 8.4（DBngin）
  - `.env` 改成 `DB_CONNECTION=mysql`；`.env.example` 同步更新，不含任何 secret
  - `APP_TIMEZONE` 維持 UTC，顯示時區設 `America/Vancouver`；`MAIL_MAILER=log`
  - 開 email verification；`AppServiceProvider` 在非 production 開 `Model::shouldBeStrict()`
  - README 寫好換電腦的 5 行 setup 指令
  - **Done when：** 全新 clone 照 README 做完，能註冊、收到（log 裡的）驗證信、登入、登出
- [ ] **T0-2 Menu schema + seeders**
  - Migrations：`categories`、`menu_items`、`modifier_groups`、`modifier_options`、`menu_item_modifier_group`
  - FK 都有 `constrained()`；金額欄位 unsigned int cents；`sort_order`、`is_available` 有 index
  - Models + factories + relationships；`MenuSeeder`：6 分類、約 18 杯（中英雙名）、5 個 modifier groups
  - **Done when：** `php artisan migrate:fresh --seed` 一次成功；relationship 有 test
- [ ] **T0-3 Roles + demo accounts**
  - spatie/laravel-permission；`RoleSeeder` 建 4 個 role，每個 role 一個 demo 帳號（寫在 README）
  - 註冊的新用戶自動拿 `customer`
  - `User::canAccessPanel()` 只允許 manager / admin 進 Filament；`/barista` 先放一個空頁 + `role:barista|manager|admin`
  - **Done when：** test 證明 customer 和 barista 進 `/admin` 會被擋，customer 進 `/barista` 會被擋
- [ ] **T0-4 Filament menu management**
  - Resources：Category、MenuItem（照片上傳、image only、≤ 2 MB）、ModifierGroup（options 用 relation manager）
  - 價格欄位輸入 dollars、存 cents；table 有 search、filter（category、available）、sort
  - MenuItem 表單可以勾選套用哪些 modifier groups
  - **Done when：** manager 能完整 CRUD 三個 entity；刪除有確認；policy test 通過
- [ ] **T0-5 Customer menu page**
  - `/` Livewire component：分類 tabs、keyword search（中英名都能搜）、sort（price / popularity）
  - Popularity 還沒有訂單資料 → 先用 `sort_order`，Sprint 2 改成近 30 天銷量
  - Mobile-first，只顯示 `is_available` 的飲料；用 eager loading 避免 N+1
  - **Done when：** 手機寬度看起來正常；search / filter / sort 有 feature test
- [ ] **T0-6 CI pipeline**
  - GitHub Actions：PHP 8.4 + MySQL 8.4 service container
  - 步驟：`composer install` → `composer audit` → `npm ci && npm run build` → `php artisan test`
  - **Done when：** PR 上看得到綠燈，故意弄壞一個 test 會變紅

**Nov 13 demo checklist**

- [ ] 架構圖 + ER diagram（12 張 table，標出 Stage 0 已實作的 5 張）
- [ ] Demo script：manager 登入 → 新增一杯飲料 + 上傳照片 → 客人頁搜得到 → customer 進 `/admin` 被擋
- [ ] Backup：錄一段 demo 影片，tunnel 或網路掛了就播影片

## Stage 1 — Ordering flow (Nov 13–20)

**Goal:** 客人能從選飲料一路走到下單、看到收據。付款先用 `FakePaymentGateway` 直接標記 paid，Stage 2 再換成 Stripe，所以這個 stage 不會被 Stripe 卡住。

- [ ] **T1-1 Order schema**
  - Migrations：`orders`、`order_items`、`order_item_modifiers`、`promo_codes`（表先建，邏輯 Stage 2）、`store_hours`
  - `OrderStatus` PHP enum：`pending_payment`、`paid`、`preparing`、`ready`、`picked_up`、`cancelled`
  - Snapshot 欄位：`name`、`unit_price`、`price_delta`；`order_number` unique index
  - **Done when：** ER diagram 和 migration 對得上，12 張核心 table 全部建好
- [ ] **T1-2 Store hours + ordering window**
  - Filament 編輯 7 天的營業時間和「打烊前幾分鐘停止線上點餐」
  - `StoreHours::isOrderingOpen(Carbon $now)`，全部用 Vancouver 時間判斷
  - 非點餐時間：菜單可看，但「Add to cart」和 checkout 都關閉並顯示下次開放時間
  - **Done when：** unit test 涵蓋開店前、營業中、截止後、打烊日
- [ ] **T1-3 Drink builder**
  - `/menu/{item}`：依 pivot 顯示該飲料的 modifier groups，`is_default` 預先選好
  - 單選群組用 radio、多選用 checkbox；售完的 option 顯示但不能選
  - 前端即時算價（Alpine）只是顯示用
  - `ModifierValidator` 在 server 端檢查：required、min / max、option 屬於這杯飲料、沒有售完
  - **Done when：** validator unit test 涵蓋每條規則；用 devtools 改 option id 會被擋
- [ ] **T1-4 Session cart**
  - 加入、改數量、刪除；同飲料同選項合併成一行（用 item id + 排序後的 option ids 當 key）
  - Cart 只存 id 和數量，不存價格；每次顯示都從 DB 重算
  - 打開 cart 時重新驗證：售完的飲料或選項自動移除並提示
  - **Done when：** 登入前加的 cart，登入後還在
- [ ] **T1-5 Pricing + tax services**
  - `PricingService`：base price + 所有 option `price_delta` × 數量
  - `TaxCalculator`：所有飲料 GST 5%；`tax_category = carbonated` 另加 PST 7%
  - Rounding 規則：同一稅別的金額先加總，再四捨五入一次（寫進 decision log）
  - **Done when：** table-driven tests，至少 10 組，包含 0.5 cent 的邊界
- [ ] **T1-6 Checkout + OrderService**
  - `/checkout` 需登入；選 ASAP 或預約時段（15 分鐘一格，最早 = 現在 + cart 裡最長的 `prep_minutes`，且在點餐時間內）
  - `OrderService::place()`：在 DB transaction 裡 `lockForUpdate` 重新檢查售完狀態、重算金額、產生 `TA-1042` 格式訂單號、寫入 order + items + modifiers
  - Double-submit：按鈕 `wire:loading` disable + 每次 checkout 頁產生一個 idempotency token，同一個 token 只會建一張單
  - `PaymentGateway` interface；這個 stage 綁定 `FakePaymentGateway`
  - **Done when：** feature test 證明：竄改價格無效、售完的飲料下不了單、同一 token 送兩次只有一張單
- [ ] **T1-7 Receipt + order history**
  - `/orders/{number}` 收據：每杯飲料、選項、subtotal、GST、PST、total
  - `/orders` 歷史訂單；`OrderPolicy` 只允許本人看
  - **Done when：** test 證明 A 用戶用 B 的訂單號會拿到 403
- [ ] **T1-8 Sold-out toggle (86)**
  - Filament table 上的 toggle 欄位，飲料和 option 都有
  - 沒有 cache，所以切換後客人重新整理就看得到
  - **Done when：** 86 掉的飲料從菜單消失、不能加入 cart、checkout 時被擋

**Stage 1 demo checkpoint:** 手機點一杯客製飲料 → 結帳 → 收據上的稅金正確 → manager 86 掉一個 topping → 客人頁立刻選不到。

## Stage 2 — Real-time and payments (Nov 21–27)

**Goal:** 這是 wow factor 最大的 stage：客人下單後吧台螢幕即時跳單、客人手機即時看到狀態。先用 polling 做出能動的版本，再換成 Reverb，確保任何時候都能 demo。

- [ ] **T2-1 Order state machine**
  - `OrderStatus::canTransitionTo()` 定義合法轉換；recall 只允許 `ready → preparing`
  - 所有狀態改變都經過 `Order::transitionTo()`，不合法就丟 exception；記錄每個狀態的 timestamp
  - **Done when：** test 涵蓋所有合法和不合法的轉換
- [ ] **T2-2 Barista board (polling version)**
  - `/barista`：三欄 Paid / Preparing / Ready，先用 `wire:poll.5s`
  - Timer 從 paid 開始計時：超過 `prep_minutes` 變黃、超過 1.5 倍變紅
  - 按鈕：Start、Bump、Recall（只在 Ready 欄出現）
  - 平板橫向排版，字和按鈕要大
  - **Done when：** 兩個瀏覽器分頁，一邊下單、一邊 5 秒內出現
- [ ] **T2-3 Reverb upgrade**
  - 安裝 Reverb + Echo；`OrderPlaced`、`OrderStatusChanged` 兩個 broadcast event
  - Private channels：`barista` 只有 staff 能訂閱；`orders.{id}` 只有訂單本人能訂閱（寫在 `routes/channels.php`）
  - 保留 polling 當 fallback（Reverb 沒連上時自動退回）
  - **Done when：** 下單後吧台不用重新整理就跳單；channel 授權有 test
- [ ] **T2-4 Live order status page**
  - `/orders/{number}` 加上進度條：Received → Preparing → Ready，訂閱 `orders.{id}`
  - **Done when：** 吧台按 Bump，客人手機畫面立刻變 Ready
- [ ] **T2-5 Stripe Checkout**
  - `StripePaymentGateway` 取代 Fake：用 server 算好的金額建 Checkout Session，`stripe_session_id` 存進 order
  - Success page **不**標記 paid，只有 webhook 可以；webhook 驗證 signature
  - Webhook idempotent：order 已經是 paid 就直接回 200
  - 本機用 `stripe listen --forward-to` 轉發；scheduler 每 5 分鐘取消超過 30 分鐘還沒付款的訂單
  - 「Simulate payment」按鈕：只在 `local` 環境 + admin 才看得到，demo 備用
  - **Done when：** 用 test card 4242 付款，order 變 paid 並出現在吧台；同一個 webhook 送兩次不會出錯
- [ ] **T2-6 Order ready notification**
  - `OrderReady` notification：database channel（站內提示）+ mail channel（`MAIL_MAILER=log`）
  - **Done when：** Bump 後 `storage/logs` 看得到信件內容，客人頁有提示
- [ ] **T2-7 Promo codes**
  - Filament CRUD：percent 或 fixed amount、`expires_at`、`max_uses`
  - Checkout 套用：檢查過期、次數；discount 按比例分攤到每行再算稅
  - `used_count` 在 webhook 標記 paid 時才 +1，用 `lockForUpdate` 防止超用
  - **Done when：** 過期、用完、兩人同時用最後一次，都有 test
- [ ] **T2-8 Audit log**
  - Model observers 記錄 MenuItem、ModifierOption、PromoCode 的 update / delete：user、old / new values
  - Filament 唯讀 resource，只有 admin 能看
  - **Done when：** manager 改價格後，admin 看得到誰、何時、從多少改成多少
- [ ] **T2-9 Manager dashboard**
  - Filament widgets：今日營收（Vancouver 日期）、本週營收趨勢、best sellers、甜度分布、熱門 toppings
  - 全部讀 order snapshot，只算 paid 以後的訂單
  - 菜單頁的 popularity sort 改成近 30 天銷量
  - **Done when：** 數字和手算 seed 資料的結果一致

**Stage 2 demo checkpoint:** 同學用手機掃 tunnel 網址下單 → Stripe test card 付款 → 吧台平板即時跳單、計時變色 → Bump → 同學手機變 Ready。

## Stage 3 — QR, PWA, hardening (Nov 28–Dec 3)

**Goal:** 補完最後兩個功能，然後把時間花在「讓 demo 不會出錯」：權限掃一遍、錯誤頁、真實感的 demo 資料、tunnel 彩排。新功能在 Dec 1 前收手。

- [ ] **T3-1 Pickup QR**
  - Ready 的訂單頁顯示 QR，內容是 signed URL（不能用猜的）
  - `/barista/scan`：用相機掃（JS QR library），掃不到時可以手動輸入訂單號
  - 掃描後 `ready → picked_up`，只有 staff 能執行
  - **Done when：** 用另一支手機掃 QR 完成取餐；customer 自己打開那個 URL 會被擋
- [ ] **T3-2 PWA**
  - `manifest.json`、icons、最小的 service worker（只 cache 靜態檔 + 離線提示頁，不 cache 訂單資料）
  - **Done when：** iPhone / Android 都能「加到主畫面」，打開沒有網址列
- [ ] **T3-3 Security hardening pass**
  - Role × route 矩陣 test：每個 route 用 4 個 role + 未登入各跑一次
  - Rate limiting：login、register、checkout、promo code 驗證
  - 自訂 403 / 404 / 419 / 500 錯誤頁；demo 環境 `APP_DEBUG=false`
  - 檢查所有 Livewire component 的 public property 沒有被信任
  - **Done when：** 矩陣 test 全綠；Section 7 對照表每一行都寫上檔案路徑
- [ ] **T3-4 UX polish**
  - Empty states（空 cart、沒有歷史訂單）、loading states、成功 / 錯誤 toast
  - 手機實機測試；表單都有 label、對比足夠、鍵盤可操作
  - **Done when：** 用自己手機走完整個流程沒有卡住的地方
- [ ] **T3-5 Demo data seeder**
  - `DemoOrdersSeeder`：過去 14 天、合理分布的訂單（午餐和下午茶尖峰），讓 dashboard 有圖可看
  - 只在 `local` 跑，不影響 test 資料
  - **Done when：** `migrate:fresh --seed` 後 dashboard 每個 widget 都有資料
- [ ] **T3-6 Demo rehearsal**
  - 用 Herd Expose 或 ngrok 開 tunnel，在教室網路或手機熱點實測
  - 準備 2 台裝置：平板（吧台）+ 手機（客人）；錄一份完整 demo 影片當 backup
  - **Done when：** 從頭到尾跑一次 demo script，計時在 presentation 時間內

**Stretch（只有 T3-1 到 T3-6 全部完成才做）:** AI drink recommender、Passkey login。

## Stage 4 — Deliverables (Dec 1–4)

**Goal:** Dec 4 同一天交 report（20%）和做 final presentation（5%）。Report 佔分最重，不能等 code 寫完才開始：每個 stage 結束時順手寫該 stage 的段落，Dec 1 之後只做整合和潤稿。

**Report 內容（對應 Course Project Guide Deliverable 4）**

- [ ] Technical documentation：架構圖、request flow、ER diagram、data dictionary（12 張 table 每個欄位）
- [ ] Section 7 compliance 對照表，每項要求附上檔案路徑
- [ ] Security 章節：SQL injection、XSS、CSRF、password hashing、RBAC 各在哪裡、怎麼測
- [ ] User manual：4 個 role 各自的操作流程 + 截圖
- [ ] Simulated 功能清楚標示（Stripe test mode、log 信件）；AI 生成的飲料照片標註
- [ ] AI tools disclosure（設計 by Jacky、實作 by Claude Code under review）
- [ ] GitHub repo link + README setup 步驟

**Guide 要求但 Laravel 結構不同的地方**

- Guide 要 `database.sql`：用 `mysqldump --no-data` 匯出 schema，另一份含 seed 資料，放在 `database/sql/`
- Guide 的範例資料夾結構（`assets`、`includes`、`pages`…）和 Laravel 不同：report 裡放一張對照表說明 Laravel 的哪個資料夾對應哪個用途

**Presentation（10–15 分鐘，依 Guide 的順序）**

1. Problem overview：手搖茶店尖峰時段的痛點
2. System architecture：一張架構圖
3. Database design：ER diagram，重點講 modifier groups 和 price snapshot
4. Key features：drink builder、real-time board、secure checkout
5. Live demo：同學手機下單 → 吧台跳單 → Ready（影片 backup）
6. Challenges encountered
7. Lessons learned

- [ ] Slides 完成
- [ ] 完整彩排一次並計時
- [ ] 準備 Q&A：「security 在哪裡做的？」「為什麼不信任前端價格？」「recall 為什麼只能 ready → preparing？」

## Scope cuts and decisions to log

**落後時的砍功能順序**（由上往下砍，第 7 項是底線）：

1. Stretch：AI recommender、Passkey
2. PWA
3. QR 掃描 → 改成吧台上一個「Picked up」按鈕
4. Dashboard 只留今日營收 + best sellers
5. Reverb → 保留 polling 版本（功能一樣，只是延遲 5 秒）
6. Promo codes
7. **不能砍：** auth + roles、menu CRUD、drink builder、server 端算價、checkout、barista board、audit log 的 RBAC 部分

**這份 plan 新增、要寫進 Project Plan decision log 的決定**

| Decision | Reason |
| --- | --- |
| MySQL 8.4 via DBngin instead of XAMPP MariaDB | 和 plan、CI 一致；XAMPP 的 MariaDB 10.4 已 EOL |
| Herd on both Mac and Windows, no containers | Sprint 0 沒時間設定 Docker；lockfiles + migrations 已解決跨機器問題 |
| `PaymentGateway` interface, Fake first, Stripe in Stage 2 | Ordering flow 不被 Stripe 卡住 |
| Polling first, Reverb second | 任何時候都有能 demo 的 real-time 版本 |
| Tax rounded once per tax type per order | 避免每行四捨五入累積誤差 |
| Promo discount allocated across lines before tax | 折扣後的金額才是應稅金額 |
| Only the Stripe webhook marks orders paid | Success page 可以被直接打開，不能當付款證明 |
