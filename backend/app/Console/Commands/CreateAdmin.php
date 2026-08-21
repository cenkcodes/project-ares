<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class CreateAdmin extends Command
{
    /**
     * The name and signature of the console command.
     *
     * Password is intentionally not accepted as a command-line option
     * so that it is not stored in shell history.
     *
     * @var string
     */
    protected $signature = 'app:create-admin
                            {--name= : Administrator name}
                            {--email= : Administrator email address}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a Xurvexa administrator with Filament panel access';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $name = trim(
            (string) (
                $this->option('name')
                ?: $this->ask('Administrator name')
            )
        );

        $email = strtolower(
            trim(
                (string) (
                    $this->option('email')
                    ?: $this->ask('Administrator email address')
                )
            )
        );

        $password = (string) $this->secret(
            'Administrator password'
        );

        $passwordConfirmation = (string) $this->secret(
            'Confirm administrator password'
        );

        $validator = Validator::make(
            [
                'name' => $name,
                'email' => $email,
                'password' => $password,
                'password_confirmation' => $passwordConfirmation,
            ],
            [
                'name' => [
                    'required',
                    'string',
                    'max:255',
                ],
                'email' => [
                    'required',
                    'string',
                    'lowercase',
                    'email',
                    'max:255',
                    Rule::unique('users', 'email'),
                ],
                'password' => [
                    'required',
                    'string',
                    'same:password_confirmation',
                    Password::min(12)
                        ->letters()
                        ->mixedCase()
                        ->numbers()
                        ->symbols(),
                ],
            ]
        );

        if ($validator->fails()) {
            $this->error(
                'Administrator could not be created.'
            );

            foreach (
                $validator->errors()->all()
                as $message
            ) {
                $this->line(
                    "- {$message}"
                );
            }

            return self::FAILURE;
        }

        $user = DB::transaction(
            function () use (
                $name,
                $email,
                $password
            ): User {
                $user = User::query()->create([
                    'name' => $name,
                    'email' => $email,
                    'password' => $password,
                ]);

                $user->forceFill([
                    'is_admin' => true,
                ])->save();

                return $user->refresh();
            }
        );

        $this->info(
            "Administrator created successfully: {$user->email}"
        );

        $this->line(
            'Filament panel access is enabled through is_admin=true.'
        );

        return self::SUCCESS;
    }
}
