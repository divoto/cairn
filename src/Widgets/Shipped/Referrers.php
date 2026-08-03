<?php

declare(strict_types=1);

namespace Divoto\Cairn\Widgets\Shipped;

use Divoto\Cairn\Enums\Dimension;
use Divoto\Cairn\Widgets\DimensionWidget;

final class Referrers extends DimensionWidget
{
    public function key(): string
    {
        return 'referrers';
    }

    public function title(): string
    {
        return 'Referrers';
    }

    public function description(): string
    {
        return 'The host that linked here. Cairn never stores a full referring URL — those can carry search queries and tokens.';
    }

    protected function dimension(): Dimension
    {
        return Dimension::ReferrerHost;
    }
}
