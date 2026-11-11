# Changelog

All notable changes to `pushery/fortify-hardening-for-laravel` are documented here. The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/), and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

A release carries only the sections that have an entry, in this order: `### ⚠️ Breaking`, `### ✨ Added`, `### 🔧 Changed`, `### ⚡ Performance`, `### 🐛 Fixed`, `### 🔒 Security`, `### 🧹 Deprecated`, `### 🗑️ Removed` and `### 📚 Documentation`.

## [0.7.0] - 2026-11-11

### 🔧 Changed

- **The four switches read their rule from the package, so a fix to it reaches a published config.** `config/fortify-hardening.php` spelled out which values switch a hardening off, and a host that published the file kept that rule as it was on the day it published it. The fix of 0.6.1 for an empty `.env` line reached no such copy, and there an empty line kept switching a hardening off. The switches call `EnvSwitch::read(env('…'), true)` now, and the rule ships in `src/Support/EnvSwitch.php`: `false`, `0`, `no` and `off` switch a hardening off, trimmed and in any case, and anything else keeps it on. **Upgrade:** in a published copy, replace the expression of each of the four once with its call and import the class: `password_reset_link.enabled` with `EnvSwitch::read(env('FORTIFY_HARDENING_PASSWORD_RESET_LINK'), true)`, `password_reset.enabled` with `EnvSwitch::read(env('FORTIFY_HARDENING_PASSWORD_RESET'), true)`, `registration.enabled` with `EnvSwitch::read(env('FORTIFY_HARDENING_REGISTRATION'), true)`, `hashed_login_keys.enabled` with `EnvSwitch::read(env('FORTIFY_HARDENING_HASHED_LOGIN_KEYS'), true)`, and `use Pushery\FortifyHardening\Support\EnvSwitch;` at the top.

## [0.6.1] - 2026-11-09

### 🐛 Fixed

- **`assertEnumerationSafe()` compares a JSON error the way production sends it.** Laravel's debug rendering adds `exception`, `file`, `line` and `trace` to the body, and two requests that both name nothing often fail on different paths through the code, so a suite running with `APP_DEBUG=true` failed on a stack trace. A body that carries `exception` and `trace` is now compared without those four. A field of that name in any other body stays in the comparison.
- **An e-mail address in a typed greeting keeps its characters in the mail about a taken address.** The mail sets a typed greeting as text, and it wrote the dot of a domain with a look-alike character so that no mail program would link it. An e-mail address in the greeting came out with that look-alike too, so a mail program did not offer it as an address and a copy of it was not the address that was typed. An address now stays as typed; a web address is still broken as before.

### 🔒 Security

- **An empty line in the `.env` keeps a hardening on.** `env()` reads `FORTIFY_HARDENING_REGISTRATION=` as an empty string, and each of the four `enabled` switches counted it as off, so a line left empty by copying a template dropped the protection of the registration, the reset link, the reset or the login keys, while a missing line kept it. Only `false`, `0`, `no` and `off` switch a section off now.
- **The password of a registration stays out of the trace of a failure.** `Registrar::register()` and `registerHashingInside()` mark it with `#[\SensitiveParameter]`, so a failure while the address is looked up or the account is created carries a placeholder in its place. A server with `zend.exception_ignore_args` off, as `php.ini-development` sets it, kept the password in the trace, where an error tracker reads the arguments of every frame.

### 📚 Documentation

- **The notice to the holder of a taken address is shown on the registration page.** The image is rendered from the package itself, nothing in it drawn by hand.

## [0.6.0] - 2026-11-08

### 🔒 Security

- **A greeting somebody typed reaches the mail about a taken address as text.** The mail is Markdown, and a name a subclass put into its greeting, such as `[Reset your password](https://…)`, arrived as a real link. A greeting `greeting()` returns as a string is now set as text: its Markdown stays characters, and a web address in it is written so that a mail program does not link it. `greeting()` may also return an `Htmlable`, which is set as it is.

  **Upgrade:** a greeting that used Markdown on purpose now shows its characters. A greeting returned as an `Htmlable` is set as it is, so one built as Markdown keeps its formatting, and what it takes from the user is then the application's to escape.

