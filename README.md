<p align="center">
  <img src="https://raw.githubusercontent.com/plin-code/job-boards-bamboohr/main/art/banner.png" alt="Job Boards BambooHR">
</p>

# Job Boards BambooHR

BambooHR connector for the [plin-code](https://github.com/plin-code) job boards family. Like Personio, BambooHR puts the company slug in the hostname rather than in the path, and the whole board comes back in a single answer with no pagination and no credentials.

```
GET https://{slug}.bamboohr.com/careers/list
{
  "meta": { "totalCount": 1 },
  "result": [
    {
      "id": "1",
      "jobOpeningName": "Accounting",
      "departmentLabel": "Accounting",
      "location": { "city": "provo ", "state": "Utah" },
      "atsLocation": { "country": null, "state": null, "province": null, "city": null },
      "isRemote": null,
      "locationType": "0"
    }
  ]
}
```

It implements `PlinCode\JobBoards\Contracts\JobBoardClient` from [`plin-code/job-boards-core`](https://github.com/plin-code/job-boards-core), so it is interchangeable with every other connector in the family. Generated from [`plin-code/job-boards-skeleton`](https://github.com/plin-code/job-boards-skeleton).

## Read this before you rely on it

This is the endpoint the BambooHR careers widget calls, not a documented public API. BambooHR publishes no REST endpoint for job postings, so the field names above are observed from live boards rather than promised by a contract, and they can be renamed in a release without notice. The connector is written defensively because of it: every field is read through a guard, an unrecognised payload yields an empty list rather than an exception, and `rawPayload` always carries the untouched row so a consumer can recover anything the mapping drops.

## Installation

```bash
composer require plin-code/job-boards-bamboohr
```

## Framework agnostic on purpose

`BambooHrClient` takes core's `HttpClient` and an optional PSR-3 logger. It imports nothing from Laravel, so a Symfony or plain PHP consumer builds it directly:

```php
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use PlinCode\JobBoards\Http\HttpClient;
use PlinCode\JobBoards\BambooHr\BambooHrClient;

$http = new HttpClient(new Client, new HttpFactory);

$client = new BambooHrClient($http);

$jobs = $client->fetchJobsForCompany('acme');       // list<JobPostingDTO>
$name = $client->validateSlug('acme');              // ?string, the slug itself
$about = $client->fetchCompanyDescription('acme');  // always null, see below
```

`BambooHrServiceProvider` is the only Laravel aware file in the package, and all it does is that same wiring out of the container.

## Laravel usage

The provider is auto discovered.

```php
use PlinCode\JobBoards\BambooHr\BambooHrClient;

$client = app(BambooHrClient::class);

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
'base_url'         => env('JOB_BOARDS_BAMBOOHR_BASE_URL', BambooHrClient::API_BASE_URL),
'job_url_template' => env('JOB_BOARDS_BAMBOOHR_JOB_URL_TEMPLATE', BambooHrClient::JOB_URL_TEMPLATE),
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
| `location` | see below |
| `url` | built from the slug and the id: the row carries no link |
| `department` | `departmentLabel`, or `null` when empty |
| `rawPayload` | the whole row, untouched |

## Locations arrive in two shapes

`location` is the display pair the widget renders, `atsLocation` the structured record behind it. In practice one of the two is populated and the other is nulls, so the connector reads them in order:

1. `location.city` and `location.state`, joined with a comma.
2. `atsLocation.city`, `state`, `province` and `country`, joined the same way.
3. The string `Remote` when the row carries no place at all but sets `isRemote` to `true`.
4. `null`.

Values are trimmed on the way through. This is not cosmetic: the live board returns `"provo "` with a trailing space, and without the trim that padding reaches the database.

## A missing tenant does not answer 404

A slug with no careers site answers `302` to `https://www.bamboohr.com/`, and a redirect following PSR-18 client such as Guzzle turns that into a `200` carrying marketing HTML. Status alone therefore proves nothing here.

Every read is gated on the payload actually being this board's envelope. When `result` is not an array, the answer is treated as no board at all: an empty list from `fetchJobsForCompany()`, a `null` from `validateSlug()`, and a warning either way. A real tenant with nothing published is a different case and stays distinguishable, because it returns an empty `result` array and validates under its own slug.

## Timeouts

`validateSlug()` uses the shorter 15 second budget and `fetchJobsForCompany()` the full 30.

PSR-18 has no notion of a timeout, so core's `HttpClient::withTimeout()` is only honoured by clients implementing `PlinCode\JobBoards\Http\SupportsTimeout`. Guzzle's PSR-18 client does not, so these numbers are a request the transport may ignore. If timeouts matter to you, build the Guzzle client with `['timeout' => 30]` and bind it yourself, or wrap it in a small `SupportsTimeout` adapter.

## Slugs land in the hostname

Most connectors in the family put the slug in the path, where percent encoding is enough. BambooHR puts it in the host, where a `/` or an `@` is not escaped but silently retargets the request: `evil.com/x` would produce `https://evil.com/x.bamboohr.com/careers/list`. Slugs are therefore checked against a host label pattern (`[A-Za-z0-9]`, with `-` allowed in the middle) before any request goes out. A slug that fails logs a warning and yields an empty list or a `null`, like any other failure, and no request is made.

## No company description

The careers page carries no company profile, only a generic "current openings" blurb that is byte for byte the same on every tenant. `fetchCompanyDescription()` therefore returns `null` without making a request. This is not a stub: there is nothing to fetch.

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

## Depending on core

This connector needs `plin-code/job-boards-core` `^0.3` for `Response::tryJson()`, which reads a body that may not be JSON without treating that as an error. The rest of the family is still on `^0.2`.

Core is not on Packagist yet, so composer resolves it through a VCS repository declared in `composer.json`:

```json
"repositories": [
    { "type": "vcs", "url": "https://github.com/plin-code/job-boards-core.git" }
]
```

Once core is published, drop the whole `repositories` block; the constraint already says the right thing.

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
