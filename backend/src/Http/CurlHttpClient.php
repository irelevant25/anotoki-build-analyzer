<?php

namespace App\Http;

use Composer\CaBundle\CaBundle;

/**
 * GET requests through curl.
 *
 * TLS is always verified. Windows PHP builds often ship without a CA bundle (curl.cainfo empty), so the
 * bundle comes from composer/ca-bundle: the system's where there is one, else its copy of Mozilla's roots.
 * Redirects are followed (a few), and a compressed body is decoded.
 */
final class CurlHttpClient implements HttpClient
{
    public function get(string $url, array $headers = [], int $timeout = 30): HttpResponse
    {
        $handle = curl_init($url);
        $received = [];

        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => min(10, max(1, $timeout)),
            CURLOPT_TIMEOUT => max(1, $timeout),
            CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CAINFO => CaBundle::getSystemCaRootBundlePath(),
            CURLOPT_HEADERFUNCTION => function ($curl, string $line) use (&$received): int {
                if (str_starts_with($line, 'HTTP/')) {
                    $received = []; // a redirect: only the last answer's headers count
                } elseif (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $received[strtolower(trim($name))] = trim($value);
                }

                return strlen($line);
            },
        ]);

        $body = curl_exec($handle);
        if ($body === false) {
            // No curl_close(): the handle goes with the variable (curl_close is deprecated in PHP 8.5).
            throw new ConnectionFailed(curl_error($handle) ?: 'no answer');
        }

        return new HttpResponse((int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), (string) $body, $received);
    }
}
