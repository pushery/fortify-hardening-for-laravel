<?php

declare(strict_types=1);

namespace Pushery\FortifyHardening\Contracts;

use Laravel\Fortify\Contracts\RegisterResponse;

/**
 * The one answer a registration gives, whether the address was free, taken, or taken in a race.
 *
 * Bind your own implementation to change where the answer leads or what it says. It has to answer
 * all three outcomes alike, because any difference between them tells the caller whether the address
 * has an account.
 */
interface RegistrationAccepted extends RegisterResponse {}
