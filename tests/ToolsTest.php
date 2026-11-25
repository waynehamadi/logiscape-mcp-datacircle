<?php

declare(strict_types=1);

namespace Datacircle\LogiscapeMcp\Tests;

use Datacircle\LogiscapeMcp\Client;
use Datacircle\LogiscapeMcp\Datacircle;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;

final class ToolsTest extends TestCase
{
    private const PLAN = ['api' => 'Employee email', 'rank' => 1, 'provider' => 'Emelia', 'plan' => 'Pro', 'score' => 76.4,
        'perThousand' => 4.99, 'page' => 'https://datacircle.dev/x', 'termsOfUse' => 'long', 'aggregators' => ['a']];

    private const APIS = [
        ['api' => 'Employee email', 'slug' => 'email-finder', 'plans' => 10, 'providers' => 3, 'internal' => 1],
        ['api' => 'Email validation', 'slug' => 'email-verification', 'plans' => 5, 'providers' => 2],
        ['api' => 'Employee profile', 'slug' => 'linkedin-profile', 'plans' => 7, 'providers' => 4],
    ];

    /** @param \Closure(RequestInterface): Response $answer */
    private static function client(\Closure $answer, ?FakeHttp &$http = null): Client
    {
        $http = new FakeHttp($answer);

        return new Client('https://api.test/v1', $http, new Psr17Factory());
    }

    public function testInputSchemas(): void
    {
        self::assertSame('{"type":"object","properties":{"slug":{"type":"string","description":"The provider\'s slug, like bright-data, or its name, like Bright Data: the name is made a slug"}},"required":["slug"]}',
            json_encode(Datacircle::inputSchema(Datacircle::PROVIDER), JSON_UNESCAPED_SLASHES));
        self::assertArrayNotHasKey('required', Datacircle::inputSchema(Datacircle::LIST_APIS));
        self::assertSame(['api', 'query', 'sort', 'limit', 'offset'], array_keys(Datacircle::inputSchema(Datacircle::SEARCH_PLANS)['properties']));
        self::assertTrue(Datacircle::handles('datacircle_search_plans'));
        self::assertFalse(Datacircle::handles('get_weather'));
        $this->expectException(\InvalidArgumentException::class);
        Datacircle::inputSchema('get_weather');
    }

    public function testListApisFindsByWords(): void
    {
        $client = self::client(static fn () => FakeHttp::json(self::APIS), $http);
        $answer = Datacircle::listApis(['query' => 'employee-email', 'limit' => '1'], $client);
        self::assertSame(['/v1/apis'], $http->asked());
        self::assertSame(3, $answer['total']);
        self::assertSame([['api' => 'Employee email', 'slug' => 'email-finder', 'plans' => 10, 'providers' => 3]], $answer['apis']);
        self::assertArrayNotHasKey('note', $answer);

        $answer = Datacircle::listApis(['query' => 'Bright Data'], $client);
        self::assertSame(0, $answer['total']);
        self::assertStringContainsString('datacircle_search_plans', $answer['note']);

        self::assertCount(3, Datacircle::listApis([], $client)['apis']);
    }

    public function testBestPlans(): void
    {
        $client = self::client(static fn () => FakeHttp::json(['api' => 'Employee email', 'slug' => 'email-finder', 'total' => 1, 'plans' => [self::PLAN]]), $http);
        $answer = Datacircle::bestPlans(['slug' => 'Email Finder', 'limit' => 300, 'extra' => true], $client);
        self::assertSame(['/v1/apis/email-finder/best?limit=50'], $http->asked());
        self::assertSame('datacircle-logiscape-mcp/'.Client::VERSION, $http->requests[0]->getHeaderLine('User-Agent'));
        self::assertSame(['api', 'rank', 'provider', 'plan', 'score', 'perThousand', 'page'], array_keys($answer['plans'][0]));

        Datacircle::bestPlans(['slug' => 'email-finder', 'limit' => 0], $client);
        self::assertSame('/v1/apis/email-finder/best', $http->asked()[1]);
    }

    public function testAnUnknownSlugIsTheApisWords(): void
    {
        $client = self::client(static fn () => FakeHttp::json(['error' => 'No API is named bright-data'], 404));
        $answer = Datacircle::bestPlans(['slug' => 'Bright Data'], $client);
        self::assertSame(['error'], array_keys($answer));
        self::assertStringStartsWith('No API is named bright-data. datacircle_list_apis', $answer['error']);
        self::assertStringContainsString('datacircle_search_plans', $answer['error']);
    }

    public function testSearchPlansTakesAProviderSentAsTheApi(): void
    {
        $client = self::client(static function (RequestInterface $r) {
            parse_str($r->getUri()->getQuery(), $q);
            if (isset($q['api'])) {
                return FakeHttp::json(['error' => 'api must be one of the slugs of GET /apis'], 400);
            }

            return FakeHttp::json(['total' => 'bright data' === ($q['query'] ?? '') ? 1 : 0, 'offset' => 0, 'plans' => 'bright data' === ($q['query'] ?? '') ? [self::PLAN] : []]);
        }, $http);
        $answer = Datacircle::searchPlans(['api' => 'bright-data', 'sort' => 'price', 'offset' => 0], $client);
        self::assertSame([
            '/v1/plans?api=bright-data&sort=price',
            '/v1/plans?query=bright-data&sort=price',
            '/v1/plans?query=bright%20data&sort=price',
        ], $http->asked());
        self::assertSame(1, $answer['total']);
        self::assertSame('No API is named "bright-data": these are the plans whose provider or plan name holds it. No provider\'s or plan\'s name holds "bright-data": these are the plans whose name holds "bright data".', $answer['note']);
        self::assertArrayNotHasKey('termsOfUse', $answer['plans'][0]);
    }

