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

Every setting has a default, so publishing is optional. The `enabled` switches, the mail budget and
the minimum duration also read an environment variable; to change any other setting, publish the
configuration file:

```bash
php artisan vendor:publish --tag="fortify-hardening"
php artisan vendor:publish --tag="fortify-hardening-config"
php artisan vendor:publish --tag="fortify-hardening-lang"
```

The first publishes the configuration file and the translations together. Every option in
`config/fortify-hardening.php` is documented inline.

The mail budget counts in the cache store Laravel's rate limiter uses, and the note that holds back
the verification mail of an account created over the budget lives there too. Name a store of its
own in `cache.limiter` that loses neither before its hour: a database store with a table of its
own, the DynamoDB store, or a Redis connection and database no other store uses, with
`maxmemory-policy noeviction` and `appendonly yes`. Leave the default store to the application's
cache, because `cache:clear` and `optimize:clear`, which a deploy often runs, flush it. A Redis
under `noeviction` that has filled up refuses every write instead, so give it the memory one hour
of counts needs.

On a database store, schedule the pruning of the package's expired keys once a day, in
`routes/console.php`, or the rows of past keys stay for good:

```php
use Illuminate\Support\Facades\Schedule;
use Pushery\FortifyHardening\Support\PruneExpiredCacheKeys;

Schedule::call(PruneExpiredCacheKeys::class)->daily();
```

### 3. Apply the package

- Implement `MustVerifyEmail` on the user model, so a new account receives a mail just as the
  holder of a taken address receives the notice.
- Show `session('status')` on the page behind the route named `login`: a web registration is
  redirected there with the answer as its status.
- If the registration form takes more fields than Fortify's stub, extend
  `Pushery\FortifyHardening\Http\Requests\RegistrationRequest`, add their rules, and name the class
  in `fortify-hardening.registration.request`. Keep the rules the ones the `CreatesNewUsers` action
  checks, keep the rule that refuses a carriage return and a line feed in the address, and never
  add a `unique` rule on the address.
- If the application queues the verification mail, extend
  `Pushery\FortifyHardening\Notifications\AccountAlreadyExistsNotification` with `ShouldQueue` and
  name it in `fortify-hardening.registration.notification`.
- If the application sends a verification notification of its own, extend
  `Illuminate\Auth\Notifications\VerifyEmail`: over the mail budget the package holds back that class
  and its subclasses, as it holds back the notice.
- If one installation serves several instances, or the application greets people by name, override
  `applicationName()` and `greeting()` in that subclass. Both receive the notifiable.
- If nobody signs in with a password, set
  `fortify-hardening.registration.notice_mentions_password_reset` to `false`, so the
  notice points at the sign-in page alone.
- When users register in a controller of the application's own, call
  `Pushery\FortifyHardening\Registration\Registrar::register()` instead of creating the user there.
- If people sign in with a username of their own, so that `fortify.username` names another field
  than `fortify.email`, the package leaves Fortify's registration in place. Register from a
  controller of the application's own with the registrar, which looks the address up under
  `fortify.email`, and answer for the username before that, the same way for a free and a taken
  address.
- Hash the key of the `login` rate limiter that Fortify's installer writes into
  `app/Providers/FortifyServiceProvider.php` with
  `Pushery\FortifyHardening\Support\LoginThrottleKey::hash()`. Laravel keeps the count of that
  limiter under an MD5 of its name and its key, which a guessed address and IP compute; the package
  hashes the key of Fortify's own `LoginRateLimiter` and leaves the application's limiter alone.

## Examples

A registration controller of the application's own:

```php
use App\Http\Requests\RegisterRequest;
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

The `login` limiter in `app/Providers/FortifyServiceProvider.php`, its key hashed:

```php
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;
use Pushery\FortifyHardening\Support\LoginThrottleKey;

RateLimiter::for('login', function (Request $request) {
    $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

    return Limit::perMinute(5)->by(LoginThrottleKey::hash($throttleKey));
});
```

A test that proves the property for the reset-link route:

```php
use App\Models\User;
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
- Setting `auth.timebox_duration` below the time one password hash, or one registration of a new
  account, takes on the production server: the registration runs inside that minimum, and work past
  it shows in the time of the answer.
- Keeping the mail budget in a store that loses keys before their hour: a Redis whose
  `maxmemory-policy` is anything but `noeviction` or that runs without `appendonly yes`, Memcached,
  or the default store, which `cache:clear` and `optimize:clear` flush. A lost count lets the next
  registration mail the address again within the hour.
- Running a database cache store without the daily `PruneExpiredCacheKeys`: the store deletes an
  expired row only when its key is read again, and the package's keys of past registrations,
  resets and failed sign-ins are never read again.
