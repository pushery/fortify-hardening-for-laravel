---
name: fortify-hardening-for-laravel
description: >
  Install, configure and apply Fortify Hardening for Laravel, which keeps Laravel Fortify's
  registration, reset-link and password reset routes from revealing whether an email address has
  an account.
license: MIT
metadata:
  author: pushery
---

# Fortify Hardening for Laravel

Use this skill when a Laravel application that uses Laravel Fortify installs or integrates the
`pushery/fortify-hardening-for-laravel` package, or when it changes its registration or password
reset. The full reference is at <https://docs.pushery.com/fortify-hardening-for-laravel/>.

## Primary Goal

Every answer on the registration, reset-link and password reset routes reads the same, and takes
the same time, whether the address has an account or not.

## Workflow

### 1. Install

```bash
composer require pushery/fortify-hardening-for-laravel
```

The service provider is registered automatically through package discovery, and Fortify's own
routes answer neutrally from the next request on. Fortify's `registration` and `resetPasswords`
features must be enabled.

### 2. Configure

Every setting has a default and most have an environment variable, so publishing is optional:

```bash
php artisan vendor:publish --tag="fortify-hardening"
php artisan vendor:publish --tag="fortify-hardening-config"
php artisan vendor:publish --tag="fortify-hardening-lang"
```

The first publishes the configuration file and the translations together. Every option in
`config/fortify-hardening.php` is documented inline.

### 3. Apply the package

- Implement `MustVerifyEmail` on the user model, so a new account receives a mail just as the
  holder of a taken address receives the notice.
- Show `session('status')` on the page behind the route named `login`: a web registration is
  redirected there with the answer as its status.
- If the registration form takes more fields than Fortify's stub, extend
  `Pushery\FortifyHardening\Http\Requests\RegistrationRequest`, add their rules, and name the class
  in `fortify-hardening.registration.request`. Keep the rules the ones the `CreatesNewUsers` action
  checks, and never add a `unique` rule on the address.
- If the application queues the verification mail, extend
  `Pushery\FortifyHardening\Notifications\AccountAlreadyExistsNotification` with `ShouldQueue` and
  name it in `fortify-hardening.registration.notification`.
- If one installation serves several instances, or the application greets people by name, override
  `applicationName()` and `greeting()` in that subclass. Both receive the notifiable.
- If nobody signs in with a password, set
  `fortify-hardening.registration.notice_mentions_password_reset` to `false`, so the
  notice points at the sign-in page alone.
- When users register in a controller of the application's own, call
  `Pushery\FortifyHardening\Registration\Registrar::register()` instead of creating the user there.

## Examples

A registration controller of the application's own:

```php
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Pushery\FortifyHardening\Registration\Registrar;

public function store(RegisterRequest $request, Registrar $registrar): RedirectResponse
{
    $registrar->register(
        $request->string('email')->value(),
        $request->string('password')->value(),
        fn (string $hashedPassword): User => User::create([
            'name' => $request->string('name')->value(),
            'email' => $request->string('email')->value(),
            'password' => $hashedPassword,
        ]),
    );

    return redirect()->route('login')
        ->with('status', __('fortify-hardening::messages.registration.accepted'));
}
```

A test that proves the property for the reset-link route:

```php
use Illuminate\Testing\TestResponse;
use Pushery\FortifyHardening\Testing\AssertsEnumerationSafety;

uses(AssertsEnumerationSafety::class);

it('answers an unknown address like a known one', function () {
    User::factory()->create(['email' => 'known@example.com']);

    $this->assertEnumerationSafe(
        fn (string $email): TestResponse => $this->post('/forgot-password', ['email' => $email]),
        'known@example.com',
        'unknown@example.com',
    );
});
```

## Anti-Patterns

- A `unique` rule on the address in the registration form request: it answers the question the
  package keeps closed. The `CreatesNewUsers` action may keep its own, because it runs only for a
  free address.
- Signing a new user in after registration, or redirecting a new user anywhere else than a taken
  address goes: the difference tells the caller which case it was.
- A message, a status code or a redirect that differs between a free and a taken address,
  including in a custom `RegistrationAccepted` implementation.
- Sending the verification mail through the queue and the notice at once, or the other way round:
  the two answers then take different times.
- Setting `auth.timebox_duration` below the time one password hash takes on the production server.
