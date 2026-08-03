<?php

declare(strict_types=1);

namespace Divoto\Cairn\Recorders;

use Divoto\Cairn\Data\Entry;

/**
 * Base class for anything that turns activity into entries.
 *
 * **This class is designed for extension.** Applications add their own
 * recorders by subclassing it and registering the subclass in
 * `cairn.recorders`, which is why it is abstract rather than final.
 *
 * A recorder owns the decision of *what* to record and the shaping of an
 * {@see Entry}. It does not own storage, sampling policy,
 * or the privacy gate — those sit in front of it and behind it respectively,
 * so a custom recorder cannot accidentally bypass them.
 *
 * Phase 5 fills in the shipped recorders and the middleware that drives them.
 */
abstract class Recorder
{
    /**
     * The configuration key this recorder reads its options from.
     *
     * Defaults to the class name, which is how the shipped recorders are keyed
     * in `config/cairn.php`.
     */
    public function key(): string
    {
        return static::class;
    }
}
