<?php

namespace App\Http;

/**
 * Sends GET requests to other sites. The real one is CurlHttpClient; tests use a fake that answers from a
 * table and fails on any request it was not told about, so they never touch the network.
 */
interface HttpClient
{
    /**
     * @param  array<string, string>  $headers
     *
     * @throws ConnectionFailed when there is no answer at all (no connection, a timeout)
     */
    public function get(string $url, array $headers = [], int $timeout = 30): HttpResponse;
}
