# Tea Alley — project conventions

## Stack
- Laravel 13, PHP 8.4 (Herd), MySQL 8.4 (DBngin)
- Livewire 4 starter kit + Flux UI, Fortify auth
- Pest for tests, spatie/laravel-permission for roles
- Filament arrives in T0-4 (not installed yet)
- Developed on both Windows and Mac: keep scripts and paths cross-platform

## Roles
`customer`, `barista`, `manager`, `admin`. Demo accounts (password `password`) are listed in README.md.

## Workflow
- One ticket = one branch = one PR.
- Branch names: `feature/T0-4-xxx`, `fix/...`, `chore/...`
- Conventional Commits: `feat:`, `fix:`, `test:`, `docs:`, `chore:`, `ci:`
- Never add `Co-Authored-By` or `Claude-Session` lines.
- Never commit unless asked. Never push to `main` directly.

## Definition of done
Run all three, and all must pass before saying a task is done:

```bash
vendor/bin/pint --test
vendor/bin/phpstan analyse
php artisan test
```

If anything fails, stop and report it.
- Don't change tests just to make them pass.
- Don't silence PHPStan with `(string)` casts or ignore comments: fix the type.

## Data rules
- Money is `unsignedInteger` cents. Format it only in views.
- Store times in UTC and display them in `America/Vancouver`.
- Orders snapshot item names and prices at order time.
- Never edit a migration that's already merged into `main`. Add a new one.
- Use `config()` in app code. `env()` belongs only in `config/`.

## Security rules
- Every new route needs auth/role middleware or a policy, plus a test that the wrong role is blocked.
- Validate all input server-side.
- Never trust Livewire public properties for prices or IDs: re-query and re-authorize on the server.
- No `{!! !!}` for user data.
- No `DB::raw()` with user input.
- Restrict uploads by MIME type and size, and give them random filenames.

## Performance
- Eager load relationships.
- Strict mode is on outside production (`Model::shouldBeStrict`). Lazy loading and reading attributes that weren't loaded both throw exceptions.

## Testing
- Local tests use in-memory SQLite (`phpunit.xml`).
- CI uses MySQL 8.4: the workflow's `DB_*` env overrides `phpunit.xml`.
- Tests must pass on both.

## Known environment issue
Norton antivirus has quarantined project files before, including `phpstan.neon` (a false positive, IDP.Generic). If a file disappears or can't be recreated (Permission denied / EPERM), tell Jacky to check Norton Quarantine. Don't try to work around it.
