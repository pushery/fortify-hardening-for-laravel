<?php

declare(strict_types=1);

namespace Pushery\FortifyHardening\Listeners;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Notifications\Events\NotificationSending;
use Pushery\FortifyHardening\Support\AddressBudget;

/**
 * Stops the verification mail of an account the registrar created over the mail budget.
 *
 * Laravel asks the listeners of `NotificationSending` before it sends a notification on a channel, and an
 * answer of `false` stops it there. This one answers `false` for Laravel's `VerifyEmail`, or a subclass of it,
 * sent to an account the budget holds a verification mail back for. Every other notification it leaves to the
 * listeners after it, by answering nothing.
 */
final readonly class HoldBackVerificationMail
{
    public function __construct(private AddressBudget $budget) {}

    public function handle(NotificationSending $event): ?false
    {
        if ($event->notification instanceof VerifyEmail && $event->notifiable instanceof Authenticatable && $this->budget->takesVerification($event->notifiable)) {
            return false;
        }

        return null;
    }
}
