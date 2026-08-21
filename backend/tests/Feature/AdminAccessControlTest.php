<?php

use App\Models\User;

test('normal user cannot access filament admin panel', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get('/admin');

    $response->assertForbidden();
});

test('admin user can access filament admin panel', function () {
    $user = User::factory()->create();

    $user->forceFill([
        'is_admin' => true,
    ])->save();

    $response = $this
        ->actingAs($user)
        ->get('/admin');

    $response->assertSuccessful();
});

test('new users are not administrators by default', function () {
    $user = User::factory()->create();

    expect($user->is_admin)->toBeFalse();
});
