# Changelog

All notable changes to `pushery/fortify-hardening-for-laravel` are documented here. The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/), and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

A release carries only the sections that have an entry, in this order: `### ⚠️ Breaking`, `### ✨ Added`, `### 🔧 Changed`, `### ⚡ Performance`, `### 🐛 Fixed`, `### 🔒 Security`, `### 🧹 Deprecated`, `### 🗑️ Removed` and `### 📚 Documentation`.

## [0.9.0] - 2026-11-24

### 🔧 Changed

- **`PruneExpiredCacheKeys` clears a file store as well.** Beside the expired rows under `fortify-hardening:` in a database store, it now deletes the expired files of a file store, which names its files by a hash of the key and so cannot tell the package's from the application's; the store already treats each of them as missing. Redis, Memcached and every other store that expires entries itself stay untouched. The call in the schedule stays `Schedule::call(PruneExpiredCacheKeys::class)->daily();`.
- **`PruneExpiredCacheKeys` also prunes behind a `failover` store and a `storage` store.** Both of these Laravel cache drivers leave expired entries behind as a database or a file store does. A failover store writes each entry into the first of its stores that answers, so the command prunes each of its stores once. A storage store lays its files out on a filesystem disk the way a file store does in its directory, and the command deletes the expired files the store named there and leaves every other file on the disk alone.

### 🐛 Fixed

- **`assertEnumerationSafe()` compares two JSON:API errors without the id of their request.** http-api names the id of each request in `meta.request_id` from 0.4.0 on, a new one for every request, so two answers that were the same failed the comparison by that id alone. Each error is compared with a placeholder in its place, and a difference in any other member still fails.

### 📚 Documentation

- **The documentation says that the `octane` store of Laravel Octane is out of the pruner's reach.** The store keeps an expired entry in a table of a fixed number of rows, `octane.cache.rows`, until its key is written again or the server restarts, and the scheduler, where the pruner runs, sees an empty store. Once the table is full, writing a new key fails, so the documentation advises against making `octane` the default cache store of an application that runs this package.
- **The documentation of the sign-in limiter says where a visitor can claim another IP.** Below `laravel/framework` 13.43.0, an application that configures no trusted proxy and whose web server answers any host name takes a header the visitor sends as the mark of a Forge or Vapor proxy (GHSA-559m-v249-7qw2), so each attempt can come from another IP and start a count of its own. The timing page names the framework update, trusted proxies and a web server that answers only the application's host names as the ways to close it.

## [0.8.0] - 2026-11-20

### ✨ Added

- **`LoginThrottleKey::hash()` keys the `login` limiter of the application's service provider with the application key.** Fortify's installer writes a `login` rate limiter into `app/Providers/FortifyServiceProvider.php` that counts failed sign-ins per address and IP a second time, and Laravel keeps that count under an MD5 of the limiter's name, the address and the IP, a value anyone computes again from a guessed address and IP. The package hashed only the key of Fortify's own `LoginRateLimiter`, while the README promised a sign-in count under a key that names nobody. The limiter counts under the same HMAC as the package's own once it returns `Limit::perMinute(5)->by(LoginThrottleKey::hash($throttleKey))`, and the README, the timing page and the Boost skill now say that this limiter is the application's to key.

  **Upgrade:** wrap the key of the `login` limiter in `app/Providers/FortifyServiceProvider.php` in `LoginThrottleKey::hash()`, as the timing page shows. Counts that are running when the change is deployed start again.

- **`PruneExpiredCacheKeys` deletes the package's expired keys from a database cache store.** A database store deletes the row of an expired key only when that key is read again, and nothing in Laravel prunes its table, so the note of every completed password reset, the count of every inbox that registered once and the sign-in count of every address and IP that never tried again stayed in the table for good. Scheduled once a day with `Schedule::call(PruneExpiredCacheKeys::class)->daily()`, it deletes the rows of the package's expired keys from the default store and from the store in `cache.limiter`, a thousand to a statement, and does nothing on any other store. The installation page and the Boost skill show the schedule line.

### 🔧 Changed

- **Packagist lists the package under what it prevents.** Its keywords were `laravel`, `fortify` and `hardening`, so a search for account enumeration or user enumeration did not find it. They now name `account-enumeration`, `user-enumeration`, `email-enumeration`, `password-reset`, `forgot-password` and `timing-attacks` among others, and the description names account enumeration.
- **Where people sign in with a username of their own, Fortify's registration stays in place.** With `fortify.username` naming another field than `fortify.email`, the package's controller looked the account up under the username and passed on every other refusal of the `CreatesNewUsers` action, so a taken address with a free username was answered with "The email has already been taken." and a free address with the neutral answer. A registration that takes a username cannot answer neutrally on its own: a taken username has to be refused for the person to choose another, and the controller would reach that refusal only for a free address. The package now puts its controller on Fortify's route only where `fortify.username` and `fortify.email` name the same field, as in Fortify's own configuration, and the registration and limits pages say what an application with usernames can do instead.

  **Upgrade:** with a username of its own, the registration on Fortify's route answers as Fortify does: a taken address or username is refused with the errors of the `CreatesNewUsers` action, and a new account is signed in. An application that keeps the address closed there registers from a controller of its own with the registrar, as the registration page shows.

