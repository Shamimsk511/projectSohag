<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Services\UserService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

class CreateAdmin extends Command
{
    /**
     * @var string
     */
    protected $signature = 'app:create-admin';

    /**
     * @var string
     */
    protected $description = 'Create an admin login (use once to bootstrap; manage other staff from /admin/users)';

    public function handle(UserService $users): int
    {
        $input = [
            'name' => text('Name', required: true),
            'email' => text('Email', required: true),
            'password' => password('Password', required: true),
        ];

        $validator = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', Password::defaults()],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $users->create([...$validator->validated(), 'status' => UserStatus::Active->value], Role::Admin);
        $this->info("Admin {$input['email']} created.");

        return self::SUCCESS;
    }
}
