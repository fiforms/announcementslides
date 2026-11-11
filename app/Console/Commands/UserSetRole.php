<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\PromptsForMissingInput;

class UserSetRole extends Command implements PromptsForMissingInput
{
    /** Global roles, in menu order. Keep in sync with the users.role enum. */
    private const ROLES = ['viewer', 'contributor', 'admin', 'banned'];

    protected $signature = 'user:setrole
                            {email : Email address of the user}
                            {role? : New role (viewer, contributor, admin, banned)}';

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

        if ($role === null) {
            if (! $this->input->isInteractive()) {
                $this->error('A role argument is required when running non-interactively.');
                $this->line('Valid roles are: ' . implode(', ', self::ROLES));
                return self::FAILURE;
            }

            // array_search() returns false for a role that predates this list,
            // which would index $choices[0]; fall back to it explicitly instead.
            $current = array_search($user->role, self::ROLES, true);

            $role = $this->choice(
                "Role for {$user->name} (current: {$user->role})",
                self::ROLES,
                $current === false ? 0 : $current
            );
        } elseif (! in_array($role, self::ROLES, true)) {
            $this->error("Invalid role: {$role}");
            $this->line('Valid roles are: ' . implode(', ', self::ROLES));
            return self::FAILURE;
        }

        $user->update(['role' => $role]);

        $this->info("Role updated: {$user->name} <{$user->email}> is now [{$role}].");
        return self::SUCCESS;
    }
}
