<?php

declare(strict_types=1);

namespace Datacircle\LogiscapeMcp;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;

/**
 * Calls Datacircle's open API, https://api.datacircle.dev/v1 (its spec: https://api.datacircle.dev/v1/openapi.json).
 * It needs no key. More at https://datacircle.dev
 *
 * It calls through PHP's curl extension, which the MCP SDK for PHP needs too, or PHP's own HTTPS streams without it,
 * or through the PSR-18 client and PSR-17 request factory it is given (psr/http-client, which the SDK does not install).
 */
class Client
{
    public const BASE_URL = 'https://api.datacircle.dev/v1';

    /** This package's version, sent in each call's User-Agent. */
    public const VERSION = '0.1.0';

    /** A plan holds about 90 keys (its terms of use, its aggregators...): these choose a plan, the rest is on its page. */
    public const PLAN_KEYS = ['api', 'rank', 'provider', 'plan', 'score', 'perThousand', 'notFoundPerThousand', 'billing', 'pay',
        'currency', 'units', 'unit', 'strengths', 'latencyMs', 'page', 'pricingPage', 'docs'];

    public readonly string $baseUrl;

    public readonly float $timeout;

    private ?ClientInterface $http;

    private ?RequestFactoryInterface $requests;

    /**
     * @param string|null $baseUrl Where the tools call. BASE_URL when left out.
     * @param ClientInterface|null $http A PSR-18 client to call through (a proxy, a test): curl or PHP's streams when left out.
     * @param RequestFactoryInterface|null $requests The PSR-17 factory of its requests, needed with a PSR-18 client.
     * @param float $timeout How many seconds a call may take without a PSR-18 client. The PSR-18 client keeps its own.
     */
    public function __construct(?string $baseUrl = null, ?ClientInterface $http = null, ?RequestFactoryInterface $requests = null, float $timeout = 30)
    {
        if (null !== $http && null === $requests) {
            throw new \InvalidArgumentException('datacircle: a PSR-18 client needs its PSR-17 request factory, like new Client(http: $guzzle, requests: new HttpFactory())');
        }
        $this->baseUrl = rtrim($baseUrl ?? self::BASE_URL, '/');
        $this->http = $http;
        $this->requests = $requests;
        $this->timeout = $timeout;
    }

    /**
     * Calls one path and gives its JSON. A 429 is waited for once, a minute at most.
     *
     * @param array<string, string|int|null> $query
     *
     * @return array<mixed>
     *
     * @throws ApiError an answer of 4xx, with the API's own words
     * @throws \RuntimeException any other failure
     */
    public function get(string $path, array $query = []): array
    {
        $query = array_filter($query, static fn (mixed $value): bool => null !== $value && '' !== $value);
        $url = $this->baseUrl.$path.([] === $query ? '' : '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986));

        for ($try = 0;; ++$try) {
            [$status, $reason, $retryAfter, $body] = null !== $this->http ? $this->sendPsr18($url) : $this->send($url);
            if (429 === $status && 0 === $try) {
                $wait = (int) $retryAfter;
                sleep($wait >= 1 && $wait <= 60 ? $wait : 60);

                continue;
            }
            $json = json_decode($body, true);
            if ($status >= 400 && $status < 500) {
                $error = is_array($json) ? ($json['error'] ?? null) : null;

                throw new ApiError($status, is_string($error) && '' !== $error ? $error : trim($status.' '.$reason));
            }
            if (200 !== $status) {
                throw new \RuntimeException(trim('datacircle: '.$status.' '.$reason));
            }
            if (!is_array($json)) {
                throw new \RuntimeException('datacircle: the answer is not JSON');
            }

            return $json;
        }
    }

    /**
     * @return array{int, string, string, string} the status, its reason, the Retry-After header and the body
     */
    private function sendPsr18(string $url): array
    {
        $request = $this->requests->createRequest('GET', $url)
            ->withHeader('Accept', 'application/json')
            ->withHeader('User-Agent', self::userAgent());

        try {
            $response = $this->http->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new \RuntimeException('datacircle: '.$e->getMessage(), 0, $e);
        }

        return [$response->getStatusCode(), $response->getReasonPhrase(), $response->getHeaderLine('Retry-After'), (string) $response->getBody()];
    }

    /**
     * @return array{int, string, string, string} the status, its reason, the Retry-After header and the body
     */
    private function send(string $url): array
    {
        $headers = ['Accept: application/json', 'User-Agent: '.self::userAgent()];
        if (function_exists('curl_init')) {
            $retryAfter = '';
            $curl = curl_init($url);
            curl_setopt_array($curl, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_TIMEOUT_MS => (int) ($this->timeout * 1000),
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$retryAfter): int {
                    if (0 === stripos($line, 'retry-after:')) {
                        $retryAfter = trim(substr($line, 12));
                    }

                    return strlen($line);
                },
            ]);
            $body = curl_exec($curl);
            if (!is_string($body)) {
                $error = curl_error($curl);

                throw new \RuntimeException('datacircle: '.('' !== $error ? $error : 'the call failed'));
            }

            return [(int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE), '', $retryAfter, $body];
        }

