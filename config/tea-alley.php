<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Tax rates
    |--------------------------------------------------------------------------
    |
    | Basis points (1 bp = 0.01%), so rates stay integers and no float maths is
    | needed: 500 = 5%. GST applies to everything; BC PST applies only to
    | carbonated drinks. Read by App\Services\TaxCalculator.
    |
    */

    'gst_bp' => 500,

    'pst_bp' => 700,

    /*
    |--------------------------------------------------------------------------
    | Trusted proxies
    |--------------------------------------------------------------------------
    |
    | Who may tell the app the visitor's IP and scheme (X-Forwarded-*). A comma
    | list of IPs / CIDR ranges, or REMOTE_ADDR for "the direct peer, whoever it
    | is". Read by App\Support\TrustedProxies. See docs/cloud-deploy.md.
    |
    | Default: loopback only (local dev, and the demo tunnel, whose client runs on
    | this machine). On Laravel Cloud: REMOTE_ADDR. The app is reachable only
    | through Cloud's edge, so the direct peer is always Cloud's proxy, and
    | request()->ip() is the address that proxy appended to X-Forwarded-For.
    |
    | Never "*" or "**": Laravel turns both into "trust every address", and then
    | Symfony returns the LEFT-most X-Forwarded-For entry, which the visitor
    | writes. A visitor could pick a new IP per request and dodge the per-IP rate
    | limits (tests/Feature/Security/TrustedProxiesTest.php shows it).
    |
    */

    'trusted_proxies' => array_values(array_filter(
        array_map(trim(...), explode(',', (string) env('TRUSTED_PROXIES', '127.0.0.1,::1'))),
        fn (string $proxy): bool => $proxy !== '',
    )),

    /*
    |--------------------------------------------------------------------------
    | Drink photo disk
    |--------------------------------------------------------------------------
    |
    | The filesystem disk every drink photo is read from and written to: the
    | /admin upload and table, the menu and drink pages, and MenuPhotosSeeder.
    | Locally the "public" disk (storage/app/public, linked into public/). On
    | Laravel Cloud, the name given to the attached object-storage bucket; Cloud
    | defines that disk itself. Kept separate from FILESYSTEM_DISK on purpose, so
    | Livewire's temporary uploads stay on the local disk.
    |
    */

    'menu_photo_disk' => env('MENU_PHOTO_DISK', 'public'),

    /*
    |--------------------------------------------------------------------------
    | Demo data in production
    |--------------------------------------------------------------------------
    |
    | DemoCustomersSeeder and DemoOrdersSeeder refuse to run in production unless
    | this is true (TEA_ALLEY_DEMO_DATA=true): the Laravel Cloud demo environment
    | opts in, a real shop never would. DemoOrdersSeeder deletes and regenerates
    | the 20 spare accounts' orders, so it must never run by accident. Staff
    | accounts are never seeded in production: php artisan tea-alley:create-staff.
    |
    */

    'demo_data' => (bool) env('TEA_ALLEY_DEMO_DATA', false),

];
