<?php

declare(strict_types=1);

namespace Pushery\FortifyHardening\Actions;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Timebox;
use Laravel\Fortify\Contracts\RedirectsIfTwoFactorAuthenticatable;
use Override;

/**
 * Fortify's two-factor step of the sign-in, held to the minimum duration the guard reads.
 *
 * With Fortify's two-factor feature on, the login pipeline checks the password in this step, before
 * the guard does, and a failure ends the request here. Fortify holds that failure to a fixed 200
 * milliseconds of its own, less than the hash an existing account costs, so an address with an
 * account would answer later than one without. This step runs the one bound to Fortify's contract,
 * Fortify's or the application's own, and holds a failure to `auth.timebox_duration`, the minimum
 * the guard holds a failed sign-in to. A request the step passes on, or answers with the two-factor
 * challenge, returns at once; a failure further down the pipeline is held as well.
 */
final readonly class TimeboxedTwoFactorStep implements RedirectsIfTwoFactorAuthenticatable
{
    /**
     * Laravel's own minimum, which the guard falls back to when `auth.timebox_duration` is unset.
     */
    private const int LARAVEL_MICROSECONDS = 200_000;

    public function __construct(
        private RedirectsIfTwoFactorAuthenticatable $step,
        private Repository $config,
    ) {}

    /**
     * @param  Request  $request
     * @param  callable  $next
     */
    #[Override]
    public function handle(mixed $request, mixed $next): mixed
    {
        return new Timebox()->call(function (Timebox $timebox) use ($request, $next): mixed {
            $response = $this->step->handle($request, $next);

            $timebox->returnEarly();

            return $response;
        }, $this->microseconds());
    }

    private function microseconds(): int
    {
        $value = $this->config->get('auth.timebox_duration');

        return is_int($value) && $value >= 0 ? $value : self::LARAVEL_MICROSECONDS;
    }
}
