<?php

declare(strict_types=1);

namespace Divoto\Cairn\Detection;

use Divoto\Cairn\Contracts\BotDetector;

/**
 * A bot detector that classifies nothing as a bot.
 *
 * A placeholder for Phase 5, which ships the real matcher. Bound only so the
 * container resolves; it is not a sensible production default, because
 * unfiltered crawler traffic makes every number wrong.
 */
final class NullBotDetector implements BotDetector
{
    public function isBot(?string $userAgent): bool
    {
        return false;
    }
}
