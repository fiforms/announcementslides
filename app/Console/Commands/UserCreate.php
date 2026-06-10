<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class UserCreate extends Command
{
    protected $signature = 'user:create
                            {--name= : Full name}
                            {--email= : Email address}
                            {--password= : Password}
                            {--role=viewer : Role (admin, contributor, viewer)}';

    protected $description = 'Create a new user account';

    public function handle(): int
    {
        $name     = $this->option('name')     ?? $this->ask('Name');
        $email    = $this->option('email')    ?? $this->ask('Email');
        $password = $this->option('password') ?? $this->secret('Password');
        $role     = $this->option('role');

        if (! in_array($role, ['admin', 'contributor', 'viewer'])) {
            $role = $this->choice('Role', ['viewer', 'contributor', 'admin'], 0);
        }

        $validator = Validator::make(
            compact('name', 'email', 'password', 'role'),
            [
                'name'     => 'required|string|max:255',
                'email'    => 'required|email|unique:users,email',
                'password' => 'required|min:8',
                'role'     => 'required|in:admin,contributor,viewer',
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
