<p align="center">
  <img src="https://raw.githubusercontent.com/plin-code/job-boards-bamboohr/main/art/banner.png" alt="Job Boards BambooHR">
</p>

# Job Boards BambooHR

<p align="center">
    <a href="https://packagist.org/packages/plin-code/job-boards-bamboohr"><img src="https://img.shields.io/packagist/v/plin-code/job-boards-bamboohr.svg?style=flat-square" alt="Packagist"></a>
    <a href="https://packagist.org/packages/plin-code/job-boards-bamboohr"><img src="https://img.shields.io/packagist/php-v/plin-code/job-boards-bamboohr.svg?style=flat-square" alt="PHP from Packagist"></a>
    <a href="https://packagist.org/packages/plin-code/job-boards-bamboohr"><img src="https://badge.laravel.cloud/badge/plin-code/job-boards-bamboohr?style=flat" alt="Laravel versions"></a>
    <a href="https://packagist.org/packages/plin-code/job-boards-bamboohr"><img src="https://img.shields.io/packagist/dt/plin-code/job-boards-bamboohr.svg?style=flat-square" alt="Total Downloads"></a>
</p>

BambooHR connector for the [plin-code](https://github.com/plin-code) job boards family. It reads the public BambooHR careers board, which needs no credentials and returns a whole board in one request. Like Personio, the slug is a subdomain rather than a path segment:

```
GET https://{slug}.bamboohr.com/careers/list
{ "meta": { "totalCount": 1 },
  "result": [ { "id": "1", "jobOpeningName": "Accounting", "departmentLabel": "Accounting",
                "location": { "city": "provo ", "state": "Utah" }, "isRemote": null } ] }
```

It implements `PlinCode\JobBoards\Contracts\JobBoardClient` from [`plin-code/job-boards-core`](https://github.com/plin-code/job-boards-core), so it is interchangeable with every other connector in the family. Generated from [`plin-code/job-boards-skeleton`](https://github.com/plin-code/job-boards-skeleton).

## Installation

```bash
composer require plin-code/job-boards-bamboohr
```

## The endpoint is not a documented API, the most important thing here

Every other connector in the family reads an API the provider publishes and documents. This one does not. BambooHR ships no REST endpoint for job postings, so this is the endpoint its own careers widget calls: reachable, stable enough in practice, and unannounced. The field names above are observed from live boards rather than promised by a contract, and they can be renamed in a release without warning.

The connector is written for that. Every field is read through a guard, an unrecognised payload yields an empty list rather than an exception, and `rawPayload` always carries the untouched row so a consumer can recover anything the mapping drops. Treat a board that suddenly returns nothing as a signal to check the payload shape, not as a company that stopped hiring.

## Framework agnostic on purpose

`BambooHRClient` takes core's `HttpClient` and an optional PSR-3 logger. It imports nothing from Laravel, so a Symfony or plain PHP consumer builds it directly:

```php
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use PlinCode\JobBoards\Http\HttpClient;
use PlinCode\JobBoards\BambooHR\BambooHRClient;

$http = new HttpClient(new Client, new HttpFactory);

$client = new BambooHRClient($http);

$jobs = $client->fetchJobsForCompany('acme');       // list<JobPostingDTO>
$name = $client->validateSlug('acme');              // ?string, the slug itself
$about = $client->fetchCompanyDescription('acme');  // always null, see below
```

`BambooHRServiceProvider` is the only Laravel aware file in the package, and all it does is that same wiring out of the container.

## Laravel usage

The provider is auto discovered.

```php
use PlinCode\JobBoards\BambooHR\BambooHRClient;

$client = app(BambooHRClient::class);

foreach ($client->fetchJobsForCompany('acme') as $job) {
    JobPosting::updateOrCreate(
        ['external_id' => $job->externalId],
        $job->toArray(),
    );
}
```

Publish the config to change the URL templates, the timeouts or the request headers:

```bash
php artisan vendor:publish --tag=job-boards-bamboohr-config
```

```php
'base_url'         => env('JOB_BOARDS_BAMBOOHR_BASE_URL', BambooHRClient::API_BASE_URL),
'job_url_template' => env('JOB_BOARDS_BAMBOOHR_JOB_URL_TEMPLATE', BambooHRClient::JOB_URL_TEMPLATE),
'timeout'          => env('JOB_BOARDS_BAMBOOHR_TIMEOUT', 30),
'lookup_timeout'   => env('JOB_BOARDS_BAMBOOHR_LOOKUP_TIMEOUT', 15),
'headers'          => ['Accept' => 'application/json'],
```

`base_url` is a `sprintf` template with one `%s` for the slug, not a prefix, because BambooHR puts the slug in the hostname. `job_url_template` takes the slug then the posting id.

The provider binds a PSR-18 client and a PSR-17 factory with `bindIf`, so an application that already binds its own keeps it. It deliberately does **not** bind `JobBoardClient` itself: several connectors implement that interface and would fight over the binding. Bind the one you want in your own application service provider.

## Mapping

| `JobPostingDTO` | BambooHR field |
| --- | --- |
| `externalId` | `id`, cast to string and trimmed |
| `title` | `jobOpeningName`, trimmed, falling back to `'Untitled Position'` |
| `location` | `location`, then `atsLocation`, then `isRemote`, see below |
| `url` | built from the slug and the id: the row carries no link |
| `department` | `departmentLabel`, or `null` when empty |
| `rawPayload` | the whole row, untouched |

## Locations arrive in two shapes

`location` is the display pair the widget renders, `atsLocation` the structured record behind it. In practice one of the two is populated and the other is nulls, so they are read in order:

1. `location.city` and `location.state`, joined with a comma.
2. `atsLocation.city`, `state`, `province` and `country`, joined the same way.
3. The string `Remote`, when the row carries no place at all but sets `isRemote` to `true`.
4. `null`.

Values are trimmed on the way through. This is not cosmetic: the live board returns `"provo "` with a trailing space, and without the trim that padding reaches the database.

## A missing tenant does not answer 404

A slug with no careers site answers `302` to `https://www.bamboohr.com/`, and a redirect following PSR-18 client such as Guzzle turns that into a `200` carrying marketing HTML. Status alone therefore proves nothing here.

Every read is gated on the payload actually being this board's envelope. When `result` is not an array, the answer is treated as no board at all: an empty list from `fetchJobsForCompany()`, a `null` from `validateSlug()`, and a warning either way. A real tenant with nothing published is a different case and stays distinguishable, because it returns an empty `result` array and validates under its own slug.

## Slugs land in the hostname

Most connectors in the family put the slug in the path, where percent encoding is enough. BambooHR puts it in the host, where a `/` or an `@` is not escaped but silently retargets the request: `evil.com/x` would produce `https://evil.com/x.bamboohr.com/careers/list`. Slugs are therefore checked against a host label pattern (`[A-Za-z0-9]`, with `-` allowed in the middle) before any request goes out. A slug that fails logs a warning and yields an empty list or a `null`, and no request is made.

## No company description

The careers page carries no company profile, only a generic "current openings" blurb that is byte for byte the same on every tenant. `fetchCompanyDescription()` returns `null` without making a request. This is not a stub: there is nothing to fetch.

`validateSlug()` is in the same position. BambooHR puts no company name in the payload, so a board that answers validates under the slug it was asked about.

## Error handling

`fetchJobsForCompany()` never throws at the caller. Everything is logged through the injected PSR-3 logger and an empty list comes back, so one broken company cannot abort a sync over hundreds of them:

| Situation | Level | Message |
| --- | --- | --- |
| slug is not a usable host label | `warning` | `BambooHR slug is not a usable host label` |
| non 2xx status | `warning` | `BambooHR careers request failed` |
| answer is not this board's envelope | `warning` | `BambooHR careers response is not a job board payload` |
| DNS failure, refused connection, timeout | `error` | `BambooHR connection error` |
| anything else | `error` | `Unexpected error fetching BambooHR jobs` |

A board that exists and publishes nothing logs nothing: an empty result set is a real answer, not a problem.

Every record carries `company_slug`. With no logger passed, a `NullLogger` is used and everything is silent. `validateSlug()` is silent by contract except for the slug check, and returns `null` for every failure including a dead connection.

## Timeouts

`validateSlug()` uses the shorter 15 second budget and `fetchJobsForCompany()` the full 30.

PSR-18 has no notion of a timeout, so core's `HttpClient::withTimeout()` is only honoured by clients implementing `PlinCode\JobBoards\Http\SupportsTimeout`. Guzzle's PSR-18 client does not, so these numbers are a request the transport may ignore. If timeouts matter to you, build the Guzzle client with `['timeout' => 30]` and bind it yourself, or wrap it in a small `SupportsTimeout` adapter.

## Depending on core

```json
"require": {
    "plin-code/job-boards-core": "^0.3"
}
```

Core is on Packagist, so that constraint is all this package needs: there is no `repositories` block to carry. Do **not** commit a `path` repository pointing at a sibling checkout of core. It resolves against the layout of one machine, and the package then fails to install from a fresh clone anywhere else.

The constraint starts at `^0.3` rather than `^0.2` like the rest of the family, because this connector reads `Response::tryJson()`, which core added in v0.3.0.

## Development

```bash
composer install
composer lint          # pint, writes
composer lint:check    # pint, read only
composer analyse       # phpstan level 10, matching core
composer test:unit     # pest
composer test          # analyse + lint:check + test:unit
```

`tests/Unit` builds the client against a faked PSR-18 client and boots no framework. `tests/Feature` boots Testbench and covers the service provider only. The PSR-18 test doubles come from core, under `PlinCode\JobBoards\Testing`.

`tests/Fixtures/bamboohr-demo-list.json` is a real answer captured from a live board rather than a hand written sample, which is what keeps the padded city and the all null `atsLocation` in the test suite.

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