## [0.5.2] - 2026-11-03

The mail budget of the registration form keeps its count under a keyed hash of the inbox, and a reset form sent twice is recognized for an account that resets under another value than its address.

### 🐛 Fixed

- **A reset form sent twice is recognized for an account that resets under another value than its address.** The completed reset was noted under `getEmailForPasswordReset()` and looked up under the address of the form, so in an application whose accounts reset under another value, such as `<scope>:<address>` for the account of each shop, the second click was still answered as a used link. The repeat now looks the account up first and reads the note under the value that account resets under. A form with another password in the same session stays refused.

### 🔒 Security

- **The mail budget of the registration form keeps its count under a keyed hash of the inbox.** Its cache key was an unkeyed SHA-256 of the address, which anyone who reads cache keys, in an error tracker or a store's monitor, could match against a guessed address. It is now an HMAC under the application key, as the login limiter's key already was.

  **Upgrade:** nothing to do. The counts of the running hour start again after the deploy.

## [0.5.1] - 2026-10-26

A registration refuses an address with a line break on every Laravel 13, a reset form sent twice is recognized behind a broker of the application's as well, and the mail budget counts an address with a no-break space at its edge as that address.

### 🐛 Fixed

- **A reset form sent twice is answered as the reset it repeats behind a broker of the application's as well.** The check that recognizes the repeat asked the broker for the account only when it was Laravel's own broker class, so a broker that implements the contract and hands the reset on to Laravel's, the usual way to send the reset mail from the queue, answered every second click as a used link. The account now comes from the broker where it can say, and from the user provider the broker's configuration names where it cannot, which is where Laravel's broker looks it up.
- **`Support\Mailbox::of()` drops a no-break space at either end of an address.** It trimmed only ASCII whitespace, so an address copied with a no-break space or an em space at its edge was counted under a key of its own and received a mail budget of its own. Whitespace of every kind is dropped at both ends now, and such a spelling shares the budget of the address.
- **A registration that sends the address as a list is refused at the field.** With `fortify.lowercase_usernames` on, the address was written in lower case before the form request ran, and a list there ended in a server error. Only text is written in lower case now; anything else reaches the form request, whose `string` rule refuses it at the field.

### 🔒 Security

- **A registration refuses an address with a carriage return or a line feed on every Laravel 13.** Laravel's `email` rule lets both characters through before 13.10.0 (GHSA-5vg9-5847-vvmq), and the address goes into the headers of the mails a registration sends. The form request refuses them with a rule of its own now, so a Laravel 13 below 13.10.0 is covered as well.

## [0.5.0] - 2026-10-25

The mail budget of the registration form counts the spellings of a Gmail address as one inbox, the rule it counts under is public as `Support\Mailbox::of()`, and a reset form sent twice is answered as the reset it repeats.

### ✨ Added

- **`Support\Mailbox::of()` names the inbox an address reaches, the key the mail budget of the registration counts under.** An application that counts mails of its own per address can count them under the same key instead of keeping a copy of the rule. The key is for counting and is no identity: an account keeps its address as it was typed.

### 🐛 Fixed

- **A reset form sent twice is answered as the reset it repeats.** A double click sent the reset form twice, the first request set the password and used the token up, and the second was answered as a reset with an invalid token, although the new password already held. The second request now receives the answer of a reset that went through where it repeats one the same session completed within the last minute: the same address, a password its confirmation agrees with, and the password the account now holds. Every other refused reset keeps the invalid-token answer.

### 🔒 Security

- **The mail budget of the registration counts the spellings of a Gmail address as one inbox.** Gmail ignores the dots in the local part and delivers `googlemail.com` to the same inbox as `gmail.com`, and the budget counted each such spelling on a budget of its own. A local part of eleven characters has 1,024 spellings with dots, twice over with the second domain, and each received the hour's mail. These spellings now share one budget. Under any other domain a dot stays, since there it can name another inbox.

  **Upgrade:** nothing to do. A count kept under a dotted spelling before the update runs out within its hour.

