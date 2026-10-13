<?php

declare(strict_types=1);

namespace Pushery\FortifyHardening\Http\Responses;

use Illuminate\Contracts\Auth\PasswordBroker;
use Laravel\Fortify\Contracts\FailedPasswordResetResponse;
use Laravel\Fortify\Http\Responses\FailedPasswordResetResponse as FortifyFailedPasswordResetResponse;
use Override;

/**
 * Answers every failed password reset as a reset with an invalid token.
 *
 * The broker tells an unknown address (`passwords.user`) apart from a known address whose token is
 * wrong or expired (`passwords.token`), and Fortify passes that status straight through. This
 * response hands Fortify's own failure response the invalid-token status instead, so the redirect
 * with its error, or the 422 a JSON request receives, reads the same for an address with an account
 * and for one without.
 */
final class NeutralPasswordResetResponse implements FailedPasswordResetResponse
{
    #[Override]
    public function toResponse(mixed $request): mixed
    {
        return new FortifyFailedPasswordResetResponse(PasswordBroker::INVALID_TOKEN)->toResponse($request);
    }
}