### 🐛 Fixed

- **The notice to a holder whose address was used says what to do in two paragraphs, one per case.** The sentence for the holder who tried and the one for everybody else ran on as one paragraph, so its last word could stand alone on a line. The second one is the new line `not_you`, in every language of the package.

  **Upgrade:** a `messages.php` published before this release still ends `next` and `next_without_password` with the second sentence, and the mail then says it twice; take it out of both lines.
- **The registrar finds an address where Fortify's password reset finds it.** `Registrar::register()` and `registerHashingInside()` looked the account up under `fortify.username`, so with a username of its own they looked for the address among the usernames, never found its account and handed a taken address to the code that creates accounts. They now look it up under `fortify.email`. Where both name the same field, nothing changes.

- **The notice to the holder of a taken address loads no image from elsewhere in an application named Laravel either.** Laravel's mail header shows Laravel's logo from laravel.com in place of an application name that reads exactly `Laravel`, the `APP_NAME` a new Laravel application starts with, so the notice, which promises no remote image, font or pixel, loaded one there. Under that name it now renders through a template of the package, `fortify-hardening::mail.notice`, whose header shows the name as text. Every other name keeps Laravel's notification template, and with it what the application published of it.

- **A registration that loses the race for a new address leaves a transaction of the application's usable on PostgreSQL.** The registrar created the account without a savepoint, so where the application had a transaction open, the insert that failed at the unique index aborted it: under the database cache store the mail budget then failed with a `QueryException`, and under any other store `register()` returned with the transaction still aborted for whatever came next. The registrar now creates the account in a savepoint wherever a transaction is open on the connection the accounts live on, as Laravel's `createOrFirst()` does, and the registration page says that both calls may run inside a transaction. MySQL and SQLite take back the failed statement alone and behave as before.

### 🔒 Security

- **The note of a completed password reset is kept under a keyed hash.** Its cache key was an unkeyed SHA-256 of the session id and the address. A session kept in Redis, Memcached or DynamoDB stands in the cache under its id, among the keys an error tracker or a store's monitor records, so whoever read those keys could test a guessed address against every session. The key is now an HMAC under the application key, as the keys of the mail budget and the sign-in limiter already were.

  **Upgrade:** nothing to do. A note lives for a minute, so at most a reset form sent twice across the deploy is answered as a used link.

- **A registration is held to the minimum duration, a free address and a taken one alike.** Both outcomes paid the same password hash, and nothing else of their time was equal: a free address also runs the `CreatesNewUsers` action, with its query, its insert and whatever it creates beside the account, and the application's `Registered` listeners, so one request with a free address told it apart by its time. The registrar now runs both outcomes inside Laravel's timebox, held to `auth.timebox_duration`, the minimum the guard holds a failed sign-in to and one second with the package, and neither returns early. A controller of the application's own that calls the registrar is held the same way.

  **Upgrade:** a registration takes at least that minimum now. Work that runs past it, such as mail sent through a slow transport while the request waits, still shows.

- **Over the mail budget, the verification mail of a new account stays back as well.** The budget counts the inbox, so `name+1@…`, `name+2@…` and the address itself share it, and over it the notice to the holder of a taken address was held back while the verification mail of a new account always went out. One inbox could be filled through new spellings, an account and a mail for each, and once another spelling had spent the budget, a free address still mailed and a taken one did not, which the time of the answer showed. The registrar now notes the new account when the budget is spent, and a listener on `NotificationSending` stops its verification mail, Laravel's `VerifyEmail` or a subclass of it, in the request or in the queue worker that sends it. `Registered` still goes out, and only the first verification mail is held, so a link asked for again goes out.

  **Upgrade:** a verification notification of the application's own that does not extend `VerifyEmail` is not recognized and still goes out over the budget; extending `VerifyEmail` brings it under the budget.

- **A fraction in `FORTIFY_HARDENING_TIMEBOX_MICROSECONDS` no longer switches the minimum duration off.** The config tested the value for a number and then cast it, and `0.5`, `.5` and `0.9` are numbers the cast makes `0`, the value that leaves Laravel's own minimum in place: a failed sign-in and the password routes fell back to Laravel's 200 milliseconds, shorter than one bcrypt hash, and the package no longer held the two-factor step. The value is read as a whole number now, so a fraction, a negative number or a typo lands on the default of one second, and only `0` itself switches the minimum off.

  **Upgrade:** a published `config/fortify-hardening.php` keeps the old reading until the expression of `timebox.microseconds` is replaced with `is_string($timebox = env('FORTIFY_HARDENING_TIMEBOX_MICROSECONDS')) && preg_match('/^\s*\d+\s*$/', $timebox) === 1 ? (int) $timebox : 1_000_000`.

