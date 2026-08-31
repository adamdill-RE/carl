<?php

declare(strict_types=1);

namespace Carl\Analysis;

/**
 * What `Analyst` needs from an analysis API.
 *
 * An interface for the same reason `WeatherProvider` is one, and more
 * urgently: a suite that called the real endpoint would be flaky, would need
 * a live key in CI, and would spend real money on every run of every branch.
 * `12_analysis_test.php` drives a stub through the queue, the lease, the
 * backoff and the retry classification -- which is all of the behaviour that
 * is actually Carl's.
 */
interface Provider
{
    /** The model identifier, for the run row and the page footer. */
    public function model(): string;

    public function analyse(string $systemPrompt, string $userMessage): Reply;
}
