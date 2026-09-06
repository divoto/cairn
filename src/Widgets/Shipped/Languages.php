<?php

declare(strict_types=1);

namespace Divoto\Cairn\Widgets\Shipped;

use Divoto\Cairn\Enums\Dimension;
use Divoto\Cairn\Widgets\DimensionWidget;

/**
 * What language visitors asked for.
 *
 * Read from the first tag of `Accept-Language` and nothing else. The full
 * header is a fingerprinting signal in its own right — an ordered list with
 * quality weights is close to unique — so the recorder keeps only that first
 * tag, truncated to eight characters, and drops the rest.
 *
 * Values are shown as recorded, so a visitor sending `pt-BR` is reported as
 * `pt-BR` rather than folded into `pt`.
 */
final class Languages extends DimensionWidget
{
    public function key(): string
    {
        return 'languages';
    }

    public function title(): string
    {
        return 'Languages';
    }

    public function description(): string
    {
        return 'The first tag of the request header, never the whole list.';
    }

    protected function dimension(): Dimension
    {
        return Dimension::Language;
    }
}
