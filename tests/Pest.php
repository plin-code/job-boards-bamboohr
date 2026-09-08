<?php

declare(strict_types=1);

use PlinCode\JobBoards\BambooHr\Tests\TestCase;

// Unit tests construct the client by hand and need no framework at all, which
// is the whole point of keeping BambooHrClient Laravel free. Only the Feature
// suite boots Testbench.
uses(TestCase::class)->in(__DIR__.'/Feature');
