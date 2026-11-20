<?php

declare(strict_types=1);

namespace Pushery\FortifyHardening\Http\Responses;

use Illuminate\Contracts\Auth\PasswordBroker;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;
use Override;

/**
 * Answers a reset-link request that failed exactly as one that succeeded.
 *
 * The password broker refuses an unknown address (`passwords.user`) and an address that asked too
 * recently (`passwords.throttled`). Only an address with an account can be throttled, so either
 * refusal tells the caller that the account exists. This response resolves the success response
 * the application has bound and returns it with the status of a sent link, for every failure, on a
 * web request and on a JSON one alike. The broker still mails only real, unthrottled accounts.
 */
final readonly class NeutralPasswordResetLinkResponse implements FailedPasswordResetLinkRequestResponse
{
    public function __construct(private Container $container) {}

    /**
     * @param  mixed  $request  the request, which the contract of a response leaves untyped
     *
     * @phpstan-param  Request  $request
     *
     * @return mixed the response, which the contract leaves untyped: Laravel's, Inertia's or the application's own
     */
    #[Override]
    public function toResponse(mixed $request): mixed
    {
        return $this->container
            ->make(SuccessfulPasswordResetLinkRequestResponse::class, ['status' => PasswordBroker::RESET_LINK_SENT])
            ->toResponse($request);
    }
}
