<?php

declare(strict_types=1);

use PlinCode\JobBoards\BambooHR\BambooHRClient;
use PlinCode\JobBoards\Contracts\JobBoardClient;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

it('publishes a config file', function (): void {
    expect(config('job-boards-bamboohr.base_url'))->toBe(BambooHRClient::API_BASE_URL)
        ->and(config('job-boards-bamboohr.job_url_template'))->toBe(BambooHRClient::JOB_URL_TEMPLATE)
        ->and(config('job-boards-bamboohr.timeout'))->toBe(30)
        ->and(config('job-boards-bamboohr.lookup_timeout'))->toBe(15)
        ->and(config('job-boards-bamboohr.headers'))->toBe(['Accept' => 'application/json']);
});

it('resolves the client from the container', function (): void {
    expect(app(BambooHRClient::class))->toBeInstanceOf(JobBoardClient::class);
});

it('binds a psr-18 client and a psr-17 request factory', function (): void {
    expect(app(ClientInterface::class))->toBeInstanceOf(ClientInterface::class)
        ->and(app(RequestFactoryInterface::class))->toBeInstanceOf(RequestFactoryInterface::class);
});

it('lets the application override the psr-18 client', function (): void {
    $custom = new class implements ClientInterface
    {
        public function sendRequest(RequestInterface $request): ResponseInterface
        {
            throw new RuntimeException('never called');
        }
    };

    app()->instance(ClientInterface::class, $custom);

    expect(app(ClientInterface::class))->toBe($custom)
        ->and(app(BambooHRClient::class))->toBeInstanceOf(BambooHRClient::class);
});

it('does not bind the JobBoardClient contract, since connectors would collide', function (): void {
    expect(app()->bound(JobBoardClient::class))->toBeFalse();
});
