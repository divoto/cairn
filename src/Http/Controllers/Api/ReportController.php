<?php

declare(strict_types=1);

namespace Divoto\Cairn\Http\Controllers\Api;

use Divoto\Cairn\Cairn;
use Divoto\Cairn\Data\ReportRow;
use Divoto\Cairn\Enums\Comparison;
use Divoto\Cairn\Enums\Dimension;
use Divoto\Cairn\Enums\Metric;
use Divoto\Cairn\Enums\Period;
use Divoto\Cairn\Exceptions\UnavailableDimensionException;
use Divoto\Cairn\Support\ErrorPageKey;
use Divoto\Cairn\Widgets\Filters;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The JSON API.
 *
 * Backed by the same report builder as everything else, so it cannot disagree
 * with the dashboard. Disabled by default and behind `auth:sanctum` when
 * enabled — it can read everything the dashboard can, which is why it is off
 * until somebody decides who may call it.
 *
 * A request for a combination that was never materialised gets a 422 naming
 * the combination, rather than an empty result the caller has to work
 * backwards from. So does a parameter it does not recognise, or a value it
 * cannot read: answering `period=7d` with Today, or `group_by=bogus` with the
 * site-wide total, is a wrong answer that looks like a right one.
 */
final readonly class ReportController
{
    /**
     * The most rows one request may ask for.
     */
    private const MAX_LIMIT = 1000;

    /**
     * The parameters that narrow the report to one dimension value — the same
     * ones the dashboard writes into its own URLs, so a dashboard link's query
     * string works here unchanged.
     *
     * @var list<string>
     */
    private const FILTERS = ['route', 'country', 'channel', 'language', 'screen_class', 'entry_url'];

    /**
     * Every other parameter the endpoint reads.
     *
     * @var list<string>
     */
    private const PARAMETERS = ['range', 'compare', 'interval', 'metrics', 'group_by', 'limit', 'timeseries'];

    public function __construct(
        private Cairn $cairn,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $errors = $this->validate($request);

        if ($errors !== []) {
            return new JsonResponse(['errors' => $errors], 422);
        }

        $filters = Filters::fromRequest($request);
        [$from, $to] = $filters->window();
        $interval = $this->interval($request) ?? $filters->interval();
        $metrics = $this->metrics($request);

        $report = $this->cairn->report()
            ->between($from, $to)
            ->interval($interval)
            ->compare($this->comparison($request) ?? $filters->comparison)
            ->metrics(...$metrics);

        // Every filter given is applied, not the first by the dashboard's
        // precedence: two at once names an intersection that was never
        // measured, and the builder's refusal says so below.
        foreach (self::FILTERS as $key) {
            $value = $request->query($key);

            if (is_string($value) && trim($value) !== '') {
                $report = $report->filter(Dimension::from($key), mb_substr(trim($value), 0, 255));
            }
        }

        $dimension = $this->dimension($request);

        if ($dimension instanceof Dimension) {
            // Ranked by the first metric asked for, so a limit keeps the top
            // rows rather than whichever the storage happened to return first.
            $report = $report->groupBy($dimension)->orderByDesc($metrics[0]);
        }

        $limit = $request->integer('limit');

        if ($limit > 0) {
            $report = $report->limit(min($limit, self::MAX_LIMIT));
        }

        try {
            $rows = $request->boolean('timeseries')
                ? $report->timeseries()
                : $report->get();
        } catch (UnavailableDimensionException $e) {
            return new JsonResponse([
                'errors' => [[
                    'status' => '422',
                    'title' => 'Unavailable combination',
                    'detail' => $e->getMessage(),
                ]],
            ], 422);
        }

        return new JsonResponse([
            'data' => $rows->map(fn (ReportRow $row): array => [
                'type' => 'report-row',
                'attributes' => $this->attributes($row),
            ])->values()->all(),
            'meta' => [
                'from' => $from->toIso8601String(),
                'to' => $to->toIso8601String(),
                'interval' => $interval->value,
                // Surfaced rather than buried: a client charting these needs to
                // know when a figure is an estimate.
                'approximate' => $rows->contains(static fn (ReportRow $row): bool => $row->approximate),
            ],
        ]);
    }

    /**
     * Everything wrong with the query string, one error per parameter.
     *
     * All of them at once, so a client fixing a hand-written URL is not sent
     * back once per mistake.
     *
     * @return list<array{status: string, title: string, detail: string, source: array{parameter: string}}>
     */
    private function validate(Request $request): array
    {
        $accepted = [...self::PARAMETERS, ...self::FILTERS];
        $errors = [];

        foreach ($request->query() as $name => $value) {
            $name = (string) $name;

            if (! in_array($name, $accepted, true)) {
                $errors[] = $this->error($name, sprintf(
                    'Unknown parameter "%s". Accepted: %s.',
                    $name,
                    implode(', ', $accepted),
                ));

                continue;
            }

            if (! is_string($value)) {
                $errors[] = $this->error($name, sprintf('"%s" takes a single value.', $name));

                continue;
            }

            $problem = $this->problemWith($name, $value);

            if ($problem !== null) {
                $errors[] = $this->error($name, $problem);
            }
        }

        return $errors;
    }

    /**
     * Why a value cannot be read, or null if it can.
     */
    private function problemWith(string $name, string $value): ?string
    {
        return match ($name) {
            'range' => array_key_exists($value, Filters::ranges())
                ? null
                : $this->unrecognised($name, $value, array_keys(Filters::ranges())),
            'compare' => Comparison::tryFrom($value) instanceof Comparison
                ? null
                : $this->unrecognised($name, $value, array_column(Comparison::cases(), 'value')),
            'interval' => Period::tryFrom($value) instanceof Period
                ? null
                : $this->unrecognised($name, $value, array_column(Period::cases(), 'value')),
            'group_by' => Dimension::tryFrom($value) instanceof Dimension
                ? null
                : $this->unrecognised($name, $value, array_column(Dimension::cases(), 'value')),
            'metrics' => $this->problemWithMetrics($value),
            'limit' => ctype_digit($value) && (int) $value > 0
                ? null
                : sprintf('"limit" must be a whole number from 1 to %d, not "%s".', self::MAX_LIMIT, $value),
            'timeseries' => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== null
                ? null
                : sprintf('"timeseries" must be true or false, not "%s".', $value),
            default => null,
        };
    }

    private function problemWithMetrics(string $value): ?string
    {
        $unknown = array_values(array_filter(
            array_map(trim(...), explode(',', $value)),
            static fn (string $name): bool => $name !== '' && ! Metric::tryFrom($name) instanceof Metric,
        ));

        if ($unknown === []) {
            return null;
        }

        return sprintf(
            'Unknown metric%s "%s". Accepted: %s.',
            count($unknown) === 1 ? '' : 's',
            implode('", "', $unknown),
            implode(', ', array_column(Metric::cases(), 'value')),
        );
    }

    /**
     * @param  list<string|int>  $accepted
     */
    private function unrecognised(string $name, string $value, array $accepted): string
    {
        return sprintf('Unknown %s "%s". Accepted: %s.', $name, $value, implode(', ', $accepted));
    }

    /**
     * @return array{status: string, title: string, detail: string, source: array{parameter: string}}
     */
    private function error(string $parameter, string $detail): array
    {
        return [
            'status' => '422',
            'title' => 'Invalid parameter',
            'detail' => $detail,
            'source' => ['parameter' => $parameter],
        ];
    }

    /**
     * A row as the API returns it.
     *
     * A broken page is stored under one key holding its status, URL and
     * referring host as a JSON array (see {@see ErrorPageKey}). It is decoded
     * here, so a client reads an object rather than parsing a string inside a
     * string.
     *
     * @return array<string, mixed>
     */
    private function attributes(ReportRow $row): array
    {
        $attributes = $row->toArray();
        $key = $row->dimension(Dimension::ErrorPage->value);

        if (is_string($key)) {
            $attributes['dimensions'] = [
                ...$row->dimensions,
                Dimension::ErrorPage->value => ErrorPageKey::parse($key) ?? $key,
            ];
        }

        return $attributes;
    }

    /**
     * The requested metrics, defaulting to pageviews.
     *
     * @return non-empty-list<Metric>
     */
    private function metrics(Request $request): array
    {
        $requested = $request->query('metrics');
        $names = is_string($requested) ? explode(',', $requested) : [];

        $metrics = [];

        foreach ($names as $name) {
            $metric = Metric::tryFrom(trim($name));

            if ($metric instanceof Metric) {
                $metrics[] = $metric;
            }
        }

        return $metrics === [] ? [Metric::Pageviews] : $metrics;
    }

    private function dimension(Request $request): ?Dimension
    {
        $value = $request->query('group_by');

        return is_string($value) ? Dimension::tryFrom($value) : null;
    }

    private function interval(Request $request): ?Period
    {
        $value = $request->query('interval');

        return is_string($value) ? Period::tryFrom($value) : null;
    }

    private function comparison(Request $request): ?Comparison
    {
        $value = $request->query('compare');

        return is_string($value) ? Comparison::tryFrom($value) : null;
    }
}
