<?php

declare(strict_types=1);

namespace Pushery\FortifyHardening\Registration;

use RuntimeException;

/**
 * Thrown by an account creator that found the address taken after the registrar had found it free.
 *
 * The registrar looks the address up and only then creates the account, so two requests for one new
 * address can both find it free. The second creation then fails, at the database's unique index or
 * at a `unique` rule the creator still carries. A creator that recognizes the second case throws
 * this, and the registrar treats the address as taken: it notifies the holder and gives the same
 * answer as for any other registration.
 */
final class AddressTurnedOutTaken extends RuntimeException {}