## [0.4.1] - 2026-10-24

The mail budget of the registration form counts per mailbox, so the `+tag` spellings of one address, which most providers deliver to the same inbox, share one hour's budget instead of each getting its own.

### 🔒 Security

- **The mail budget of the registration form counts per mailbox, not per spelling.** It keyed its count with the address as it arrived, so `name+a@example.com` and `name+b@example.com`, which most providers deliver to the inbox of `name@example.com`, had a budget each, and since every spelling registers as a new address, one inbox could receive a verification mail for every variant. The count now ignores case, surrounding whitespace and a `+tag` in the local part; a dot in the local part still makes another address.

  **Upgrade:** nothing to do. A count kept under a spelling before the update runs out within its hour.

## [0.4.0] - 2026-10-21

A regional locale reads the package's catalog of its language, so an application running `pt-BR` or `pt-PT` sends the notice to the holder of a taken address in Portuguese, and the timing page says from which release Fortify holds a failed two-factor sign-in itself.

### ✨ Added

- **A regional locale reads the package's catalog of its language.** The catalogs ship per language, and Laravel's translator asks for the given locale and then for `app.fallback_locale`, so an application running `pt-BR` or `pt-PT` sent the notice to the holder of a taken address and the answer to a registration in the fallback language. The package now wraps the translation loader so that a regional locale of the `fortify-hardening` namespace reads its language, `pt-BR` from `pt` and `en-GB` from `en`, a region nobody listed included. A catalog published for the region under `lang/vendor/fortify-hardening/` wins over the language key by key. Every other namespace keeps Laravel's lookup.

### 📚 Documentation

- **The timing page and the configuration say when Fortify's own two-factor wait applies.** Fortify holds a failed sign-in in its two-factor step to 200 ms from release 1.40 on, and earlier releases do not hold it at all, while the package allows `laravel/fortify` from 1.0. The package's minimum applies in both cases.

## [0.3.0] - 2026-10-17

The notice to the holder of a taken address names the application and can greet the holder, it stops suggesting a password reset to an application without passwords, and `assertEnumerationSafe()` passes a Livewire form that answers a known and an unknown identity alike.

### ✨ Added

- **The notice to the holder of a taken address names the application, and a host can greet the holder.** The subject says where somebody tried to register, from `app.name`. A subclass named in `fortify-hardening.registration.notification` can return another name from `applicationName()`, such as the instance where one installation serves several, and a greeting from `greeting()`; both receive the notifiable. A published translation of the subject places the name with `:app`.
- **The notice no longer suggests a password reset to an application without passwords.** With `fortify-hardening.registration.notice_mentions_password_reset` set to `false`, its last line points at the sign-in page alone, in all seven languages.

### 🐛 Fixed

- **`assertEnumerationSafe()` passes a Livewire form that answers a known and an unknown identity alike.** It compared a JSON body byte for byte, and a Livewire update response is never the same twice: the component id is drawn at random, the snapshot carries a checksum, and the response repeats the address that was typed. The fingerprint now reads each snapshot as data without its checksum, writes `<component-id>` for the ids Livewire drew, and writes `<identity>` wherever the response repeats the identity that was sent, in the status line, the errors and the redirect as well. A field that tells the two apart still fails the assertion.

## [0.2.0] - 2026-10-16

Fortify's sign-in limiter counts under a hashed key, so neither the cache store nor an error tracker that records cache calls holds an address or a client IP any more.

### 🔒 Security

- **Fortify's sign-in limiter counts under a key that names nobody.** Fortify keys its count of failed sign-ins with the lower-cased address and the client IP as they are, so both stood in the cache store and in what an error tracker records for cache calls. The package now binds a `LoginRateLimiter` that counts under the same pair hashed with the application key; the count, the limit of five a minute and the wait stay Fortify's. Counts running at the deploy start again. `FORTIFY_HARDENING_HASHED_LOGIN_KEYS=false` switches it off.

