<?php

declare(strict_types=1);

namespace Divoto\Cairn\Recorders;

/**
 * Records a pageview for each request that passes the privacy gate.
 *
 * Driven by the `TrackPageView` middleware, which registers on the `web`
 * group and records from `terminate()` so nothing is written while the
 * response is still being produced.
 *
 * Phase 5 implements this. It exists now because `config/cairn.php` keys the
 * recorder options by class name, and publishing a config file that references
 * a class which does not exist would fatal on any deployment that publishes it.
 */
final class PageViews extends Recorder {}
