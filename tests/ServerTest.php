<?php

declare(strict_types=1);

namespace Datacircle\LogiscapeMcp\Tests;

use Datacircle\LogiscapeMcp\Client;
use Datacircle\LogiscapeMcp\Datacircle;
use Mcp\Server\McpServer;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * The tools on a real McpServer of logiscape/mcp-sdk-php, called through its own tools/list and tools/call handlers,
 * each answer as the server writes it on the wire.
 */
final class ServerTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function call(McpServer $server, string $method, mixed $params): array
    {
        $result = $server->getServer()->getHandlers()[$method]($params);

        return json_decode(json_encode($result, JSON_UNESCAPED_SLASHES), true);
    }

    private static function client(\Closure $answer, ?FakeHttp &$http = null): Client
    {
        $http = new FakeHttp($answer);

        return new Client('https://api.test/v1', $http, new Psr17Factory());
    }

    public function testTheToolsBesideTheServersOwn(): void
    {
        $client = self::client(static function (RequestInterface $r) {
            return match ($r->getUri()->getPath()) {
                '/v1/apis/email-finder/best' => FakeHttp::json(['api' => 'Employee email', 'slug' => 'email-finder', 'total' => 1, 'plans' => [['provider' => 'Emelia', 'rank' => 1, 'secret' => 'x']]]),
                default => FakeHttp::json(['error' => 'no provider has the slug email-finder'], 404),
            };
        }, $http);
        $server = (new McpServer('test'))->tool('shout', 'Shouts.', static fn (string $text): string => strtoupper($text));
        self::assertSame($server, Datacircle::addTools($server, $client));

        $tools = array_column(self::call($server, 'tools/list', null)['tools'], null, 'name');
        self::assertSame(['shout', ...Datacircle::NAMES], array_keys($tools));
        $annotations = in_array('annotations', array_map(static fn (\ReflectionParameter $p): string => $p->getName(),
            (new \ReflectionMethod(McpServer::class, 'tool'))->getParameters()), true);
        foreach (Datacircle::NAMES as $name) {
            self::assertEquals(Datacircle::inputSchema($name), $tools[$name]['inputSchema']);
            self::assertStringContainsString('https://datacircle.dev', $tools[$name]['description']);
            if ($annotations) {
                self::assertTrue($tools[$name]['annotations']['readOnlyHint']);
                self::assertFalse($tools[$name]['annotations']['destructiveHint']);
                self::assertTrue($tools[$name]['annotations']['openWorldHint']);
            }
        }
        self::assertSame('Who is best for an API', $tools['datacircle_best_plans']['title']);

        $best = self::call($server, 'tools/call', (object) ['name' => 'datacircle_best_plans', 'arguments' => (object) ['slug' => 'email-finder', 'limit' => 1]]);
        self::assertFalse($best['isError'] ?? false);
        $answer = ['api' => 'Employee email', 'slug' => 'email-finder', 'total' => 1, 'plans' => [['rank' => 1, 'provider' => 'Emelia']]];
        self::assertSame($answer, json_decode($best['content'][0]['text'], true));
        self::assertSame($answer, $best['structuredContent']);

        $missing = self::call($server, 'tools/call', (object) ['name' => 'datacircle_provider', 'arguments' => (object) ['slug' => 'email-finder']]);
        self::assertTrue($missing['isError']);
        self::assertSame("no provider has the slug email-finder. An API's best providers: datacircle_best_plans with the API's slug, like email-finder.",
            $missing['content'][0]['text']);
        self::assertArrayNotHasKey('structuredContent', $missing);

        // The SDK checks no input against the schema: the tool does, and the model reads what it refused.
        $wrong = self::call($server, 'tools/call', (object) ['name' => 'datacircle_best_plans', 'arguments' => (object) ['limit' => 'two']]);
        self::assertTrue($wrong['isError']);
        self::assertSame("The input does not fit the tool's schema: slug: required; limit: expected a number, got a string", $wrong['content'][0]['text']);
        self::assertSame(['/v1/apis/email-finder/best?limit=1', '/v1/providers/email-finder'], $http->asked());

        self::assertSame('HI', self::call($server, 'tools/call', (object) ['name' => 'shout', 'arguments' => (object) ['text' => 'hi']])['content'][0]['text']);
    }

    public function testOneToolAlone(): void
    {
        $client = self::client(static fn () => new Response(503), $http);
        $server = Datacircle::addTool(new McpServer('test'), Datacircle::LIST_APIS, $client);
        self::assertSame([Datacircle::LIST_APIS], array_column(self::call($server, 'tools/list', null)['tools'], 'name'));
        foreach ([(object) ['name' => 'datacircle_list_apis', 'arguments' => new \stdClass()], (object) ['name' => 'datacircle_list_apis']] as $params) {
            $down = self::call($server, 'tools/call', $params);
            self::assertTrue($down['isError']);
            self::assertSame('datacircle: 503 Service Unavailable', $down['content'][0]['text']);
        }
        self::assertCount(2, $http->requests);

        $this->expectException(\InvalidArgumentException::class);
        Datacircle::addTool(new McpServer('test'), 'get_weather');
    }

    public function testAPsr18ClientNeedsItsFactory(): void
    {
        $this->expectExceptionMessage('a PSR-18 client needs its PSR-17 request factory');
        new Client(http: new FakeHttp(static fn () => new Response(200)));
    }
}
