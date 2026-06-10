<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class UserSetRole extends Command
{
    protected $signature = 'user:setrole
                            {email : Email address of the user}
                            {role? : New role (admin, contributor, viewer)}';

    protected $description = 'Set the role for an existing user';

    public function handle(): int
    {
        $email = $this->argument('email');
        $user  = User::where('email', $email)->first();

        if (! $user) {
            $this->error("No user found with email: {$email}");
            return self::FAILURE;
        }

        $role = $this->argument('role');

        if (! $role || ! in_array($role, ['admin', 'contributor', 'viewer'])) {
            $role = $this->choice(
                "Role for {$user->name} (current: {$user->role})",
                ['viewer', 'contributor', 'admin'],
                array_search($user->role, ['viewer', 'contributor', 'admin'])
            );
        }

        $user->update(['role' => $role]);

        $this->info("Role updated: {$user->name} <{$user->email}> is now [{$role}].");
        return self::SUCCESS;
    }
}
