<?php

declare(strict_types=1);

namespace Pushery\FortifyHardening\Http\Responses;

use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\PasswordBroker;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\FailedPasswordResetResponse;
use Laravel\Fortify\Contracts\PasswordResetResponse;
use Laravel\Fortify\Http\Responses\FailedPasswordResetResponse as FortifyFailedPasswordResetResponse;
use Override;
use Pushery\FortifyHardening\Support\CompletedPasswordResets;

/**
 * Answers every failed password reset as a reset with an invalid token.
 *
 * The broker tells an unknown address (`passwords.user`) apart from a known address whose token is
 * wrong or expired (`passwords.token`), and Fortify passes that status straight through. This
 * response hands Fortify's own failure response the invalid-token status instead, so the redirect
 * with its error, or the 422 a JSON request receives, reads the same for an address with an account
 * and for one without.
 *
 * A reset form sent twice is the one exception. The second request finds the token the first one
 * used up, and where it repeats a reset this session completed a moment ago, it receives the answer
 * of a reset that went through, as {@see CompletedPasswordResets} decides.
 */
final readonly class NeutralPasswordResetResponse implements FailedPasswordResetResponse
{
    public function __construct(private CompletedPasswordResets $resets) {}

    /** @param  Request  $request */
    #[Override]
    public function toResponse(mixed $request): mixed
    {
        if ($this->resets->repeats($request)) {
            return Container::getInstance()
                ->make(PasswordResetResponse::class, ['status' => PasswordBroker::PASSWORD_RESET])
                ->toResponse($request);
        }

        return new FortifyFailedPasswordResetResponse(PasswordBroker::INVALID_TOKEN)->toResponse($request);
    }
}
