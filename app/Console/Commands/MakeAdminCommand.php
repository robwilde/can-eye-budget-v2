<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

final class MakeAdminCommand extends Command
{
    protected $signature = 'app:make-admin {email : The email of an existing user} {--revoke : Remove the admin flag instead}';

    protected $description = 'Grant or revoke the instance admin flag for a user';

    public function handle(): int
    {
        $user = User::query()->where('email', (string) $this->argument('email'))->first();

        if ($user === null) {
            $this->error('No user has that email.');

            return self::FAILURE;
        }

        $user->forceFill(['is_admin' => ! $this->option('revoke')])->save();

        $this->info($user->is_admin ? 'User is now an admin.' : 'User is no longer an admin.');

        return self::SUCCESS;
    }
}
