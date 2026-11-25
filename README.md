# Datacircle tools for the MCP SDK for PHP

Find the best GTM APIs. We benchmark all the GTM APIs and we put the results on our website.

Our website: https://datacircle.dev

This package adds four tools to a server made with the [MCP SDK for PHP](https://github.com/logiscape/mcp-sdk-php) (`logiscape/mcp-sdk-php`). The tools call Datacircle's open API, https://api.datacircle.dev/v1. You need no key and no account.

| Tool | What the tool gives | Call |
| --- | --- | --- |
| `datacircle_list_apis` | Every GTM API on Datacircle, with the API's slug, number of plans, number of providers and best plan | `GET /apis` |
| `datacircle_best_plans` | One API's plans, sorted by score out of 100, best first. Each plan comes with a rank and a price per 1,000 calls | `GET /apis/{slug}/best` |
| `datacircle_search_plans` | Plans that match a provider or plan name, on all APIs or on one API. You can sort them by score, provider or price | `GET /plans` |
| `datacircle_provider` | The APIs a provider sells, and the provider's rank on each API | `GET /providers/{slug}` |

## Install

```bash
composer require datacircle/logiscape-mcp-tools
```

You need PHP 8.1 or later and `logiscape/mcp-sdk-php` 1.7.0 or later.

## Add the tools to your server

Pass your `McpServer` to `Datacircle::addTools()`:

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Datacircle\LogiscapeMcp\Datacircle;
use Mcp\Server\McpServer;

$server = new McpServer('my-server');

$server->tool('add-numbers', 'Adds two numbers together', function (float $a, float $b): string {
    return 'Sum: '.($a + $b);
});

Datacircle::addTools($server)->run();
```

Your own tools keep working next to ours. To add only one of our tools, call `Datacircle::addTool($server, Datacircle::BEST_PLANS)`. From the command line, `run()` serves the tools over stdio. On a web server, such as Apache or PHP-FPM, `run()` serves them over HTTP.

To try the server, open the MCP Inspector:

```bash
npx @modelcontextprotocol/inspector php server.php
```

## What the model reads

Each tool gives a JSON object, as text and as structured content. Each plan comes with a rank, provider, plan, score, price per 1,000 (`perThousand`, in dollars) and a page on datacircle.dev. That page lists every field.

For an unknown slug, the tool gives an error result with the API's message. If the network fails, the error gives the network's message. The model can then call the tool again and keep going. The SDK does not check the arguments. Each tool checks them against the tool's input schema. If the arguments are wrong, the error result says which one and why.

The annotations mark all four tools as read only. This needs version 2.0 of the SDK or later.

## Make each call yourself

You can make each call from PHP without running the MCP server. You get the tool's answer as an array:

```php
use Datacircle\LogiscapeMcp\Datacircle;

$answer = Datacircle::bestPlans(['slug' => 'email-finder', 'limit' => 3]);
print_r($answer['plans']);
```

The other calls are `Datacircle::listApis()`, `Datacircle::searchPlans()` and `Datacircle::provider()`.

## Network

Each tool call sends HTTPS GET requests to https://api.datacircle.dev/v1 and nowhere else. Most calls send one request. `datacircle_search_plans` sends up to three when the first search finds nothing. The requests hold only the tool's own arguments: no key and nothing from your chats. The API takes up to 60 calls a minute from one IP address. The tool waits once, for a minute at most, when the API says to wait. The package stores nothing.

The tools call through PHP's curl extension, as the SDK does. Without curl, they use PHP's own HTTPS streams. You can also call through a PSR-18 client of your own, such as Guzzle. Build a `Client` from your PSR-18 client and that PSR-18 client's request factory. Pass that `Client` as the last argument of `addTools()`:

```php
use Datacircle\LogiscapeMcp\Client;
use Datacircle\LogiscapeMcp\Datacircle;

$client = new Client(http: new \GuzzleHttp\Client(['timeout' => 20]), requests: new \GuzzleHttp\Psr7\HttpFactory());
Datacircle::addTools($server, $client);
```

Use this to add a proxy or a timeout.

## Links

- Datacircle: https://datacircle.dev
- The API: https://datacircle.dev/api
- The API's spec: https://api.datacircle.dev/v1/openapi.json
- What each key means: https://datacircle.dev/fields

MIT license.
