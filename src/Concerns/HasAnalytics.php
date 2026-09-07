<?php

declare(strict_types=1);

namespace Divoto\Cairn\Concerns;

use Carbon\CarbonImmutable;
use Divoto\Cairn\Cairn;
use Divoto\Cairn\Enums\Dimension;
use Divoto\Cairn\Enums\EntryType;
use Divoto\Cairn\Enums\Metric;
use Divoto\Cairn\Recording\EntryFactory;
use Divoto\Cairn\Support\SubjectKey;
use Illuminate\Database\Eloquent\Model;
use Throwable;

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
     * The aggregate key this model's numbers are stored under.
     *
     * The morph alias and the primary key, so a class rename does not orphan
     * history — provided the alias is registered in a morph map. Without one,
     * `getMorphClass()` returns the class name and renaming the class does
     * strand its rows.
     */
    public function analyticsKey(): string
    {
        $key = $this->getKey();

        return SubjectKey::for(
            $this->analyticsType(),
            is_int($key) || is_string($key) ? $key : '',
        );
    }

    /**
     * How many times this model was viewed, over the last N days.
     *
     * Reads the rollup, never raw entries — the same rule the dashboard obeys.
     * A window longer than your retention returns what is still aggregated
     * rather than scanning for what has been pruned.
     */
    public function views(int $days = 30): int
    {
        return $this->analyticsTotal(Metric::Pageviews, $days);
    }

    /**
     * How many events other than views were recorded against this model.
     *
     * Not per event name: that would be a subject *and* an event name, and v1
     * materialises one dimension at a time. Asking the report builder for the
     * pair would throw rather than quietly scan raw entries, which is the
     * right behaviour and a poor method.
     */
    public function analyticsEvents(int $days = 30): int
    {
        return $this->analyticsTotal(Metric::Events, $days);
    }

    /**
     * How many conversions were recorded against this model.
     */
    public function analyticsConversions(int $days = 30): int
    {
        return $this->analyticsTotal(Metric::Conversions, $days);
    }

    /**
     * One rolled-up total for this model.
     */
    private function analyticsTotal(Metric $metric, int $days): int
    {
        $to = CarbonImmutable::now('UTC')->endOfDay();

        $total = app(Cairn::class)->report()
            ->between($to->subDays(max(1, $days) - 1)->startOfDay(), $to)
            ->metrics($metric)
            ->filter(Dimension::Subject, $this->analyticsKey())
            ->total()
            ->metric($metric);

        return (int) ($total ?? 0);
    }

    /**
     * Build and buffer an entry attributed to this model.
     *
     * @param  array<string, scalar|null>  $properties
     */
    private function trackAnalytics(EntryType $type, string $name, array $properties, ?string $value = null): void
    {
        // Project rule: a Cairn failure never becomes the host application's.
        // The middleware guards its own work after the response has gone out;
        // a model event fires inside the host's own request, where building
        // the entry already touches the session table, so it guards itself.
        try {
            $cairn = app(Cairn::class);
            $request = request();

            // The gate is consulted here as it is everywhere else: a model
            // event triggered during a request the visitor asked not to have
            // measured is still that visitor's request.
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
        } catch (Throwable $e) {
            report($e);
        }
    }
}
