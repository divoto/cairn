<?php

declare(strict_types=1);

namespace Divoto\Cairn\Support;

use Divoto\Cairn\Concerns\HasAnalytics;

/**
 * The vocabulary shared by everything that reads or writes a subject.
 *
 * A subject is a model an entry was recorded against — the Article that was
 * viewed, not the URL it was viewed at. Storage, the read helpers on
 * `HasAnalytics` and the Top content widget all have to agree on two things,
 * and this is where they agree on them.
 */
final readonly class SubjectKey
{
    /**
     * The event name `trackView()` records.
     *
     * Cairn's own vocabulary rather than user input, which is why storage is
     * entitled to count it as a first-class measurement.
     */
    public const VIEW_EVENT = 'viewed';

    /**
     * The aggregate key for one model.
     *
     * The morph alias rather than the class name, so renaming a class does not
     * orphan its history — the same reason {@see HasAnalytics::analyticsType()}
     * uses `getMorphClass()`.
     */
    public static function for(string $type, int|string $id): string
    {
        return $type.':'.$id;
    }

    /**
     * Split a key back into its type and id.
     *
     * Returns null for anything that is not one, so a widget rendering a
     * corrupted or hand-written aggregate row skips it rather than throwing.
     *
     * @return array{type: string, id: string}|null
     */
    public static function parse(string $key): ?array
    {
        $at = mb_strrpos($key, ':');

        if ($at === false) {
            return null;
        }

        // A key with nothing before or after the separator names no model.
        if ($at === 0 || $at === mb_strlen($key) - 1) {
            return null;
        }

        return [
            'type' => mb_substr($key, 0, $at),
            'id' => mb_substr($key, $at + 1),
        ];
    }
}
