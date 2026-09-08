<?php

declare(strict_types=1);

namespace PlinCode\JobBoards\BambooHr;

use PlinCode\JobBoards\Contracts\JobBoardClient;
use PlinCode\JobBoards\Data\JobPostingDTO;
use PlinCode\JobBoards\Exceptions\TransportException;
use PlinCode\JobBoards\Http\HttpClient;
use PlinCode\JobBoards\Http\Response;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Reads the public BambooHR careers board:
 *
 *   GET https://{slug}.bamboohr.com/careers/list
 *   { "meta": { "totalCount": 1 },
 *     "result": [ { "id": "1", "jobOpeningName": "Accounting",
 *                   "departmentLabel": "Accounting",
 *                   "location": { "city": "provo ", "state": "Utah" },
 *                   "atsLocation": { "country": null, "state": null, ... },
 *                   "isRemote": null, "locationType": "0" } ] }
 *
 * No credentials, no pagination: the whole board comes back in one answer.
 * Like Personio, the slug is a subdomain rather than a path segment, so the
 * base URL is a template.
 *
 * Two things about this endpoint are worth knowing before relying on it. It is
 * the one the careers widget calls rather than a documented public API, so the
 * field names here are observed, not promised. And a slug with no careers site
 * does not 404: it answers 302 to www.bamboohr.com, which a redirect following
 * PSR-18 client turns into a 200 of marketing HTML. Status alone therefore
 * proves nothing, and every read below is gated on the payload actually being
 * the JSON envelope this board returns.
 *
 * Nothing here knows about Laravel. It is handed core's HttpClient and an
 * optional PSR-3 logger, both of which a Symfony or plain PHP consumer can
 * build by hand. {@see BambooHrServiceProvider} is the only Laravel aware file.
 */
final class BambooHrClient implements JobBoardClient
{
    /**
     * The slug is a subdomain, so the base URL is a template rather than a
     * prefix.
     */
    public const string API_BASE_URL = 'https://%s.bamboohr.com/careers/list';

    /**
     * The public job page: slug, then posting id.
     */
    public const string JOB_URL_TEMPLATE = 'https://%s.bamboohr.com/careers/%s';

    /**
     * Listing a whole board can be slow, so it gets a longer budget than the
     * cheap lookup behind validateSlug().
     */
    public const float TIMEOUT_SECONDS = 30.0;

    public const float LOOKUP_TIMEOUT_SECONDS = 15.0;

