<?php

declare(strict_types=1);

namespace Pushery\FortifyHardening\Testing;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Session\Store;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Testing\Assert;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Proves that an endpoint answers a known and an unknown identity alike.
 *
 * Use it in a PHPUnit test case or, with `uses()`, in Pest. It sends the same request once for an
 * identity that has an account and once for one that has none, runs your own expectation on each
 * response, and then requires the two to be indistinguishable to the caller: the same HTTP status,
 * the same redirect, the same flashed status line, the same validation errors and, for a JSON
 * response, the same body.
 */
trait AssertsEnumerationSafety
{
    /**
     * @param  Closure(string): TestResponse<Response>  $submit  sends the request for one identity
     * @param  (Closure(TestResponse<Response>): mixed)|null  $expect  asserts the neutral outcome on each response
     */
    public function assertEnumerationSafe(Closure $submit, string $known, string $unknown, ?Closure $expect = null): void
    {
        $knownResponse = $submit($known);

        if ($expect instanceof Closure) {
            $expect($knownResponse);
        }

        $knownFingerprint = self::enumerationFingerprint($knownResponse);

        $unknownResponse = $submit($unknown);

        if ($expect instanceof Closure) {
            $expect($unknownResponse);
        }

        Assert::assertSame(
            $knownFingerprint,
            self::enumerationFingerprint($unknownResponse),
            'The response to a known identity differs from the response to an unknown one, so it tells the caller which identities have an account.',
        );
    }

    /**
     * What a caller observes of a response: its status, its redirect, the status line and the
     * validation errors it flashed, and the body of a JSON response.
     *
     * Read from the session the way Laravel's own session assertions read it, started again after
     * the request saved it. With `session.serialization` at `json` a saved session holds its error
     * bags as plain arrays, and only a start turns them back into a `ViewErrorBag`.
     *
     * @param  TestResponse<Response>  $response
     * @return array{status: int, location: string|null, status_line: mixed, errors: array<array-key, mixed>, body: string|null}
     */
    public static function enumerationFingerprint(TestResponse $response): array
    {
        $session = Container::getInstance()->make(Store::class);

        if (! $session->isStarted()) {
            $session->start();
        }

        $bag = $session->get('errors');
        $content = $response->baseResponse->getContent();
        $isJson = str_contains((string) $response->baseResponse->headers->get('Content-Type'), 'json');

        return [
            'status' => $response->baseResponse->getStatusCode(),
            'location' => $response->baseResponse->headers->get('Location'),
            'status_line' => $session->get('status'),
            'errors' => $bag instanceof ViewErrorBag ? $bag->getBag('default')->getMessages() : [],
            'body' => $isJson && is_string($content) ? $content : null,
        ];
    }
}
