<?php

declare(strict_types=1);

use PlinCode\JobBoards\BambooHr\BambooHrClient;
use PlinCode\JobBoards\Data\JobPostingDTO;
use PlinCode\JobBoards\Testing\FakePsrClient;
use PlinCode\JobBoards\Testing\RecordingLogger;

function bambooHrClient(FakePsrClient $fake, ?RecordingLogger $logger = null): BambooHrClient
{
    return new BambooHrClient($fake->asHttpClient(), logger: $logger);
}

/**
 * The board as it really answers, captured from a live tenant. Everything the
 * mapper has to cope with is in here: an id that is a string, a padded city,
 * an atsLocation of nothing but nulls, and a null isRemote.
 */
function bambooHrFixture(): string
{
    return (string) file_get_contents(__DIR__.'/../Fixtures/bamboohr-demo-list.json');
}

/**
 * @param  array<array-key, mixed>  $rows
 */
function bambooHrBoard(array $rows): FakePsrClient
{
    return (new FakePsrClient)->respondWithJson([
        'meta' => ['totalCount' => count($rows)],
        'result' => $rows,
    ]);
}

it('maps the live board payload to DTOs', function (): void {
    $fake = (new FakePsrClient)->respondWith(200, bambooHrFixture(), ['Content-Type' => 'application/json']);

    $jobs = bambooHrClient($fake)->fetchJobsForCompany('demo');

    expect($jobs)->toHaveCount(1)
        ->and($jobs[0])->toBeInstanceOf(JobPostingDTO::class)
        ->and($jobs[0]->externalId)->toBe('1')
        ->and($jobs[0]->title)->toBe('Accounting')
        // "provo " arrives padded on the live board.
        ->and($jobs[0]->location)->toBe('provo, Utah')
        ->and($jobs[0]->department)->toBe('Accounting')
        ->and($jobs[0]->url)->toBe('https://demo.bamboohr.com/careers/1')
        ->and($jobs[0]->rawPayload)->toHaveKey('employmentStatusLabel')
        ->and($fake->lastUri())->toBe('https://demo.bamboohr.com/careers/list');
});

it('falls back to atsLocation when the display location is empty', function (): void {
    $fake = bambooHrBoard([[
        'id' => '7',
        'jobOpeningName' => 'Support Engineer',
        'location' => ['city' => null, 'state' => null],
        'atsLocation' => ['country' => 'Italy', 'state' => null, 'province' => 'MI', 'city' => 'Milan'],
    ]]);

    $jobs = bambooHrClient($fake)->fetchJobsForCompany('acme');

    expect($jobs[0]->location)->toBe('Milan, MI, Italy');
});

it('reports a remote posting that carries no place at all', function (): void {
    $fake = bambooHrBoard([[
        'id' => '8',
        'jobOpeningName' => 'Designer',
        'location' => ['city' => '', 'state' => ''],
        'atsLocation' => ['country' => null, 'state' => null, 'province' => null, 'city' => null],
        'isRemote' => true,
    ]]);

    expect(bambooHrClient($fake)->fetchJobsForCompany('acme')[0]->location)->toBe('Remote');
});

it('leaves the location null when the board gives nothing to show', function (): void {
    $fake = bambooHrBoard([['id' => '9', 'jobOpeningName' => 'Intern']]);

    $jobs = bambooHrClient($fake)->fetchJobsForCompany('acme');

    expect($jobs[0]->location)->toBeNull()
        ->and($jobs[0]->department)->toBeNull();
});

it('falls back to a placeholder title when the opening has no name', function (): void {
    $fake = bambooHrBoard([['id' => '10', 'jobOpeningName' => '  ']]);

    expect(bambooHrClient($fake)->fetchJobsForCompany('acme')[0]->title)->toBe('Untitled Position');
});

