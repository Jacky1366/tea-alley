# Tea Alley

Bubble tea shop ordering app built with Laravel, Livewire, and Flux.

## Local setup

```bash
composer setup
php artisan migrate:fresh --seed
```

## Demo accounts

Seeded by `DemoUserSeeder` (never in production). All accounts are email-verified.

| Email | Password | Role |
|---|---|---|
| customer@tea-alley.test | password | customer |
| barista@tea-alley.test | password | barista |
| manager@tea-alley.test | password | manager |
| admin@tea-alley.test | password | admin |