        $context = stream_context_create(['http' => [
            'method' => 'GET',
            'header' => implode("\r\n", [...$headers, 'Connection: close']),
            'ignore_errors' => true,
            'timeout' => $this->timeout,
            'follow_location' => 1,
            'max_redirects' => 4,
        ]]);
        error_clear_last();
        $stream = @fopen($url, 'r', false, $context);
        if (false === $stream) {
            throw new \RuntimeException('datacircle: '.(error_get_last()['message'] ?? 'the call failed'));
        }

        try {
            $body = stream_get_contents($stream);
            $lines = stream_get_meta_data($stream)['wrapper_data'] ?? [];
        } finally {
            fclose($stream);
        }
        if (!is_string($body)) {
            throw new \RuntimeException('datacircle: the answer could not be read');
        }
        [$status, $reason, $retryAfter] = [0, '', ''];
        foreach (is_array($lines) ? $lines : [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})\s*(.*)$#', (string) $line, $match)) {
                // A redirect's status comes first: the last one is the answer's.
                [$status, $reason, $retryAfter] = [(int) $match[1], trim($match[2]), ''];
            } elseif (0 === stripos((string) $line, 'retry-after:')) {
                $retryAfter = trim(substr((string) $line, 12));
            }
        }

        return [$status, $reason, $retryAfter, $body];
    }

    private static function userAgent(): string
    {
        return 'datacircle-logiscape-mcp/'.self::VERSION;
    }

    /**
     * The API's rule for a slug: the name in lowercase letters and digits, the rest a hyphen.
     */
    public static function slugify(string $name): string
    {
        $name = trim($name);
        if (class_exists(\Normalizer::class)) {
            $name = (string) preg_replace('/\p{Mn}/u', '', (string) \Normalizer::normalize($name, \Normalizer::FORM_KD));
        } elseif (function_exists('iconv')) {
            // Without intl, each letter alone: iconv writes é as 'e or ?e in some locales, and the mark is dropped.
            $name = (string) preg_replace_callback('/[^\x00-\x7F]/u', static fn (array $letter): string => str_replace(
                ["'", '"', '`', '^', '~', '?'], '', (string) @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $letter[0])), $name);
        }

        return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');
    }

    /**
     * @param array<string, mixed> $item
     * @param list<string> $keys
     *
     * @return array<string, mixed>
     */
    public static function brief(array $item, array $keys): array
    {
        $kept = [];
        foreach ($keys as $key) {
            if (isset($item[$key])) {
                $kept[$key] = $item[$key];
            }
        }

        return $kept;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function objects(mixed $value): array
    {
        return array_values(array_filter(is_array($value) ? $value : [], 'is_array'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function briefPlans(mixed $value): array
    {
        return array_map(static fn (array $plan): array => self::brief($plan, self::PLAN_KEYS), self::objects($value));
    }
}