    /**
     * The slug is interpolated into the hostname, where a slash or an @ would
     * not be escaped but would silently retarget the request at another host.
     * Only what can legitimately appear in a host label gets through.
     */
    private const string SLUG_PATTERN = '/^[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?$/';

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly HttpClient $http,
        private readonly string $baseUrlTemplate = self::API_BASE_URL,
        private readonly string $jobUrlTemplate = self::JOB_URL_TEMPLATE,
        private readonly float $timeout = self::TIMEOUT_SECONDS,
        private readonly float $lookupTimeout = self::LOOKUP_TIMEOUT_SECONDS,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger;
    }

    /**
     * Never throws at the caller: whatever goes wrong is logged and an empty
     * list comes back, so one broken company cannot abort a sync over hundreds
     * of them.
     *
     * @return list<JobPostingDTO>
     */
    public function fetchJobsForCompany(string $slug): array
    {
        if (! $this->isUsableSlug($slug)) {
            return [];
        }

        try {
            // get() rather than tryGet() so the transport error message survives
            // into the log. tryGet() would flatten it to a null.
            $response = $this->http->withTimeout($this->timeout)->get($this->listUrl($slug));

            if ($response->failed()) {
                $this->logger->warning('BambooHR careers request failed', [
                    'company_slug' => $slug,
                    'status' => $response->status(),
                ]);

                return [];
            }

            $rows = $this->rows($response);

            if ($rows === null) {
                // Either the redirect to the marketing site, or a board that
                // answered with something other than the envelope. Neither is
                // an error worth throwing over, but both are worth seeing.
                $this->logger->warning('BambooHR careers response is not a job board payload', [
                    'company_slug' => $slug,
                    'status' => $response->status(),
                ]);

                return [];
            }

            $postings = [];

            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }

                /** @var array<string, mixed> $row */
                $postings[] = $this->mapToDTO($row, $slug);
            }

            return $postings;
        } catch (TransportException $e) {
            $this->logger->error('BambooHR connection error', [
                'company_slug' => $slug,
                'error' => $e->getMessage(),
            ]);

            return [];
        } catch (Throwable $e) {
            $this->logger->error('Unexpected error fetching BambooHR jobs', [
                'company_slug' => $slug,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * BambooHR publishes no company name anywhere in this payload, so a board
     * that answers with the envelope validates under its own slug. A board that
     * lists nothing still validates: an empty result set is a real answer from
     * a real tenant, while a slug with no careers site never gets this far
     * because it answers with the marketing site instead.
     */
    public function validateSlug(string $slug): ?string
    {
        if (! $this->isUsableSlug($slug)) {
            return null;
        }

        try {
            // tryGet() here: this one is silent by contract, so there is no
            // message to keep.
            $response = $this->http->withTimeout($this->lookupTimeout)->tryGet($this->listUrl($slug));

            if ($response === null || $response->failed()) {
                return null;
            }

            return $this->rows($response) === null ? null : $slug;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The careers page carries no company profile, only a generic "current
     * openings" blurb that is the same string on every tenant, so there is
     * nothing here worth returning.
     */
    public function fetchCompanyDescription(string $slug): ?string
    {
        return null;
    }

    /**
     * The job rows, or null when the answer is not this board's envelope at all.
     * An existing board with nothing published returns an empty list, which is
     * a different thing and must stay distinguishable.
     *
     * @return list<mixed>|null
     */
    private function rows(Response $response): ?array
    {
        // tryJson() rather than json(): a tenant that does not exist answers
        // with HTML, which is routine here rather than exceptional.
        $result = $response->tryJson('result');

        return is_array($result) ? array_values($result) : null;
    }

    private function isUsableSlug(string $slug): bool
    {
        if (preg_match(self::SLUG_PATTERN, $slug) === 1) {
            return true;
        }

        $this->logger->warning('BambooHR slug is not a usable host label', [
            'company_slug' => $slug,
        ]);

        return false;
    }

    private function listUrl(string $slug): string
    {
        return sprintf($this->baseUrlTemplate, $slug);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function mapToDTO(array $row, string $slug): JobPostingDTO
    {
        $id = $row['id'] ?? null;
        $externalId = is_scalar($id) ? trim((string) $id) : '';
        $title = $row['jobOpeningName'] ?? null;
        $department = $row['departmentLabel'] ?? null;

        return new JobPostingDTO(
            externalId: $externalId,
            title: is_string($title) && trim($title) !== '' ? trim($title) : 'Untitled Position',
            location: $this->location($row),
            url: sprintf($this->jobUrlTemplate, $slug, rawurlencode($externalId)),
            department: is_string($department) && trim($department) !== '' ? trim($department) : null,
            rawPayload: $row,
        );
    }

    /**
     * "location" is the display pair the widget renders and is what a reader
     * wants; "atsLocation" is the structured record behind it and is often all
     * nulls. Take the first, fall back to the second, and let a board that
     * flags the posting remote say so when it gave no place at all.
     *
     * Values arrive padded often enough to be worth trimming here rather than
     * in every consumer: the live board returns "provo " with the space.
     *
     * @param  array<string, mixed>  $row
     */
    private function location(array $row): ?string
    {
        $display = $this->joinParts($row['location'] ?? null, ['city', 'state']);

        if ($display !== null) {
            return $display;
        }

        $ats = $this->joinParts($row['atsLocation'] ?? null, ['city', 'state', 'province', 'country']);

        if ($ats !== null) {
            return $ats;
        }

        return ($row['isRemote'] ?? null) === true ? 'Remote' : null;
    }

    /**
     * @param  list<string>  $keys
     */
    private function joinParts(mixed $source, array $keys): ?string
    {
        if (! is_array($source)) {
            return null;
        }

        $parts = [];

        foreach ($keys as $key) {
            $value = $source[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                $parts[] = trim($value);
            }
        }

        return $parts === [] ? null : implode(', ', $parts);
    }
}
