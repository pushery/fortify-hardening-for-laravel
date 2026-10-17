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
 *
 * What the caller sent, and what Livewire draws at random, stand as placeholders, because neither
 * tells one identity from the other: the identity wherever the response repeats it, and in a Livewire
 * update response the ids of its components and the checksums of their snapshots.
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

        $knownFingerprint = self::enumerationFingerprint($knownResponse, $known);

        $unknownResponse = $submit($unknown);

        if ($expect instanceof Closure) {
            $expect($unknownResponse);
        }

        Assert::assertSame(
            $knownFingerprint,
            self::enumerationFingerprint($unknownResponse, $unknown),
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
     * Given the identity the request sent, every place the response repeats it reads `<identity>`.
     * A Livewire update response is compared by what its components say: each snapshot is read as
     * data without its checksum, and the ids Livewire drew for the components read `<component-id>`.
     *
     * @param  TestResponse<Response>  $response
     * @return array{status: int, location: string|null, status_line: mixed, errors: array<array-key, mixed>, body: string|null}
     */
    public static function enumerationFingerprint(TestResponse $response, ?string $identity = null): array
    {
        $session = Container::getInstance()->make(Store::class);

        if (! $session->isStarted()) {
            $session->start();
        }

        $identities = self::enumerationIdentityForms($identity);
        $bag = $session->get('errors');
        $statusLine = $session->get('status');
        $location = $response->baseResponse->headers->get('Location');
        $content = $response->baseResponse->getContent();
        $isJson = str_contains((string) $response->baseResponse->headers->get('Content-Type'), 'json');

        return [
            'status' => $response->baseResponse->getStatusCode(),
            'location' => is_string($location) ? strtr($location, $identities) : null,
            'status_line' => is_string($statusLine) ? strtr($statusLine, $identities) : $statusLine,
            'errors' => $bag instanceof ViewErrorBag ? self::enumerationReplacedIn($bag->getBag('default')->getMessages(), $identities) : [],
            'body' => $isJson && is_string($content) ? self::enumerationComparableBody($content, $identities) : null,
        ];
    }

    /**
     * The forms an identity takes in a response: as sent, escaped for HTML, and encoded for a URL.
     *
     * @return array<string, string>
     */
    private static function enumerationIdentityForms(?string $identity): array
    {
        if ($identity === null || $identity === '') {
            return [];
        }

        return array_fill_keys(array_unique([
            $identity,
            htmlspecialchars($identity, ENT_QUOTES | ENT_SUBSTITUTE),
            rawurlencode($identity),
            urlencode($identity),
        ]), '<identity>');
    }

    /**
     * A JSON body as the caller can compare it.
     *
     * Livewire returns each component's snapshot as a JSON string of its own, signed with a checksum
     * over the data, and writes the component's id into its HTML. A child the update mounts carries
     * its own snapshot in an attribute of that HTML, checksum included.
     *
     * @param  array<string, string>  $identities
     */
    private static function enumerationComparableBody(string $content, array $identities): string
    {
        $body = json_decode($content, true);

        if (! is_array($body)) {
            return strtr($content, $identities);
        }

        $ids = [];
        $components = $body['components'] ?? null;

        if (is_array($components)) {
            foreach ($components as $index => $component) {
                $encoded = is_array($component) ? $component['snapshot'] ?? null : null;
                $snapshot = is_string($encoded) ? json_decode($encoded, true) : null;

                if (! is_array($component) || ! is_array($snapshot)) {
                    continue;
                }

                unset($snapshot['checksum']);
                $ids = [...$ids, ...self::enumerationComponentIds($snapshot['memo'] ?? null)];
                $component['snapshot'] = $snapshot;

                $effects = $component['effects'] ?? null;

                if (is_array($effects)) {
                    array_walk_recursive($effects, static function (mixed &$leaf): void {
                        if (is_string($leaf)) {
                            $leaf = preg_replace('/(&quot;|")checksum\1:\1[0-9a-f]{64}\1/', '$1checksum$1:$1<checksum>$1', $leaf) ?? $leaf;
                        }
                    });

                    $component['effects'] = $effects;
                }

                $components[$index] = $component;
            }

            $body['components'] = $components;
        }

        $replacements = [...array_fill_keys($ids, '<component-id>'), ...$identities];

        return json_encode(self::enumerationReplacedIn($body, $replacements), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * The ids a snapshot's memo names: the component's own, and those of the children it tracks.
     *
     * @return list<string>
     */
    private static function enumerationComponentIds(mixed $memo): array
    {
        if (! is_array($memo)) {
            return [];
        }

        $id = $memo['id'] ?? null;
        $ids = is_string($id) && $id !== '' ? [$id] : [];
        $children = $memo['children'] ?? null;

        if (! is_array($children)) {
            return $ids;
        }

        foreach ($children as $child) {
            $childId = is_array($child) ? $child[1] ?? null : null;

            if (is_string($childId) && $childId !== '') {
                $ids[] = $childId;
            }
        }

        return $ids;
    }

    /**
     * Every string in $value, with the replacements made.
     *
     * @param  array<array-key, mixed>  $value
     * @param  array<string, string>  $replacements
     * @return array<array-key, mixed>
     */
    private static function enumerationReplacedIn(array $value, array $replacements): array
    {
        array_walk_recursive($value, static function (mixed &$leaf) use ($replacements): void {
            if (is_string($leaf)) {
                $leaf = strtr($leaf, $replacements);
            }
        });

        return $value;
    }
}
