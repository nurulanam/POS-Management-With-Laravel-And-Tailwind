<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class CreateAdmin extends Command
{
    protected $signature = 'pos:create-admin';

    protected $description = 'Securely create an administrator without default or command-line passwords';

    public function handle(): int
    {
        $data = [
            'name' => $this->ask('Administrator name'),
            'email' => strtolower(trim((string) $this->ask('Email address'))),
            'password' => $this->secret('Password (at least 12 characters, with letters and numbers)'),
            'password_confirmation' => $this->secret('Confirm password'),
        ];
        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(12)->letters()->numbers(), 'max:72'],
        ]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }
        $user = new User;
        $user->name = $data['name'];
        $user->email = $data['email'];
        $user->password = $data['password'];
        $user->role = UserRole::Admin;
        $user->is_active = true;
        $user->save();
        $this->info('Administrator created. Sign in and create your first branch.');

        return self::SUCCESS;
    }
}