## [0.1.1] - 2026-10-15

A security release for applications that use Fortify's two-factor feature, together with a fix for the lock of the mail budget.

### 🐛 Fixed

- **The mail budget locks an address in the store it counts in.** The count lives in the store Laravel's rate limiter uses, `cache.limiter`, and the lock was taken in the default store. It is taken beside the count now, and in the default store only where the limiter's store cannot lock, so an application with a limiter store of its own, one per tenant for instance, keeps both together.

### 🔒 Security

- **A failed sign-in through Fortify's two-factor step waits the full minimum.** With Fortify's two-factor feature on, Fortify checks the password in a step of its own before the guard does, and it held a failure there to a fixed 200 ms, less than the hash an existing account costs, so an address with an account still answered later than one without. The package holds that step to `auth.timebox_duration`, the minimum the guard reads, whether the step is Fortify's or one the application binds with `Fortify::redirectUserForTwoFactorAuthenticationUsing()`.

## [0.1.0] - 2026-10-13

The first release. The package stops Laravel Fortify from revealing whether an account exists for an address: registration and password reset answer a taken address and a free one alike and in the same time, and the holder of a taken address is told by mail instead of getting a second account.

### ✨ Added

- **Neutral registration on Fortify's route.** A taken address and a free one get the same answer, a redirect to the sign-in page with "check your inbox", or `202 Accepted` for JSON, and nobody is signed in. A free address is created by the application's `CreatesNewUsers` action, a taken one gets no second account, and a race for one new address ends like a taken address.
- **Notice to the holder of a taken address** (`AccountAlreadyExistsNotification`), in German, English, Spanish, French, Italian, Dutch and Portuguese, with a mail budget per address and hour that the verification mail of a new account counts against as well.
- **Neutral password reset.** A reset-link request for an unknown address, or for one that asked a moment ago, is answered like a link that was sent, and a failed reset reads like a wrong token whether the address has an account or not.
- **Equal timing.** A taken address pays the same password hash a new account costs, and a failed sign-in, a reset-link request and a failed reset are held to one second through `auth.timebox_duration`, unless the application sets its own.
- **`Registrar`** for applications that register users in a controller of their own, and the `RegistrationAccepted` contract for an answer of their own.
- **`AssertsEnumerationSafety`**, the assertion the package proves itself with, for an application's own suite.
- **Switches that read what a host writes.** Each section is switched off with `false`, `0`, `no`, `off` or an empty value, in any case and with spaces around it, and any other value keeps it on.

[Unreleased]: https://github.com/pushery/fortify-hardening-for-laravel/compare/v0.7.0...HEAD
[0.7.0]: https://github.com/pushery/fortify-hardening-for-laravel/compare/v0.6.1...v0.7.0
[0.6.1]: https://github.com/pushery/fortify-hardening-for-laravel/compare/v0.6.0...v0.6.1
[0.6.0]: https://github.com/pushery/fortify-hardening-for-laravel/compare/v0.5.2...v0.6.0
[0.5.2]: https://github.com/pushery/fortify-hardening-for-laravel/compare/v0.5.1...v0.5.2
[0.5.1]: https://github.com/pushery/fortify-hardening-for-laravel/compare/v0.5.0...v0.5.1
[0.5.0]: https://github.com/pushery/fortify-hardening-for-laravel/compare/v0.4.1...v0.5.0
[0.4.1]: https://github.com/pushery/fortify-hardening-for-laravel/compare/v0.4.0...v0.4.1
[0.4.0]: https://github.com/pushery/fortify-hardening-for-laravel/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/pushery/fortify-hardening-for-laravel/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/pushery/fortify-hardening-for-laravel/compare/v0.1.1...v0.2.0
[0.1.1]: https://github.com/pushery/fortify-hardening-for-laravel/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/pushery/fortify-hardening-for-laravel/releases/tag/v0.1.0
