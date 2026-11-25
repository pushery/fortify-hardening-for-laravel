<p align="center">
  <a href="https://github.com/pushery/fortify-hardening-for-laravel">
    <img src="https://raw.githubusercontent.com/pushery/fortify-hardening-for-laravel/main/art/header.png" alt="Fortify Hardening for Laravel" width="100%">
  </a>
</p>

# Fortify Hardening for Laravel

[![Latest Version](https://img.shields.io/packagist/v/pushery/fortify-hardening-for-laravel.svg)](https://packagist.org/packages/pushery/fortify-hardening-for-laravel)
[![PHP Version](https://img.shields.io/packagist/dependency-v/pushery/fortify-hardening-for-laravel/php.svg)](https://packagist.org/packages/pushery/fortify-hardening-for-laravel)
[![Laravel Versions](https://badge.laravel.cloud/badge/pushery/fortify-hardening-for-laravel?style=flat)](https://packagist.org/packages/pushery/fortify-hardening-for-laravel)
[![License](https://img.shields.io/packagist/l/pushery/fortify-hardening-for-laravel.svg)](LICENSE)

[![Tests: Pest 5](https://img.shields.io/badge/tests-Pest%205-8BC34A.svg)](https://pestphp.com)
![Coverage: 100%](https://img.shields.io/badge/coverage-100%25-brightgreen.svg)
![Type coverage: 100%](https://img.shields.io/badge/types-100%25-brightgreen.svg)
[![PHPStan: level 10](https://img.shields.io/badge/PHPStan-10-blue.svg)](https://phpstan.org)
[![Code style: Pint](https://img.shields.io/badge/code%20style-pint-orange.svg)](https://laravel.com/docs/pint)

Stops account enumeration in Laravel Fortify: neutral answers on registration and password reset, equal timing, and a notice to the owner.

## Documentation

**Full documentation at
[docs.pushery.com/fortify-hardening-for-laravel](https://docs.pushery.com/fortify-hardening-for-laravel/).**
This README is the quick start; the guides and the configuration reference live there.

## Requirements

- PHP 8.4+ (tested on 8.4.27, 8.5.x and 8.6.x)
- Laravel 13+
- Laravel Fortify 1.34.1+, with its `registration` and `resetPasswords` features

Tested on PostgreSQL 18.7 and MySQL 8.4.13, the databases Laravel Cloud runs (serverless Postgres
and MySQL 8.4 LTS). The fast suite runs on SQLite.

Tested on the floor of the declared PHP range, because a `>=` promise is only proven
by running on the boundary, and on the minor version above it, so the range above the
floor is exercised here rather than first by a consumer.

## Installation

```bash
composer require pushery/fortify-hardening-for-laravel
```

The service provider is registered automatically through package discovery.

## What it changes

Fortify tells anyone who asks whether an email address has an account: its registration form
refuses a taken address, its reset-link form refuses an unknown one, and a password reset names the
difference too. With the package installed, Fortify's own routes answer every case alike.

| Where | Fortify on its own | With the package |
|---|---|---|
| Registration | a taken address is refused, a free one is signed in | both read "check your inbox", nobody is signed in, the holder of a taken address gets a notice |
| Reset link | an unknown address is refused, a recent one is told to wait | every request reads like a link that was sent |
| Password reset | an unknown address and a wrong token give different errors | every failure reads like a wrong token |
| Timing | a taken address answers sooner, a known one takes longer on the password routes | both answers take the same time |
| Sign-in limiter | failed sign-ins are counted under the address and the full IP as they are, so every address of an IPv6 network starts a count of its own, and again by the `login` limiter of your service provider under an MD5 a guess can compute | Fortify's count under a key hashed with the application key, an IPv6 client counted by its /64, and `LoginThrottleKey::of()` to key the `login` limiter the same way |

Nothing needs to be wired: the package puts its answers in place of Fortify's through the
container. Give new accounts a verification mail (`MustVerifyEmail` on the user model) and show
`session('status')` on the sign-in page, and the registration reads the same for everybody. The
[installation guide](https://docs.pushery.com/fortify-hardening-for-laravel/installation) has the
details, and every setting is listed in the
[configuration reference](https://docs.pushery.com/fortify-hardening-for-laravel/configuration).

## Usage

On Fortify's route there is nothing to call. An application that registers users in a controller of
its own uses the registrar, which looks the address up, creates the account or sends the notice,
and signs nobody in:

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

The [registration guide](https://docs.pushery.com/fortify-hardening-for-laravel/registration)
covers the form request, the notice to the holder and its mail budget.

The mail budget counts the inbox an address reaches, not its spelling. An application that counts
mails of its own can use the same key:

```php
use Pushery\FortifyHardening\Support\Mailbox;

$key = 'contact-form:'.hash_hmac('sha256', Mailbox::of($request->string('email')->value()), config('app.key'));
```

`Mailbox::of()` trims the address and writes it in lower case, drops a `+tag` in the local part, and
for Gmail drops the dots in the local part and reads `googlemail.com` as `gmail.com`. It is a key to
count under, not an identity: an account keeps its address as it was typed. Hash it with the
application key, as above and as the package does: an error tracker or a store's monitor records
cache keys, and a plain hash of an address can be matched against a guessed one.

To prove the property in your own suite, use the assertion the package ships:

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

See [Testing your application](https://docs.pushery.com/fortify-hardening-for-laravel/testing) for
registration and password reset examples.

## Testing

```bash
composer test
```

The PostgreSQL and MySQL suites need a reachable server and **skip** without one, so
a green run on a bare checkout has not exercised them, and the summary counts each of
their cases as skipped. Point them at a server with the `PG_TEST_*` / `MYSQL_TEST_*` variables
(defaults: `127.0.0.1`, ports 5432 and 3308, users `postgres` / `root`), and set
`REQUIRE_DB_TESTS=1` to turn an unreachable server into a failure instead of a skip.

```bash
REQUIRE_DB_TESTS=1 composer test
```

## Security

Please review the [security policy](SECURITY.md) and report vulnerabilities
privately rather than opening a public issue.

## Built by PUSHERY

This package is built and maintained by [PUSHERY](https://www.pushery.com) — a
Berlin-based studio building Laravel applications, SaaS products, and open-source
tools.

Building a Laravel UI? [WireKit](https://www.wirekit.app) is PUSHERY's open-source
Livewire component kit.
Browse the rest of our work at [pushery.com](https://www.pushery.com).

## License

The MIT License (MIT). See [LICENSE](LICENSE) for details.
