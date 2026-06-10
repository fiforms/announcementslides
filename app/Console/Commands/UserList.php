<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class UserList extends Command
{
    protected $signature = 'user:list {--role= : Filter by role (admin, contributor, viewer)}';

    protected $description = 'List all user accounts';

    public function handle(): int
    {
        $query = User::orderBy('role')->orderBy('name');

        if ($role = $this->option('role')) {
            $query->where('role', $role);
        }

        $users = $query->get(['id', 'name', 'email', 'role', 'google_id', 'created_at']);

        if ($users->isEmpty()) {
            $this->info('No users found.');
            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Name', 'Email', 'Role', 'Auth', 'Created'],
            $users->map(fn ($u) => [
                $u->id,
                $u->name,
                $u->email,
                $u->role,
                $u->google_id ? 'Google' : 'Password',
                $u->created_at->format('Y-m-d'),
            ])
        );

        $this->line('  Total: ' . $users->count());
        return self::SUCCESS;
    }
}
