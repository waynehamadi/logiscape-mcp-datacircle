<?php

declare(strict_types=1);

namespace Datacircle\LogiscapeMcp;

use Mcp\Server\McpServer;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use Mcp\Types\ToolAnnotations;

/**
 * Datacircle's four tools for the MCP SDK for PHP (logiscape/mcp-sdk-php): find the best GTM APIs. https://datacircle.dev
 *
 * Each tool calls Datacircle's open API, https://api.datacircle.dev/v1, which needs no key. addTools() adds them to an
 * McpServer by its tool(), beside the server's own tools. Each tool's result is a JSON object, as text and as
 * structured content. What goes wrong is a tool error (isError) the model reads: an answer of 4xx in the API's words,
 * an input the tool cannot read, a failed network.
 */
final class Datacircle
{
    public const LIST_APIS = 'datacircle_list_apis';
    public const BEST_PLANS = 'datacircle_best_plans';
    public const SEARCH_PLANS = 'datacircle_search_plans';
    public const PROVIDER = 'datacircle_provider';

    /** The four tools' names, in their order. Their answers name each other by these names. */
    public const NAMES = [self::LIST_APIS, self::BEST_PLANS, self::SEARCH_PLANS, self::PROVIDER];

    private const TITLES = [
        self::LIST_APIS => 'List the GTM APIs',
        self::BEST_PLANS => 'Who is best for an API',
        self::SEARCH_PLANS => 'Search the plans',
        self::PROVIDER => 'One provider on every API',
    ];

    private const ABOUT = [
        self::LIST_APIS => "Lists the GTM APIs on Datacircle's sheet (https://datacircle.dev). GTM means go-to-market. GTM APIs are the APIs sales and marketing teams use. Gives each API's slug, how many plans and providers it has and its best plan. Call it first to find an API's slug.",
        self::BEST_PLANS => "Gives who is best for one API, like email-finder: its best providers' plans, best first by their score out of 100 on Datacircle's benchmark (https://datacircle.dev), each with its rank, provider, plan, $ per 1,000 calls and its page. perThousand is a plan's price of 1,000 items in dollars, pay the plan's whole price in its currency.",
        self::SEARCH_PLANS => "Searches every plan on Datacircle's sheet (https://datacircle.dev), or one API's, by provider or plan name, sorted by score, provider or price. Gives how many were found and up to limit of them. perThousand is a plan's price of 1,000 items in dollars, pay the plan's whole price in its currency.",
        self::PROVIDER => "Gives each API one provider sells and its rank on each on Datacircle's benchmark (https://datacircle.dev), with its best plan there, its best rank first. total is how many APIs it sells, ranked on how many of them it has a rank. For an API's best providers, call datacircle_best_plans.",
    ];