- **A minimum duration the application reads from `env()` stays in force.** An application sets `auth.timebox_duration` in its own `config/auth.php`, usually from `env()`, which hands a number over as text, and Laravel's guard and password broker take the text as the number it spells. The package counted only an integer as set: it replaced such a value with its own minimum of one second, so an application that asked for two seconds got one, and with the package's minimum switched off, a registration was held to Laravel's 200 milliseconds instead of the application's minimum. A whole number counts as set now, written as a number or as text, for the provider, the two-factor step and the registration alike; a negative number or other text holds no duration and is treated as an unset key.

- **A refused password reset reads no account unless its session just reset that address.** To tell a reset form sent twice from any other refused reset, the answer looked the address of the form up, and for an address with an account it also read the account and a note in the cache. The answer comes after the broker's minimum duration has ended, so an address with an account was answered later than one without, by a cache read that is one more query under the database cache store. The note is now kept under the session and the address of the form and read first: a session that has not just reset that address reads one cache entry and no account, whether the address has one or not.

  **Upgrade:** nothing to do. A note lives for a minute, so at most a reset form sent twice across the deploy is answered as a used link.

- **A failed sign-in through `Fortify::authenticateUsing()` is held to the minimum duration.** With a callback set there, Fortify's `AttemptToAuthenticate` signs in through the callback and never through the guard, whose timebox is the only one Laravel holds a sign-in to. A callback that looks the user up and checks the password, as Fortify's documentation writes it, paid a password hash for an address with an account and none for one without, so with the two-factor feature off the time of a failed sign-in told the two apart. The package now holds that step to `auth.timebox_duration`, as it holds the two-factor step, also in a login pipeline of the application's own that names the class. A sign-in that goes through still returns at once.

  **Upgrade:** nothing to do. A failed sign-in through the callback now takes the minimum duration, one second unless the application sets its own.

### 📚 Documentation

- **The README says what the engine suites prove, and no more.** It concluded from the runs on PostgreSQL and MySQL 8.4 that the package runs on Laravel Cloud without further setup, while a run against a database server in the test lane is no run on the serverless Postgres of Laravel Cloud. The requirements name the two engines as the ones Laravel Cloud runs, the fact the sentence stood on, and leave the conclusion out.
- **The code examples import every class they name.** The controller example on the registration page, in the README and in the Boost skill typed `RegisterRequest` without importing it, the README's also `RedirectResponse`, and the test examples called `User::factory()` without `use App\Models\User;`. Pasted into a file with a namespace, PHP looked for each class in that namespace and stopped with `Class not found`.
- **The README's example of an application's own mail count keys its hash with the application key.** It hashed the address with a plain SHA-256, which anyone who reads cache keys can match against a guessed address. The README and the registration page now show `hash_hmac()` under `app.key` and say why.
- **The registration page names what the store of the mail budget has to survive.** The count lives only in a cache store, and a count the store loses before its hour is a budget that starts again, so the next registration mails the address once more. The page recommended Redis, Memcached, DynamoDB and the database store without a word on that. It now names the three ways a store loses a count, eviction under memory pressure (Redis under any `maxmemory-policy` but `noeviction`, Memcached without `-M`), a flush by `cache:clear` or `optimize:clear`, and a restart without persistence, and the store that keeps one; the limits page names the same.
- **The installation page says which settings read an environment variable.** It promised one for every setting, so publishing the configuration file looked optional for any change, while the form request, the two routes and everything about the notice change only in a published file. The page and the Boost skill now name the `enabled` switches, the mail budget and the minimum duration as the settings that read one. The page also credited the message after a password reset to Fortify's sign-in views, which Fortify does not ship; it now names the redirect of Fortify's own response, which flashes the message as `status` on the way to the `login` route.
- **The pages name every key of the package a store can lose before its time, and the stores that keep them.** The registration and limits pages named what the store of the mail budget has to survive for its count alone, while the note that holds back the verification mail of an account created over the budget lives in the same store, and a lost note lets that mail go out. Both pages name the note now, recommend a store of its own in `cache.limiter` that no `cache:clear` of the default store reaches, leave Memcached out, and say that a Redis under `noeviction` that has filled up refuses every write. The password reset page says that a store which loses the note of a completed reset answers a form sent twice with the invalid-token answer, and the Boost skill names the store and what to avoid.

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

[Unreleased]: https://github.com/pushery/fortify-hardening-for-laravel/compare/v0.9.0...HEAD
[0.9.0]: https://github.com/pushery/fortify-hardening-for-laravel/compare/v0.8.0...v0.9.0
[0.8.0]: https://github.com/pushery/fortify-hardening-for-laravel/compare/v0.7.0...v0.8.0
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
