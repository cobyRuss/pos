<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use App\Services\RolePermissionSyncer;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * The two accounts a fresh install needs to be usable at all.
 *
 * Without an admin there is no way to reach staff management, settings or
 * reports, so a seeded install that only creates an unprivileged user is a
 * dead end. The stock Laravel seeder did exactly that.
 *
 * Passwords come from the environment when set, and fall back to obvious
 * development defaults otherwise. The fallback is deliberate and loud rather
 * than hidden: a seeded database is a development convenience, and quietly
 * shipping a well-known production password would be the actual danger. The
 * credentials are printed on completion so nobody has to guess them.
 */
class UserSeeder extends Seeder
{
    public function run(RolePermissionSyncer $syncer): void
    {
        // Normally reconciled on MigrationsEnded, but `db:seed` can be run on
        // its own against an existing database, so make it explicit here.
        $syncer->sync();

        $accounts = [
            [
                'role' => Role::Admin,
                'name' => env('SEED_ADMIN_NAME', 'Shop Administrator'),
                'email' => env('SEED_ADMIN_EMAIL', 'admin@example.com'),
                'password' => env('SEED_ADMIN_PASSWORD'),
                'default' => 'password',
            ],
            [
                'role' => Role::Staff,
                'name' => env('SEED_STAFF_NAME', 'Front Counter'),
                'email' => env('SEED_STAFF_EMAIL', 'staff@example.com'),
                'password' => env('SEED_STAFF_PASSWORD'),
                'default' => 'password',
            ],
        ];

        $generated = false;
        $lines = [];

        foreach ($accounts as $account) {
            $password = $this->resolvePassword($account, $generated);

            $user = User::updateOrCreate(
                ['email' => $account['email']],
                [
                    'name' => $account['name'],
                    'password' => Hash::make($password),
                    'is_active' => true,
                ],
            );

            $user->syncRoles([$account['role']->value]);

            $lines[] = sprintf('  %-9s %-24s %s', $account['role']->value, $account['email'], $password);
        }

        $this->command?->newLine();

        if ($generated) {
            $this->command?->warn('Generated one-time passwords because SEED_*_PASSWORD was not set.');
            $this->command?->warn('Record these now. They are not stored anywhere and cannot be recovered.');
        } else {
            $this->command?->info('Seeded accounts (change these before any real use):');
        }

        foreach ($lines as $line) {
            $this->command?->line($line);
        }

        $this->command?->newLine();
    }

    /**
     * Decide the password for an account.
     *
     * On a local install the familiar `password` is convenient and harmless.
     * Anywhere else an unset SEED_*_PASSWORD produces a random one, because a
     * deployment that quietly inherits a well-known administrator password is
     * the actual disaster. `pos:health` fails while any default remains.
     *
     * @param  array{role: Role, name: string, email: string, password: ?string, default: string}  $account
     */
    private function resolvePassword(array $account, bool &$generated): string
    {
        if (filled($account['password'])) {
            return (string) $account['password'];
        }

        if (app()->environment('local', 'testing')) {
            return $account['default'];
        }

        $generated = true;

        return Str::password(24);
    }
}
