<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * T3-5: 20 spare customer accounts for the demo, customer01@tea-alley.test ... customer20,
 * password "password", email verified. Classmates are meant to register with their own
 * Gmail; these are for anyone whose verification email doesn't arrive. DemoOrdersSeeder
 * gives them their order history. Half English, half Traditional Chinese.
 *
 * Like DemoUserSeeder, never in production. Safe to run again: accounts are updated in place.
 */
class DemoCustomersSeeder extends Seeder
{
    public const COUNT = 20;

    /** Display names, one per account, in order. */
    private const NAMES = [
        'Mei Chen', 'Jason Lin', 'Ava Wong', 'Ethan Huang', 'Chloe Wu',
        'Ryan Tsai', 'Sophie Lee', 'Kevin Chang', 'Grace Liu', 'Daniel Ho',
        'Emily Kuo', 'Brian Yang', 'Olivia Chou', 'Marcus Hsu', 'Hannah Lai',
        'Leo Cheng', 'Ivy Su', 'Nathan Wang', 'Zoe Tseng', 'Oscar Lu',
    ];

    /**
     * customer01@tea-alley.test ... customer20@tea-alley.test, in order.
     *
     * @return list<string>
     */
    public static function emails(): array
    {
        return array_map(
            fn (int $n): string => sprintf('customer%02d@tea-alley.test', $n),
            range(1, self::COUNT),
        );
    }

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->warn('DemoCustomersSeeder skipped: it never runs in production.');

            return;
        }

        foreach (self::emails() as $i => $email) {
            $user = User::updateOrCreate(
                ['email' => $email],
                ['name' => self::NAMES[$i], 'password' => 'password'],
            );

            // Odd numbers English, even numbers Traditional Chinese.
            $user->forceFill([
                'email_verified_at' => $user->email_verified_at ?? now(),
                'locale' => $i % 2 === 0 ? 'en' : 'zh_TW',
            ])->save();

            $user->syncRoles(['customer']);
        }
    }
}
