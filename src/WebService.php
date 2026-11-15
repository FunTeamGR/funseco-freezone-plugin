<?php
namespace Funseco\Freezone;

use Psr\Http\Message\ResponseInterface;
use React\Promise\PromiseInterface;
use Funseco\Core\Services\Http\AsyncHttpClient;
use function React\Promise\reject;

/** The FreeZone web service: Basic auth, JSON in and out. Promises reject on any failure; a 401 rejects with WrongPassword. */
class WebService
{
    public function __construct(private AsyncHttpClient $http)
    {
    }

    public function user(): string
    {
        return (string) (config('freezone.ws_user') ?: config('server.login'));
    }

    /** Web calls allowed: password set and not in testmode. */
    public function usable(): bool
    {
        return (string) config('freezone.ws_password') !== '' && !config('freezone.testmode');
    }

    public function get(string $path): PromiseInterface
    {
        return $this->send('get', $path);
    }

    public function put(string $path, array $body): PromiseInterface
    {
        return $this->send('put', $path, $body);
    }

    public function post(string $path, array $body): PromiseInterface
    {
        return $this->send('post', $path, $body);
    }

    private function send(string $verb, string $path, ?array $body = null): PromiseInterface
    {
        if (!$this->usable()) {
            return reject(new \RuntimeException('freezone web service not usable'));
        }
        $headers = [
            'Authorization' => 'Basic '.base64_encode($this->user().':'.config('freezone.ws_password')),
            'Accept'        => 'application/json',
            'User-Agent'    => 'ManiaLib Rest Client',
        ];
        $url = rtrim((string) config('freezone.ws_url'), '/').$path;
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }
        $promise = $body === null
            ? $this->http->get($url, $headers)
            : $this->http->{$verb}($url, json_encode($body), $headers);

        return $promise->then(
            fn (ResponseInterface $response) => json_decode((string) $response->getBody(), true),
            function (\Throwable $e) {
                if ($e instanceof \React\Http\Message\ResponseException && $e->getCode() === 401) {
                    throw new WrongPassword('freezone web service refused the credentials', 401, $e);
                }
                throw $e;
            }
        );
    }
}
