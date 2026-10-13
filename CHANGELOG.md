# Changelog

All notable changes to `pushery/fortify-hardening-for-laravel` are documented here. The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/), and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

A release carries only the sections that have an entry, in this order: `### ⚠️ Breaking`, `### ✨ Added`, `### 🔧 Changed`, `### ⚡ Performance`, `### 🐛 Fixed`, `### 🔒 Security`, `### 🧹 Deprecated`, `### 🗑️ Removed` and `### 📚 Documentation`.

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
