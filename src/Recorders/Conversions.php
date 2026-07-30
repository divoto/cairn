<?php

declare(strict_types=1);

namespace Divoto\Cairn\Recorders;

/**
 * Records named conversions, optionally carrying a monetary value.
 *
 * Driven explicitly by the application through `Cairn::conversion()`, rather
 * than inferred from traffic — Cairn does not guess what counts as a
 * conversion.
 *
 * Phase 5 implements this; see {@see PageViews} for why the class exists now.
 */
final class Conversions extends Recorder {}
