<?php

namespace Tests\Support;

use App\Http\HttpClient;
use App\Http\HttpResponse;
use RuntimeException;

/**
 * The network, faked: answers from a table of URL patterns, records every request, and fails on any request
 * it was not told about - so a test never reaches a real site.
 *
 *     T::$http->fake(['showcase.example/*' => FakeHttpClient::json([...]), 'https://slow.example/*' => 'page']);
 *
 * A pattern is a URL without its scheme, "*" matching anything; the first match wins. An answer is an
 * HttpResponse, a string (a 200 with that body) or a callable returning either.
 */
final class FakeHttpClient implements HttpClient
{
    /** @var array<string, HttpResponse|string|callable> */
    private array $routes = [];

    /** @var list<string> */
    public array $sent = [];

    /** @param array<string, HttpResponse|string|callable> $routes */
    public function fake(array $routes): void
    {
        $this->routes = $routes + $this->routes;
    }

    public function reset(): void
    {
        $this->routes = [];
        $this->sent = [];
    }

    public static function json(mixed $data, int $status = 200, array $headers = []): HttpResponse
    {
        return new HttpResponse($status, (string) json_encode($data), array_change_key_case($headers + ['content-type' => 'application/json']));
    }

    public static function status(int $status, string $body = '', array $headers = []): HttpResponse
    {
        return new HttpResponse($status, $body, array_change_key_case($headers));
    }

    public function get(string $url, array $headers = [], int $timeout = 30): HttpResponse
    {
        $bare = preg_replace('#^https?://#', '', $url) ?? $url;
        foreach ($this->routes as $pattern => $answer) {
            $regex = '#^'.str_replace('\*', '.*', preg_quote(preg_replace('#^https?://#', '', $pattern) ?? $pattern, '#')).'$#';
            if (preg_match($regex, $bare)) {
                $this->sent[] = $url;
                if (is_callable($answer) && ! is_string($answer)) {
                    $answer = $answer($url, $headers);
                }

                return is_string($answer) ? new HttpResponse(200, $answer) : $answer;
            }
        }

        throw new RuntimeException("A test sent a request nobody expected: GET {$url}");
    }
}
