<?php

declare(strict_types=1);

namespace Datacircle\LogiscapeMcp\Tests;

use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A PSR-18 client that answers each request with what its closure gives, and keeps every request it was sent.
 */
final class FakeHttp implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @param \Closure(RequestInterface): ResponseInterface $answer */
    public function __construct(private \Closure $answer) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        return ($this->answer)($request);
    }

    /** @param array<mixed> $body */
    public static function json(array $body, int $status = 200, array $headers = []): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'] + $headers, json_encode($body));
    }

    /** The paths and queries asked, in order, like /v1/apis/email-finder/best?limit=3. */
    public function asked(): array
    {
        return array_map(static fn (RequestInterface $r): string => $r->getUri()->getPath().('' === $r->getUri()->getQuery() ? '' : '?'.$r->getUri()->getQuery()), $this->requests);
    }
}
