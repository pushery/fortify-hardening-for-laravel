<?php

declare(strict_types=1);

namespace Pushery\FortifyHardening\Actions;

use Illuminate\Http\Request;
use Illuminate\Support\Timebox;
use Laravel\Fortify\Contracts\RedirectsIfTwoFactorAuthenticatable;
use Override;
use Pushery\FortifyHardening\Support\Settings;

/**
 * Fortify's two-factor step of the sign-in, held to the minimum duration the guard reads.
 *
 * With Fortify's two-factor feature on, the login pipeline checks the password in this step, before
 * the guard does, and a failure ends the request here. From Fortify 1.40 on it holds that failure to
 * a fixed 200 milliseconds of its own, and earlier releases do not hold it at all. Either is less
 * than the hash an existing account costs, so an address with an account would answer later than
 * one without. This step runs the one bound to Fortify's contract, Fortify's or the application's
 * own, and holds a failure to `auth.timebox_duration`, the minimum the guard holds a failed sign-in
 * to. A request the step passes on, or answers with the two-factor challenge, returns at once; a
 * failure further down the pipeline is held as well.
 */
final readonly class TimeboxedTwoFactorStep implements RedirectsIfTwoFactorAuthenticatable
{
    public function __construct(
        private RedirectsIfTwoFactorAuthenticatable $step,
        private Settings $settings,
    ) {}

    /**
     * @param  mixed  $request  the request, which Fortify's pipeline hands a step untyped
     * @param  mixed  $next  the rest of the pipeline, which Fortify hands a step untyped
     *
     * @phpstan-param  Request  $request
     * @phpstan-param  callable  $next
     *
     * @return mixed what the rest of the pipeline returns, which Fortify leaves untyped
     */
    #[Override]
    public function handle(mixed $request, mixed $next): mixed
    {
        $response = null;

        // The step's answer leaves the timebox through $response, so the callback declares no type of
        // its own: a sign-in that goes through returns at once, and a failure waits out the minimum and
        // is thrown again, as Timebox::call() does with what the callback throws.
        new Timebox()->call(function (Timebox $timebox) use ($request, $next, &$response): void {
            $response = $this->step->handle($request, $next);

            $timebox->returnEarly();
        }, $this->settings->guardTimeboxMicroseconds());

        return $response;
    }
}
