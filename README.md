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
npm run build
```

Herd serves the app at http://tea-alley.test. Use `npm run dev` instead of `npm run build` while working on the frontend.

## Demo accounts

Seeded by `DemoUserSeeder` (never in production). All accounts are email-verified.

| Email | Password | Role |
|---|---|---|
| customer@tea-alley.test | password | customer |
| barista@tea-alley.test | password | barista |
| manager@tea-alley.test | password | manager |
| admin@tea-alley.test | password | admin |