    /** Each tool's arguments: [JSON schema, kind, needed], the kind what the check reads the model's value as. */
    private const FIELDS = [
        self::LIST_APIS => [
            'query' => [['type' => 'string', 'description' => "Words of the API's name or slug, like email or linkedin profile: the APIs that hold the most of them come first. Left out, the sheet's order"], 'text', false],
            'limit' => [['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'description' => 'How many APIs to give, 1 to 200. 20 when left out: the sheet has about 150'], 'number', false],
        ],
        self::BEST_PLANS => [
            'slug' => [['type' => 'string', 'description' => "The API's slug, like email-finder or linkedin-profile, as datacircle_list_apis gives it"], 'text', true],
            'limit' => [['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'description' => 'How many plans to give, 1 to 50. 10 when left out'], 'number', false],
        ],
        self::SEARCH_PLANS => [
            'api' => [['type' => 'string', 'description' => "Only this API's plans: its name or slug, like email-finder. A provider's name goes in query, never here. Left out, every API"], 'text', false],
            'query' => [['type' => 'string', 'description' => "Words that must all appear in the provider's or plan's name, like bright data. Left out, every plan"], 'text', false],
            'sort' => [['type' => 'string', 'enum' => ['score', 'provider', 'price'], 'description' => 'score (best first, the default), provider (A to Z) or price ($ per 1,000, lowest first)'], 'choice', false],
            'limit' => [['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'description' => 'How many plans to give, 1 to 50. 10 when left out'], 'number', false],
            'offset' => [['type' => 'integer', 'minimum' => 0, 'description' => 'How many found plans to skip first. 0 when left out'], 'number', false],
        ],
        self::PROVIDER => [
            'slug' => [['type' => 'string', 'description' => "The provider's slug, like bright-data, or its name, like Bright Data: the name is made a slug"], 'text', true],
        ],
    ];

    /** Tells a model that sent a provider's name to an API's tool where a provider's plans are. */
    private const FOR_PROVIDER = "A provider's plans: datacircle_search_plans with its name as query, like bright data, or datacircle_provider.";

    /** Tells a model that sent an API's slug to the provider's tool where an API's best providers are. */
    private const FOR_API = "An API's best providers: datacircle_best_plans with the API's slug, like email-finder.";

    /**
     * Adds the four tools to an McpServer: datacircle_list_apis (GET /apis), datacircle_best_plans
     * (GET /apis/{slug}/best), datacircle_search_plans (GET /plans) and datacircle_provider (GET /providers/{slug}).
     *
     * @param Client|null $client Where and through what the tools call: Datacircle's API through curl when left out.
     *
     * @return McpServer the same server, for its next call (its run())
     */
    public static function addTools(McpServer $server, ?Client $client = null): McpServer
    {
        $client ??= new Client();
        foreach (self::NAMES as $name) {
            self::addTool($server, $name, $client);
        }

        return $server;
    }

    /**
     * Adds one of the four tools to an McpServer, by its name.
     *
     * @return McpServer the same server, for its next call
     *
     * @throws \InvalidArgumentException a name that is none of the four tools'
     */
    public static function addTool(McpServer $server, string $name, ?Client $client = null): McpServer
    {
        $arguments = [
            'name' => $name,
            'description' => self::ABOUT[$name] ?? '',
            'callback' => self::handler($name, $client ?? new Client()),
            'title' => self::TITLES[$name] ?? null,
            'inputSchema' => self::inputSchema($name),
            'annotations' => new ToolAnnotations(
                title: self::TITLES[$name] ?? null,
                readOnlyHint: true,
                destructiveHint: false,
                idempotentHint: true,
                openWorldHint: true,
            ),
        ];

        // tool() takes annotations from 2.0 and a title and an input schema from 1.7: each goes in where it is taken.
        return $server->tool(...array_intersect_key($arguments, array_flip(self::toolParameters())));
    }

    /** @var list<string>|null */
    private static ?array $toolParameters = null;

    /**
     * The names of McpServer::tool()'s parameters, in the SDK installed.
     *
     * @return list<string>
     */
    private static function toolParameters(): array
    {
        return self::$toolParameters ??= array_map(
            static fn (\ReflectionParameter $parameter): string => $parameter->getName(),
            (new \ReflectionMethod(McpServer::class, 'tool'))->getParameters(),
        );
    }

    /**
     * Whether a tool is one of Datacircle's, by its name.
     */
    public static function handles(string $name): bool
    {
        return in_array($name, self::NAMES, true);
    }

    /**
     * A tool's arguments as a JSON schema, as tools/list gives it.
     *
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException a name that is none of the four tools'
     */
    public static function inputSchema(string $name): array
    {
        $fields = self::fields($name);
        $schema = ['type' => 'object', 'properties' => array_map(static fn (array $field): array => $field[0], $fields)];
        $required = array_keys(array_filter($fields, static fn (array $field): bool => $field[2]));
        if ([] !== $required) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    // The same calls without a server or a model: each gives the answer the model would read, as an array, an answer
    // of 4xx as ['error' => the API's words]. An input the tool cannot read throws \InvalidArgumentException.

    /**
     * GET /apis: the APIs, as datacircle_list_apis gives them.
     *
     * @param array{query?: string|null, limit?: int|null} $input
     *
     * @return array<string, mixed>
     */
    public static function listApis(array $input = [], ?Client $client = null): array
    {
        return self::call(self::LIST_APIS, self::check(self::LIST_APIS, $input), $client ?? new Client());
    }

    /**
     * GET /apis/{slug}/best: an API's plans best first, as datacircle_best_plans gives them.
     *
     * @param array{slug: string, limit?: int|null} $input
     *
     * @return array<string, mixed>
     */
    public static function bestPlans(array $input, ?Client $client = null): array
    {
        return self::call(self::BEST_PLANS, self::check(self::BEST_PLANS, $input), $client ?? new Client());
    }

    /**
     * GET /plans: the plans found, as datacircle_search_plans gives them.
     *
     * @param array{api?: string|null, query?: string|null, sort?: 'score'|'provider'|'price'|null, limit?: int|null, offset?: int|null} $input
     *
     * @return array<string, mixed>
     */
    public static function searchPlans(array $input = [], ?Client $client = null): array
    {
        return self::call(self::SEARCH_PLANS, self::check(self::SEARCH_PLANS, $input), $client ?? new Client());
    }

    /**
     * GET /providers/{slug}: where a provider ranks, as datacircle_provider gives it.
     *
     * @param array{slug: string} $input
     *
     * @return array<string, mixed>
     */
    public static function provider(array $input, ?Client $client = null): array
    {
        return self::call(self::PROVIDER, self::check(self::PROVIDER, $input), $client ?? new Client());
    }

    /**
     * The tool's handler: the server gives each argument by its name, as the client sent it, and checks none against
     * the input schema. Its parameters have no type and a default, so that the server casts nothing and refuses
     * nothing, and the check below reads each value.
     */
    private static function handler(string $name, Client $client): \Closure
    {
        $result = static fn (array $input): CallToolResult => self::result($name, $input, $client);

        return match ($name) {
            self::LIST_APIS => static fn ($query = null, $limit = null): CallToolResult => $result(['query' => $query, 'limit' => $limit]),
            self::BEST_PLANS => static fn ($slug = null, $limit = null): CallToolResult => $result(['slug' => $slug, 'limit' => $limit]),
            self::SEARCH_PLANS => static fn ($api = null, $query = null, $sort = null, $limit = null, $offset = null): CallToolResult => $result(
                ['api' => $api, 'query' => $query, 'sort' => $sort, 'limit' => $limit, 'offset' => $offset]),
            self::PROVIDER => static fn ($slug = null): CallToolResult => $result(['slug' => $slug]),
            default => throw new \InvalidArgumentException('datacircle: '.self::quote($name)." is not one of its tools' names: ".implode(', ', self::NAMES)),
        };
    }

    /**
     * One call's result: the answer as JSON text and as structured content. What goes wrong is a tool error (isError)
     * of its own words, which the model reads and goes on from.
     *
     * @param array<string, mixed> $input
     */
    private static function result(string $name, array $input, Client $client): CallToolResult
    {
        try {
            $answer = self::call($name, self::check($name, $input), $client);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return new CallToolResult(content: [new TextContent($e->getMessage())], isError: true);
        }
        if (isset($answer['error'])) {
            return new CallToolResult(content: [new TextContent((string) $answer['error'])], isError: true);
        }

        return new CallToolResult(content: [new TextContent(self::json($answer))], structuredContent: $answer);
    }

    /**
     * @return array<string, array{array<string, mixed>, string, bool}>
     */
    private static function fields(string $name): array
    {
        return self::FIELDS[$name] ?? throw new \InvalidArgumentException('datacircle: '.self::quote($name)." is not one of its tools' names: ".implode(', ', self::NAMES));
    }

    /**
     * The input checked against the tool's fields, looser than the schema, as the SDK checks none: null is a key left out, and so is an empty number or choice, a key of
     * its own is dropped, a number sent as a string is read as one, a fraction cut to its whole part. What it refuses,
     * the model reads why.
     *
     * @param array<mixed> $input
     *
     * @return array<string, string|int|null>
     *
     * @throws \InvalidArgumentException
     */
    private static function check(string $name, array $input): array
    {
        $checked = [];
        $wrong = [];
        foreach (self::fields($name) as $key => [$schema, $kind, $needed]) {
            $value = $input[$key] ?? null;
            if (null === $value || ('' === $value && 'text' !== $kind)) {
                if ($needed) {
                    $wrong[] = $key.': required';
                }
                $checked[$key] = null;

                continue;
            }
            if ('number' === $kind) {
                if (!is_numeric($value)) {
                    $wrong[] = $key.': expected a number, got '.self::kind($value);

                    continue;
                }
                $checked[$key] = (int) $value;
            } elseif (!is_string($value)) {
                $wrong[] = $key.': expected a string, got '.self::kind($value);
            } elseif ('choice' === $kind && !in_array($value, $schema['enum'], true)) {
                $wrong[] = $key.': expected one of '.implode(', ', $schema['enum']).', got '.json_encode($value);
            } else {
                $checked[$key] = $value;
            }
        }
        if ([] !== $wrong) {
            throw new \InvalidArgumentException("The input does not fit the tool's schema: ".implode('; ', $wrong));
        }

        return $checked;
    }

    /**
     * @param array<string, string|int|null> $input
     *
     * @return array<string, mixed>
     */
    private static function call(string $name, array $input, Client $client): array
    {
        try {
            return match ($name) {
                self::LIST_APIS => self::listApisRun($client, $input),
                self::BEST_PLANS => self::bestPlansRun($client, $input),
                self::SEARCH_PLANS => self::searchPlansRun($client, $input),
                self::PROVIDER => self::providerRun($client, $input),
            };
        } catch (ApiError $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * @param array<string, string|int|null> $input
     *
     * @return array<string, mixed>
     */
    private static function listApisRun(Client $client, array $input): array
    {
        $apis = Client::objects($client->get('/apis'));
        $query = (string) $input['query'];
        $words = preg_split('/\s+/', strtolower(str_replace('-', ' ', $query)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $found = [];
        foreach ($apis as $order => $api) {
            $name = strtolower((string) ($api['api'] ?? '').' '.str_replace('-', ' ', (string) ($api['slug'] ?? '')));
            $matched = count(array_filter($words, static fn (string $word): bool => str_contains($name, $word)));
            if ($matched > 0 || [] === $words) {
                $found[] = [$matched, $order, Client::brief($api, ['api', 'slug', 'plans', 'providers', 'best', 'bestPage', 'sheetPage'])];
            }
        }
        // The most words matched first, the sheet's order within each.
        usort($found, static fn (array $a, array $b): int => [$b[0], $a[1]] <=> [$a[0], $b[1]]);

        $answer = [
            'total' => count($found),
            'apis' => array_column(array_slice($found, 0, self::bounded($input['limit'], 200) ?? 20), 2),
        ];
        if ([] !== $words && ([] === $found || $found[0][0] < count($words))) {
            // A provider's name sent as an API's, like Bright Data, whose data matches many APIs: where its plans are.
            $answer['note'] = "No API's name holds every word of ".self::quote($query).". A provider's plans: datacircle_search_plans with its name as query, or datacircle_provider.";
        }

        return $answer;
    }

    /**
     * @param array<string, string|int|null> $input
     *
     * @return array<string, mixed>
     */
    private static function bestPlansRun(Client $client, array $input): array
    {
        $api = Client::slugify((string) $input['slug']);
        if ('' === $api) {
            return ['error' => "Give an API's slug, like email-finder: datacircle_list_apis gives every slug. ".self::FOR_PROVIDER];
        }

        try {
            $answer = $client->get('/apis/'.$api.'/best', ['limit' => self::bounded($input['limit'], 50)]);
        } catch (ApiError $e) {
            if (404 !== $e->status) {
                throw $e;
            }

            // A provider's name sent as the API's slug: the API's words, and where a provider's plans are.
            return ['error' => $e->getMessage().". datacircle_list_apis gives every API's slug. ".self::FOR_PROVIDER];
        }
        $answer['plans'] = Client::briefPlans($answer['plans'] ?? []);

        return $answer;
    }

    /**
     * @param array<string, string|int|null> $input
     *
     * @return array<string, mixed>
     */
    private static function searchPlansRun(Client $client, array $input): array
    {
        $search = static fn (string $api, string $query): array => $client->get('/plans', [
            'api' => trim($api),
            'query' => trim($query),
            'sort' => $input['sort'],
            'limit' => self::bounded($input['limit'], 50),
            'offset' => null !== $input['offset'] && $input['offset'] > 0 ? $input['offset'] : null,
        ]);
        $api = (string) $input['api'];
        $query = (string) $input['query'];
        $notes = [];

        try {
            $answer = $search($api, $query);
        } catch (ApiError $e) {
            if (400 !== $e->status || !str_contains($e->getMessage(), 'api must be') || '' !== trim($query)) {
                throw $e;
            }
            // A provider's name sent as the API: the plans whose provider or plan name holds it.
            [$api, $query] = ['', $api];
            $answer = $search($api, $query);
            $notes[] = 'No API is named '.self::quote((string) $input['api']).': these are the plans whose provider or plan name holds it.';
        }
        if (0 === ($answer['total'] ?? null) && str_contains($query, '-')) {
            // A slug sent as a name, like bright-data, which no name holds: its words, like bright data. A name with a
            // hyphen, like A-Leads, is found as it is first.
            $words = implode(' ', preg_split('/[\s-]+/', $query, -1, PREG_SPLIT_NO_EMPTY) ?: []);

            try {
                $answer = $search($api, $words);
                $notes[] = "No provider's or plan's name holds ".self::quote($query).': these are the plans whose name holds '.self::quote($words).'.';
            } catch (ApiError) {
                // The first answer stands.
            }
        }
        $answer['plans'] = Client::briefPlans($answer['plans'] ?? []);
        if ([] !== $notes) {
            $answer['note'] = implode(' ', $notes);
        }

        return $answer;
    }

    /**
     * @param array<string, string|int|null> $input
     *
     * @return array<string, mixed>
     */
    private static function providerRun(Client $client, array $input): array
    {
        $provider = Client::slugify((string) $input['slug']);
        if ('' === $provider) {
            return ['error' => "Give a provider's slug or name, like bright-data or Bright Data"];
        }

        try {
            $answer = $client->get('/providers/'.$provider);
        } catch (ApiError $e) {
            if (404 !== $e->status) {
                throw $e;
            }

            // An API's slug sent as a provider's, like email-finder: the API's words, and where an API's best providers are.
            return ['error' => $e->getMessage().'. '.self::FOR_API];
        }

        $apis = [];
        foreach (Client::objects($answer['apis'] ?? []) as $order => $entry) {
            $plans = Client::objects($entry['plans'] ?? []);
            $kept = Client::brief($entry, ['api', 'slug', 'rank', 'bestPage']);
            $kept['plans'] = count($plans);
            $best = self::bestPlan($plans);
            if (null !== $best) {
                $kept['best'] = Client::brief($best, ['plan', 'score', 'perThousand', 'page']);
            }
            $apis[] = [$order, $kept];
        }
        // Its best rank first, the APIs where it has none last, in the API's order.
        $rank = static fn (array $api): float|int => is_int($api['rank'] ?? null) || is_float($api['rank'] ?? null) ? $api['rank'] : INF;
        usort($apis, static fn (array $a, array $b): int => [$rank($a[1]), $a[0]] <=> [$rank($b[1]), $b[0]]);
        $apis = array_column($apis, 1);

        unset($answer['apis']);
        // How many it sells and is ranked on before the list, so that a model reads them first.
        $answer['total'] = count($apis);
        $answer['ranked'] = count(array_filter($apis, static fn (array $api): bool => INF !== $rank($api)));
        $answer['apis'] = $apis;

        return $answer;
    }

    /**
     * A provider's best plan on an API: its lowest rank, the first when none has a rank.
     *
     * @param list<array<string, mixed>> $plans
     *
     * @return array<string, mixed>|null
     */
    private static function bestPlan(array $plans): ?array
    {
        $best = null;
        foreach ($plans as $plan) {
            $rank = $plan['rank'] ?? null;
            if ((is_int($rank) || is_float($rank)) && (null === $best || $rank < $best['rank'])) {
                $best = $plan;
            }
        }

        return $best ?? $plans[0] ?? null;
    }

    /** A limit the model sent, within the API's bounds, or null for the API's default. */
    private static function bounded(int|string|null $limit, int $high): ?int
    {
        return null === $limit || (int) $limit < 1 ? null : min($high, (int) $limit);
    }

    /**
     * @param array<string, mixed> $value
     */
    private static function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private static function quote(string $text): string
    {
        return json_encode($text, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '""';
    }

    private static function kind(mixed $value): string
    {
        return match (true) {
            is_array($value) => array_is_list($value) ? 'a list' : 'an object',
            is_bool($value) => 'a boolean',
            is_string($value) => 'a string',
            default => get_debug_type($value),
        };
    }
}
