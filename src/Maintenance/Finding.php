<?php

declare(strict_types=1);

namespace Divoto\Cairn\Maintenance;

/**
 * Something `cairn:doctor` noticed.
 *
 * A finding is an observation, not an error. It explains what a setting means
 * and stops there — it never says whether a deployment is lawful, because that
 * depends on context a package cannot see, and a tool that answered the
 * question would be answering it wrongly.
 */
final readonly class Finding
{
    /**
     * @param  string  $title  What was noticed.
     * @param  string  $implication  What it means, in plain language.
     * @param  string  $setting  The configuration key or gate involved.
     * @param  bool  $severe  Whether this is likely to be unintended.
     */
    public function __construct(
        public string $title,
        public string $implication,
        public string $setting,
        public bool $severe = false,
    ) {}
}
