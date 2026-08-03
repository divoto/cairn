<?php

declare(strict_types=1);

namespace Divoto\Cairn\Concerns;

use Divoto\Cairn\Cairn;
use Divoto\Cairn\Enums\EntryType;
use Divoto\Cairn\Recording\EntryFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Lets an Eloquent model record its own analytics.
 *
 * This is the capability that comes from living inside the application. An
 * external analytics tag sees a URL; Cairn can be told "this was a view of
 * *this* Article", and answer questions about models rather than about paths.
 *
 * ```php
 * class Article extends Model
 * {
 *     use HasAnalytics;
 * }
 *
 * $article->trackView();
 * $article->trackEvent('shared', ['network' => 'mastodon']);
 * ```
 *
 * @mixin Model
 */
trait HasAnalytics
{
    /**
     * Record a view of this model.
     */
    public function trackView(): void
    {
        $this->trackEvent('viewed');
    }

    /**
     * Record a named event against this model.
     *
     * @param  array<string, scalar|null>  $properties
     */
    public function trackEvent(string $name, array $properties = []): void
    {
        $this->trackAnalytics(EntryType::Event, $name, $properties);
    }

    /**
     * Record a conversion against this model, optionally carrying a value.
     *
     * @param  array<string, scalar|null>  $properties
     */
    public function trackConversion(string $name, float|string|null $value = null, array $properties = []): void
    {
        $this->trackAnalytics(
            EntryType::Conversion,
            $name,
            $properties,
            $value === null ? null : (is_string($value) ? $value : number_format($value, 2, '.', '')),
        );
    }

    /**
     * The polymorphic type recorded for this model.
     *
     * Uses the morph alias when one is registered, so a class rename does not
     * orphan historical data.
     */
    public function analyticsType(): string
    {
        return $this->getMorphClass();
    }

    /**
     * Build and buffer an entry attributed to this model.
     *
     * @param  array<string, scalar|null>  $properties
     */
    private function trackAnalytics(EntryType $type, string $name, array $properties, ?string $value = null): void
    {
        $cairn = app(Cairn::class);
        $request = request();

        // The gate is consulted here as it is everywhere else: a model event
        // triggered during a request the visitor asked not to have measured is
        // still that visitor's request.
        if ($cairn->decide($request) !== null) {
            return;
        }

        $key = $this->getKey();

        $cairn->record(app(EntryFactory::class)->named(
            $request,
            $type,
            $name,
            $properties === [] ? null : $properties,
            $value,
            $this->analyticsType(),
            is_int($key) || is_string($key) ? $key : null,
        ));
    }
}