    public function testProviderIsSortedByRank(): void
    {
        $client = self::client(static fn () => FakeHttp::json(['provider' => 'Bright Data', 'slug' => 'bright-data', 'apis' => [
            ['api' => 'A', 'slug' => 'a', 'rank' => null, 'plans' => [['plan' => 'x', 'score' => 1]]],
            ['api' => 'B', 'slug' => 'b', 'rank' => 7, 'plans' => [['plan' => 'y', 'rank' => 9, 'score' => 2], ['plan' => 'z', 'rank' => 7, 'score' => 3, 'perThousand' => 1.5]]],
            ['api' => 'C', 'slug' => 'c', 'rank' => 2, 'plans' => []],
        ]]), $http);
        $answer = Datacircle::provider(['slug' => 'Bright Data'], $client);
        self::assertSame(['/v1/providers/bright-data'], $http->asked());
        self::assertSame(['provider', 'slug', 'total', 'ranked', 'apis'], array_keys($answer));
        self::assertSame([3, 2], [$answer['total'], $answer['ranked']]);
        self::assertSame(['c', 'b', 'a'], array_column($answer['apis'], 'slug'));
        self::assertSame(['plan' => 'z', 'score' => 3, 'perThousand' => 1.5], $answer['apis'][1]['best']);
        self::assertSame(['plan' => 'x', 'score' => 1], $answer['apis'][2]['best']);
        self::assertArrayNotHasKey('best', $answer['apis'][0]);

        $missing = self::client(static fn () => FakeHttp::json(['error' => 'No provider is named email-finder'], 404));
        self::assertSame("No provider is named email-finder. An API's best providers: datacircle_best_plans with the API's slug, like email-finder.",
            Datacircle::provider(['slug' => 'email-finder'], $missing)['error']);
    }

    public function testTheCheckSaysWhatItRefuses(): void
    {
        $client = self::client(static fn () => FakeHttp::json([]), $http);
        try {
            Datacircle::searchPlans(['api' => ['x'], 'sort' => 'cheapest', 'limit' => 'ten'], $client);
            self::fail('no refusal');
        } catch (\InvalidArgumentException $e) {
            self::assertSame("The input does not fit the tool's schema: api: expected a string, got a list; sort: expected one of score, provider, price, got \"cheapest\"; limit: expected a number, got a string", $e->getMessage());
        }
        try {
            Datacircle::provider([], $client);
            self::fail('no refusal');
        } catch (\InvalidArgumentException $e) {
            self::assertSame("The input does not fit the tool's schema: slug: required", $e->getMessage());
        }
        self::assertSame([], $http->requests);

        // null and an empty number are left out, a fraction is cut.
        Datacircle::searchPlans(['api' => null, 'query' => 'pdl', 'sort' => '', 'limit' => '12.7', 'offset' => 20], $client);
        self::assertSame(['/v1/plans?query=pdl&limit=12&offset=20'], $http->asked());

        self::assertSame("Give a provider's slug or name, like bright-data or Bright Data", Datacircle::provider(['slug' => ' - '], $client)['error']);
    }

    public function testATooManyIsWaitedForOnce(): void
    {
        $answers = [new Response(429, ['Retry-After' => '1']), FakeHttp::json(self::APIS)];
        $client = self::client(static function () use (&$answers) { return array_shift($answers); }, $http);
        $started = microtime(true);
        self::assertSame(3, Datacircle::listApis([], $client)['total']);
        self::assertGreaterThanOrEqual(0.9, microtime(true) - $started);
        self::assertCount(2, $http->requests);
    }

    public function testOtherFailuresThrow(): void
    {
        $down = self::client(static fn () => new Response(503));
        try {
            Datacircle::listApis([], $down);
            self::fail('no failure');
        } catch (\RuntimeException $e) {
            self::assertSame('datacircle: 503 Service Unavailable', $e->getMessage());
        }

        $offline = self::client(static function (RequestInterface $request) {
            throw new class('Could not resolve host', $request) extends \RuntimeException implements NetworkExceptionInterface {
                public function __construct(string $message, private RequestInterface $request) { parent::__construct($message); }

                public function getRequest(): RequestInterface { return $this->request; }
            };
        });
        $this->expectExceptionMessage('datacircle: Could not resolve host');
        Datacircle::provider(['slug' => 'pdl'], $offline);
    }

    public function testSlugs(): void
    {
        self::assertSame('bright-data', Client::slugify(' Bright Data '));
        self::assertSame('people-data-labs-pdl', Client::slugify('People Data Labs (PDL)'));
        self::assertSame('a-leads', Client::slugify('A-Leads'));
        self::assertSame('societe-info', Client::slugify('Société.info'));
    }
}
