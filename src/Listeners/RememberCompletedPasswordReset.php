<?php

declare(strict_types=1);

namespace Pushery\FortifyHardening\Listeners;

use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Http\Request;
use Pushery\FortifyHardening\Support\CompletedPasswordResets;

/**
 * Notes a completed password reset for the session that made it, so the same form sent again reads as that reset.
 *
 * Laravel announces the reset while the broker still holds the token, so the note exists before a second request can
 * find the token gone.
 */
final readonly class RememberCompletedPasswordReset
{
    public function __construct(
        private CompletedPasswordResets $resets,
        private Request $request,
    ) {}

    public function handle(PasswordReset $event): void
    {
        if ($event->user instanceof CanResetPassword) {
            $this->resets->remember($this->request, $event->user);
        }
    }
}
