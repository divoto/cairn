<?php

declare(strict_types=1);

namespace Divoto\Cairn\Recorders;

/**
 * Records what only the browser can measure: time on page, scroll depth,
 * screen class and Core Web Vitals.
 *
 * Fed by the optional JS beacon. With the beacon disabled this recorder never
 * fires, and the widgets built on its metrics render an explanatory empty
 * state rather than a misleading zero — the dashboard is fully functional
 * without it.
 *
 * Phase 6 implements this; see {@see PageViews} for why the class exists now.
 */
final class ClientMetrics extends Recorder {}
