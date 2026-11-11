<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class UserCreate extends Command
{
    /** Global roles, in menu order. Keep in sync with the users.role enum. */
    private const ROLES = ['viewer', 'contributor', 'admin', 'banned'];

    protected $signature = 'user:create
                            {--name= : Full name}
                            {--email= : Email address}
                            {--password= : Password}
                            {--role= : Role (viewer, contributor, admin, banned); prompted if omitted}';

    protected $description = 'Create a new user account';

    public function handle(): int
    {
        $name     = $this->option('name')     ?? $this->ask('Name');
        $email    = $this->option('email')    ?? $this->ask('Email');
        $password = $this->option('password') ?? $this->secret('Password');
        $role     = $this->option('role');

        if ($role === null) {
            // No default on --role, so an omitted role is prompted for rather
            // than silently becoming a viewer. Non-interactively it stays null
            // and the validator reports it with any other missing field.
            if ($this->input->isInteractive()) {
                $role = $this->choice('Role', self::ROLES, 0);
            }
        } elseif (! in_array($role, self::ROLES, true)) {
            $this->error("Invalid role: {$role}");
            $this->line('Valid roles are: ' . implode(', ', self::ROLES));
            return self::FAILURE;
        }

        $validator = Validator::make(
            compact('name', 'email', 'password', 'role'),
            [
                'name'     => 'required|string|max:255',
                'email'    => 'required|email|unique:users,email',
                'password' => 'required|min:8',
                'role'     => 'required|in:' . implode(',', self::ROLES),
            ]
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }
            return self::FAILURE;
        }

        $user = User::create([
            'name'     => $name,
            'email'    => $email,
            'password' => $password,
            'role'     => $role,
        ]);

        $this->info("User created: {$user->name} <{$user->email}> [{$user->role}]");
        return self::SUCCESS;
    }
}
