<?php

declare(strict_types=1);

namespace Pushery\FortifyHardening\Actions;

use Illuminate\Http\Request;
use Illuminate\Support\Timebox;
use Laravel\Fortify\Actions\AttemptToAuthenticate;
use Pushery\FortifyHardening\Support\Settings;

/**
 * Fortify's step of the sign-in that authenticates, held to the minimum duration the guard reads.
 *
 * With a callback set through `Fortify::authenticateUsing()`, this step signs in through the callback and never
 * through the guard, whose timebox is the only one Laravel holds a sign-in to. A callback that looks the user up and
 * checks the password pays a hash for an address with an account and none for one without, so a failure would answer
 * sooner for an address without an account. This step runs Fortify's, or the subclass the application binds, and
 * holds a failure to `auth.timebox_duration`. A sign-in that goes through returns at once. On the guard's own path the
 * guard has already waited out the same minimum, so the wait here adds nothing.
 */
final readonly class TimeboxedSignInStep
{
    public function __construct(
        private AttemptToAuthenticate $step,
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
