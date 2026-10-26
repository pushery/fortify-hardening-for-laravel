<?php

declare(strict_types=1);

namespace Pushery\FortifyHardening\Http\Controllers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;
use Laravel\Fortify\Contracts\RegisterResponse;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\Http\Controllers\RegisteredUserController;
use Override;
use Pushery\FortifyHardening\Contracts\RegistrationAccepted;
use Pushery\FortifyHardening\Registration\AddressTurnedOutTaken;
use Pushery\FortifyHardening\Registration\Registrar;
use Pushery\FortifyHardening\Support\Settings;

/**
 * Fortify's registration endpoint, without the answers that tell a free address from a taken one.
 *
 * The package binds this class in place of Fortify's controller, so Fortify's own route reaches it.
 * The request is validated first, for every address, by the configured form request. Only then is
 * the address looked up: a free one is created by the application's `CreatesNewUsers` action, a taken
 * one is not, and either way the answer is `RegistrationAccepted` and nobody is signed in.
 */
final class EnumerationSafeRegisteredUserController extends RegisteredUserController
{
    public function __construct(
        StatefulGuard $guard,
        private readonly Container $container,
        private readonly Registrar $registrar,
        private readonly Settings $settings,
    ) {
        parent::__construct($guard);
    }

    #[Override]
    public function store(Request $request, CreatesNewUsers $creator): RegisterResponse
    {
        $username = Fortify::username();
        $given = $request->input($username);

        // Only text is written in lower case here. Anything else reaches the form request as it was
        // sent, and its `string` rule refuses it at the field.
        if ($this->settings->lowercasesUsernames() && is_string($given)) {
            $request->merge([$username => Str::lower($given)]);
        }

        // Resolving a form request validates it, and a failure throws before anything is looked up.
        $this->container->make($this->settings->registrationRequest());

        $this->registrar->registerHashingInside(
            $request->string($username)->value(),
            $request->string('password')->value(),
            static function () use ($creator, $request, $username): Authenticatable {
                try {
                    return $creator->create($request->all());
                } catch (ValidationException $invalid) {
                    // The form request above passed, so a refusal of the address here is the
                    // creator's own `unique` rule losing a race the registrar could not see.
                    if (array_key_exists($username, $invalid->errors())) {
                        throw new AddressTurnedOutTaken('The address was registered while this request was in flight.', $invalid->getCode(), previous: $invalid);
                    }

                    throw $invalid;
                }
            },
        );

        return $this->container->make(RegistrationAccepted::class);
    }
}