it('returns an empty list for a board that publishes nothing', function (): void {
    $logger = new RecordingLogger;

    expect(bambooHrClient(bambooHrBoard([]), $logger)->fetchJobsForCompany('quiet'))->toBe([])
        // An empty board is a real answer, not a problem worth logging.
        ->and($logger->messages())->toBe([]);
});

it('returns an empty list on a failed http response', function (): void {
    $fake = (new FakePsrClient)->respondWith(500, 'Server Error');
    $logger = new RecordingLogger;

    expect(bambooHrClient($fake, $logger)->fetchJobsForCompany('broken'))->toBe([])
        ->and($logger->messages())->toBe(['BambooHR careers request failed'])
        ->and($logger->levels())->toBe(['warning'])
        ->and($logger->records[0]['context'])->toBe(['company_slug' => 'broken', 'status' => 500]);
});

it('treats the marketing site a missing tenant redirects to as no board', function (): void {
    // A slug with no careers site answers 302 to www.bamboohr.com, which a
    // redirect following PSR-18 client hands back as a 200 of HTML.
    $fake = (new FakePsrClient)->respondWith(200, '<!doctype html><title>BambooHR</title>', ['Content-Type' => 'text/html']);
    $logger = new RecordingLogger;

    expect(bambooHrClient($fake, $logger)->fetchJobsForCompany('not-a-tenant'))->toBe([])
        ->and($logger->messages())->toBe(['BambooHR careers response is not a job board payload'])
        ->and($logger->levels())->toBe(['warning']);
});

it('returns an empty list when the connection fails', function (): void {
    $fake = (new FakePsrClient)->throwNetworkError();
    $logger = new RecordingLogger;

    expect(bambooHrClient($fake, $logger)->fetchJobsForCompany('unreachable'))->toBe([])
        ->and($logger->messages())->toBe(['BambooHR connection error'])
        ->and($logger->levels())->toBe(['error']);
});

it('refuses a slug that would retarget the request at another host', function (): void {
    $fake = new FakePsrClient;
    $logger = new RecordingLogger;

    expect(bambooHrClient($fake, $logger)->fetchJobsForCompany('evil.com/x'))->toBe([])
        ->and($fake->requests)->toBe([])
        ->and($logger->messages())->toBe(['BambooHR slug is not a usable host label']);
});

it('validates a slug that answers with the board envelope', function (): void {
    $fake = bambooHrBoard([['id' => '1', 'jobOpeningName' => 'Accounting']]);

    expect(bambooHrClient($fake)->validateSlug('demo'))->toBe('demo')
        ->and($fake->lastUri())->toBe('https://demo.bamboohr.com/careers/list');
});

it('validates a real tenant that has nothing published', function (): void {
    expect(bambooHrClient(bambooHrBoard([]))->validateSlug('quiet'))->toBe('quiet');
});

it('rejects a slug whose answer is not a board', function (): void {
    $fake = (new FakePsrClient)->respondWith(200, '<!doctype html>', ['Content-Type' => 'text/html']);

    expect(bambooHrClient($fake)->validateSlug('not-a-tenant'))->toBeNull();
});

it('rejects a slug when the lookup fails outright', function (): void {
    expect(bambooHrClient((new FakePsrClient)->throwNetworkError())->validateSlug('unreachable'))->toBeNull()
        ->and(bambooHrClient((new FakePsrClient)->respondWith(503))->validateSlug('down'))->toBeNull();
});

it('has no company description to offer', function (): void {
    expect(bambooHrClient(new FakePsrClient)->fetchCompanyDescription('demo'))->toBeNull();
});

it('asks for the timeout each call says it wants', function (): void {
    $fake = bambooHrBoard([]);
    bambooHrClient($fake)->fetchJobsForCompany('demo');

    $lookup = bambooHrBoard([]);
    bambooHrClient($lookup)->validateSlug('demo');

    expect($fake->appliedTimeouts)->toBe([BambooHrClient::TIMEOUT_SECONDS])
        ->and($lookup->appliedTimeouts)->toBe([BambooHrClient::LOOKUP_TIMEOUT_SECONDS]);
});
