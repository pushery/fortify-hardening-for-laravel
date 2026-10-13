<?php

declare(strict_types=1);

namespace Pushery\FortifyHardening\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Redirector;
use Illuminate\Routing\Router;
use Override;
use Pushery\FortifyHardening\Contracts\RegistrationAccepted;
use Pushery\FortifyHardening\Support\Settings;

/**
 * Answers every registration the same way: check your inbox.
 *
 * A web request is redirected to the configured route, the sign-in page by default, or to the
 * application's root when no route of that name exists, with that message as its status. A JSON
 * request receives it with `202 Accepted`. Nobody is signed in and nothing in the answer depends on
 * whether the address was free.
 */
final readonly class NeutralRegistrationResponse implements RegistrationAccepted
{
    public function __construct(
        private Redirector $redirector,
        private Router $router,
        private Settings $settings,
    ) {}

    #[Override]
    public function toResponse(mixed $request): mixed
    {
        $message = trans('fortify-hardening::messages.registration.accepted');

        if ($request->wantsJson()) {
            return new JsonResponse(['message' => $message], JsonResponse::HTTP_ACCEPTED);
        }

        $route = $this->settings->redirectRoute();
        $redirect = $this->router->has($route) ? $this->redirector->route($route) : $this->redirector->to('/');

        return $redirect->with('status', $message);
    }
}
