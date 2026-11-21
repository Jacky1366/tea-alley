<?php

namespace App\Console\Commands;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Enums\Locale;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Throwable;

/**
 * #67: staff accounts in production, where DemoUserSeeder never runs.
 *
 *     php artisan tea-alley:create-staff barista@example.com barista --name="Amy"
 *
 * By default the account gets a long random password that nobody ever sees, and the person is
 * emailed a password-reset link to choose their own. No password is typed, shown, or stored
 * anywhere: not in shell history, a Cloud console transcript, or a log. So it also works as a
 * non-interactive Cloud command. The address needs a real inbox.
 *
 * --prompt asks for the password instead, with hidden input, and sends no email (local use).
 *
 * Either way: the same password rules as registration (8+ characters, not in a known breach),
 * the email is marked verified, only barista / manager / admin, and an existing email is
 * refused (this never changes an existing account).
 */
class CreateStaff extends Command
{
    use PasswordValidationRules, ProfileValidationRules;

    public const ROLES = ['barista', 'manager', 'admin'];

    protected $signature = 'tea-alley:create-staff
        {email : The staff member\'s email address (a real inbox, for the reset link)}
        {role : barista, manager, or admin}
        {--name= : Their name (default: the role, e.g. "Barista")}
        {--prompt : Type the password (hidden) instead of emailing a reset link}';

    protected $description = 'Create a verified staff account (barista, manager, or admin)';

    public function handle(): int
    {
        $role = Str::lower((string) $this->argument('role'));
        $email = Str::lower(trim((string) $this->argument('email')));
        $name = trim((string) ($this->option('name') ?? '')) ?: Str::ucfirst($role);

        if (! in_array($role, self::ROLES, true)) {
            $this->error('The role must be one of: '.implode(', ', self::ROLES).'. Customers register on the site.');

            return self::FAILURE;
        }

        $profile = Validator::make(['name' => $name, 'email' => $email], $this->profileRules());

        if ($profile->fails()) {
            return $this->failWith($profile->errors()->all());
        }

        $prompted = (bool) $this->option('prompt');
        $password = $prompted ? $this->askForPassword() : Str::password(40);

        if ($password === null) {
            return self::FAILURE;
        }

        $user = DB::transaction(function () use ($name, $email, $password, $role): User {
            $user = User::create([
                'name' => $name,
                'email' => $email,
                'password' => $password,
                'locale' => Locale::English->value,
            ]);

            $user->forceFill(['email_verified_at' => now()])->save();
            $user->assignRole(Role::findOrCreate($role));

            return $user;
        });

        $this->info("Created {$role} account {$user->email}.");

        if ($prompted) {
            return self::SUCCESS;
        }

        return $this->sendResetLink($user);
    }

    /**
     * Hidden input, asked twice, checked with the registration rules. Null when it fails.
     */
    private function askForPassword(): ?string
    {
        $password = (string) $this->secret('Password (hidden)');
        $confirmation = (string) $this->secret('Password again');

        $validator = Validator::make(
            ['password' => $password, 'password_confirmation' => $confirmation],
            ['password' => $this->passwordRules()],
        );

        if ($validator->fails()) {
            $this->failWith($validator->errors()->all());

            return null;
        }

        return $password;
    }

    /**
     * The same reset link "Forgot password?" sends; it expires after
     * config('auth.passwords.users.expire') minutes.
     */
    private function sendResetLink(User $user): int
    {
        try {
            $status = Password::broker()->sendResetLink(['email' => $user->email]);
        } catch (Throwable $exception) {
            report($exception);
            $status = null;
        }

        if ($status === Password::RESET_LINK_SENT) {
            $this->info("Emailed {$user->email} a link to choose a password.");

            return self::SUCCESS;
        }

        $this->warn("The account exists, but the reset email wasn't sent. They can use \"Forgot password?\" on the login page with {$user->email}.");

        return self::FAILURE;
    }

    /**
     * @param  array<int, string>  $messages
     */
    private function failWith(array $messages): int
    {
        foreach ($messages as $message) {
            $this->error($message);
        }

        return self::FAILURE;
    }
}
