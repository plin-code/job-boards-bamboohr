<?php

declare(strict_types=1);

use PlinCode\JobBoards\BambooHr\BambooHrClient;

return [

    /*
    |--------------------------------------------------------------------------
    | API Base URL
    |--------------------------------------------------------------------------
    |
    | A printf template, not a prefix: the company slug is a subdomain on
    | bamboohr.com rather than a path segment, so it is interpolated into the
    | host. Override it to point the connector at a recorded fixture server.
    |
    */

    'base_url' => env('JOB_BOARDS_BAMBOOHR_BASE_URL', BambooHrClient::API_BASE_URL),

    /*
    |--------------------------------------------------------------------------
    | Job URL Template
    |--------------------------------------------------------------------------
    |
    | The public page for a single posting: company slug first, posting id
    | second. This is what ends up on JobPostingDTO::$url.
    |
    */

    'job_url_template' => env('JOB_BOARDS_BAMBOOHR_JOB_URL_TEMPLATE', BambooHrClient::JOB_URL_TEMPLATE),

    /*
    |--------------------------------------------------------------------------
    | Timeouts
    |--------------------------------------------------------------------------
    |
    | Seconds. "timeout" covers listing a whole board, "lookup_timeout" the
    | cheaper call behind validateSlug(). Honoured only by PSR-18 clients that
    | implement PlinCode\JobBoards\Http\SupportsTimeout; other clients keep the
    | timeout they were built with.
    |
    */

    'timeout' => env('JOB_BOARDS_BAMBOOHR_TIMEOUT', 30),

    'lookup_timeout' => env('JOB_BOARDS_BAMBOOHR_LOOKUP_TIMEOUT', 15),

    /*
    |--------------------------------------------------------------------------
    | Request Headers
    |--------------------------------------------------------------------------
    |
    | Sent with every request. The board needs no credentials.
    |
    */

    'headers' => [
        'Accept' => 'application/json',
    ],

];
