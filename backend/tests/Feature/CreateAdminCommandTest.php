<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_admin_command_creates_an_administrator(): void
    {
        $this->artisan(
            'app:create-admin',
            [
                '--name' =>
                    'Xurvexa Admin',

                '--email' =>
                    'admin@example.com',
            ]
        )
            ->expectsQuestion(
                'Administrator password',
                'StrongPassword!123'
            )
            ->expectsQuestion(
                'Confirm administrator password',
                'StrongPassword!123'
            )
            ->expectsOutputToContain(
                'Administrator created successfully: admin@example.com'
            )
            ->assertExitCode(0);

        $user =
            User::query()
                ->where(
                    'email',
                    'admin@example.com'
                )
                ->firstOrFail();

        $this->assertTrue(
            $user->is_admin
        );

        $this->assertTrue(
            Hash::check(
                'StrongPassword!123',
                $user->password
            )
        );
    }

    public function test_create_admin_command_rejects_duplicate_email(): void
    {
        User::factory()->create([
            'email' =>
                'admin@example.com',
        ]);

        $this->artisan(
            'app:create-admin',
            [
                '--name' =>
                    'Second Admin',

                '--email' =>
                    'admin@example.com',
            ]
        )
            ->expectsQuestion(
                'Administrator password',
                'StrongPassword!123'
            )
            ->expectsQuestion(
                'Confirm administrator password',
                'StrongPassword!123'
            )
            ->expectsOutputToContain(
                'Administrator could not be created.'
            )
            ->assertExitCode(1);

        $this->assertSame(
            1,
            User::query()
                ->where(
                    'email',
                    'admin@example.com'
                )
                ->count()
        );
    }

    public function test_create_admin_command_rejects_weak_password(): void
    {
        $this->artisan(
            'app:create-admin',
            [
                '--name' =>
                    'Xurvexa Admin',

                '--email' =>
                    'admin@example.com',
            ]
        )
            ->expectsQuestion(
                'Administrator password',
                'password'
            )
            ->expectsQuestion(
                'Confirm administrator password',
                'password'
            )
            ->expectsOutputToContain(
                'Administrator could not be created.'
            )
            ->assertExitCode(1);

        $this->assertDatabaseMissing(
            'users',
            [
                'email' =>
                    'admin@example.com',
            ]
        );
    }
}
