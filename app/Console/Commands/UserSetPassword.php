<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\PromptsForMissingInput;
use Illuminate\Support\Facades\Validator;

class UserSetPassword extends Command implements PromptsForMissingInput
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

        $password = $this->option('password');

        if ($password === null) {
            if (! $this->input->isInteractive()) {
                $this->error('The --password option is required when running non-interactively.');
                return self::FAILURE;
            }

            $password = $this->secret('New password');

            if ($password !== $this->secret('Confirm new password')) {
                $this->error('Passwords do not match.');
                return self::FAILURE;
            }
        }

        // Same rule user:create validates against, so both commands agree on
        // what a valid password is (min counts characters, not bytes).
        $validator = Validator::make(
            ['password' => $password],
            ['password' => 'required|string|min:8']
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }
            return self::FAILURE;
        }

        $user->update(['password' => $password]);

        $this->info("Password updated for {$user->name} <{$user->email}>.");
        return self::SUCCESS;
    }
}
