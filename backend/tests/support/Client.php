<?php

namespace Tests\Support;

use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\Factory\UriFactory;
use T;

/**
 * A browser for the API: sends requests to the app in-process, from 127.0.0.1 to http://localhost unless
 * told otherwise, carrying a bearer token once asIam() was called.
 */
final class Client
{
    private ?string $token = null;

    /** @var array<string, string> */
    private array $headers = [];

    public function __construct(private string $ip = '127.0.0.1') {}

    /** The next requests carry a valid access token for this IAM user. */
    public function asIam(int $userId, array $roles = ['MEMBER'], array $claims = []): self
    {
        $this->token = iamToken($userId, $roles, $claims);

        return $this;
    }

    public function withToken(?string $token): self
    {
        $this->token = $token;

        return $this;
    }

    /** @param array<string, string> $headers */
    public function withHeaders(array $headers): self
    {
        $this->headers = $headers + $this->headers;

        return $this;
    }

    public function flush(): self
    {
        $this->headers = [];
        $this->token = null;

        return $this;
    }

    public function from(string $ip): self
    {
        $this->ip = $ip;

        return $this;
    }

    /**
     * @param  array<string, mixed>|null  $json
     * @param  array<string, mixed>  $options  files: [name => [path, filename]], form: array, body: a raw body
     *                                          (sent as application/json, or as type)
     */
    public function request(string $method, string $url, ?array $json = null, array $options = []): Answer
    {
        $url = str_starts_with($url, 'http') ? $url : 'http://localhost'.$url;
        $uri = (new UriFactory)->createUri($url);
        $host = $uri->getHost().($uri->getPort() ? ':'.$uri->getPort() : '');
        $request = (new ServerRequestFactory)->createServerRequest($method, $uri, ['REMOTE_ADDR' => $this->ip])->withHeader('Host', $host);
        $streams = new StreamFactory;

        if ($json !== null) {
            $request = $request->withHeader('Content-Type', 'application/json')->withBody($streams->createStream((string) json_encode($json)));
        }
        if (isset($options['body'])) {
            $request = $request->withHeader('Content-Type', $options['type'] ?? 'application/json')->withBody($streams->createStream($options['body']));
        }
        if (isset($options['files'])) {
            $files = [];
            foreach ($options['files'] as $name => [$path, $filename]) {
                $files[$name] = new \Slim\Psr7\UploadedFile($path, $filename, 'application/json', filesize($path), UPLOAD_ERR_OK);
            }
            $request = $request->withUploadedFiles($files)->withParsedBody($options['form'] ?? []);
        }
        $request = $request->withHeader('Accept', 'application/json');
        foreach ($this->headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        if ($this->token !== null) {
            $request = $request->withHeader('Authorization', 'Bearer '.$this->token);
        }

        return new Answer(T::$api->handle($request));
    }

    public function get(string $url): Answer
    {
        return $this->request('GET', $url);
    }

    public function post(string $url, array $json = []): Answer
    {
        return $this->request('POST', $url, $json);
    }

    public function patch(string $url, array $json = []): Answer
    {
        return $this->request('PATCH', $url, $json);
    }

    public function delete(string $url): Answer
    {
        return $this->request('DELETE', $url);
    }
}

/**
 * An answer of the API, with a few checks that say what they found when they fail.
 */
final class Answer
{
    public readonly int $status;

    public readonly string $body;

    public function __construct(public readonly ResponseInterface $response)
    {
        $this->status = $response->getStatusCode();
        $this->body = (string) $response->getBody();
    }

    public function json(?string $path = null): mixed
    {
        $data = json_decode($this->body, true);
        if ($path === null) {
            return $data;
        }
        foreach (explode('.', $path) as $segment) {
            if (! is_array($data) || ! array_key_exists($segment, $data)) {
                return null;
            }
            $data = $data[$segment];
        }

        return $data;
    }

    public function header(string $name): string
    {
        return $this->response->getHeaderLine($name);
    }

    public function status(int $expected): self
    {
        same($expected, $this->status, 'HTTP status (body: '.substr($this->body, 0, 300).')');

        return $this;
    }

    public function path(string $path, mixed $expected): self
    {
        same($expected, $this->json($path), "JSON at {$path}");

        return $this;
    }
}
