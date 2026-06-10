<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class UserSetPassword extends Command
{
    protected $signature = 'user:setpassword
                            {email : Email address of the user}
                            {--password= : New password (prompted if omitted)}';

    protected $description = 'Set a new password for an existing user';

    public function handle(): int
    {
        $email = $this->argument('email');
        $user  = User::where('email', $email)->first();

        if (! $user) {
            $this->error("No user found with email: {$email}");
            return self::FAILURE;
        }

        $password = $this->option('password') ?? $this->secret('New password');

        if (strlen($password) < 8) {
            $this->error('Password must be at least 8 characters.');
            return self::FAILURE;
        }

        $user->update(['password' => $password]);

        $this->info("Password updated for {$user->name} <{$user->email}>.");
        return self::SUCCESS;
    }
}
