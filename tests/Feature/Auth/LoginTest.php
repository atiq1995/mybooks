<?php

declare(strict_types=1);

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('renders the login page as an Inertia component', function (): void {
    $this->get('/login')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Auth/Login')
            ->where('canResetPassword', true)
            ->where('auth.user', null)
            ->where('organization', null),
        );
});

it('signs in with valid credentials and lands on the dashboard', function (): void {
    $user = User::factory()->create(['email' => 'ayesha@example.com']);

    $this->post('/login', [
        'email' => 'ayesha@example.com',
        'password' => 'password',
    ])->assertRedirect('/dashboard');

    $this->assertAuthenticatedAs($user);

    expect($user->fresh()?->last_login_at)->not->toBeNull();
});

it('rejects a wrong password and stays a guest', function (): void {
    User::factory()->create(['email' => 'ayesha@example.com']);

    $this->from('/login')->post('/login', [
        'email' => 'ayesha@example.com',
        'password' => 'not-the-password',
    ])->assertRedirect('/login')->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('rejects an unknown email with the same error as a wrong password', function (): void {
    // The response must not reveal whether the address exists.
    $this->from('/login')->post('/login', [
        'email' => 'nobody@example.com',
        'password' => 'password',
    ])->assertRedirect('/login')->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('refuses a suspended account', function (): void {
    User::factory()->suspended()->create(['email' => 'gone@example.com']);

    $this->from('/login')->post('/login', [
        'email' => 'gone@example.com',
        'password' => 'password',
    ])->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('is case-insensitive on email', function (): void {
    User::factory()->create(['email' => 'ayesha@example.com']);

    $this->post('/login', [
        'email' => 'AYESHA@Example.com',
        'password' => 'password',
    ])->assertRedirect('/dashboard');

    $this->assertAuthenticated();
});

it('redirects guests away from the application', function (): void {
    $this->get('/dashboard')->assertRedirect('/login');
    $this->get('/sales/invoices')->assertRedirect('/login');
});

it('signs out and invalidates the session', function (): void {
    $this->actingAs(User::factory()->create());

    $this->post('/logout')->assertRedirect('/');

    $this->assertGuest();
});
