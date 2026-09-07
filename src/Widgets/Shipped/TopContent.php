<?php

declare(strict_types=1);

namespace Divoto\Cairn\Widgets\Shipped;

use Divoto\Cairn\Data\ReportRow;
use Divoto\Cairn\Enums\Dimension;
use Divoto\Cairn\Support\SubjectKey;
use Divoto\Cairn\Widgets\DimensionWidget;
use Divoto\Cairn\Widgets\Filters;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Throwable;

/**
 * The most viewed models, by name.
 *
 * This is the panel only an in-application analytics package can draw. An
 * external tag sees `/articles/how-we-built-it` and can tell you the path did
 * well; Cairn was told *which Article* was viewed, so it can name it — and
 * still name it after the slug changes.
 *
 * Rows are keyed by `{morph alias}:{id}`, so the numbers survive a class
 * rename provided the alias is in a morph map. Labels are resolved with one
 * query per subject type, never one per row: a ten-row table must not become
 * ten queries because somebody opened the dashboard.
 */
final class TopContent extends DimensionWidget
{
    public function key(): string
    {
        return 'top-content';
    }

    public function title(): string
    {
        return 'Top content';
    }

    public function description(): string
    {
        return 'Models recorded with trackView(), named rather than numbered.';
    }

    /**
     * Replace each row's key with the model's own label.
     *
     * A model that no longer exists keeps the fallback rather than vanishing:
     * the views happened, and dropping the row would quietly change a total.
     *
     * @return Collection<int, ReportRow>
     */
    public function rows(Filters $filters): Collection
    {
        $rows = parent::rows($filters);

        $labels = $this->labelsFor($rows);

        return $rows->map(static function (ReportRow $row) use ($labels): ReportRow {
            $key = (string) $row->dimension(Dimension::Subject->value);

            return new ReportRow(
                dimensions: [Dimension::Subject->value => $labels[$key] ?? $key],
                metrics: $row->metrics,
                previous: $row->previous,
                approximate: $row->approximate,
                bucket: $row->bucket,
            );
        });
    }

    protected function dimension(): Dimension
    {
        return Dimension::Subject;
    }

    /**
     * Names for every key on the page, one query per subject type.
     *
     * @param  Collection<int, ReportRow>  $rows
     * @return array<string, string>
     */
    private function labelsFor(Collection $rows): array
    {
        /** @var array<string, list<string>> $byType */
        $byType = [];

        foreach ($rows as $row) {
            $parsed = SubjectKey::parse((string) $row->dimension(Dimension::Subject->value));

            if ($parsed !== null) {
                $byType[$parsed['type']][] = $parsed['id'];
            }
        }

        $labels = [];

        foreach ($byType as $type => $ids) {
            foreach ($this->resolveType($type, $ids) as $id => $label) {
                $labels[SubjectKey::for($type, $id)] = $label;
            }
        }

        return $labels;
    }

    /**
     * Names for one subject type.
     *
     * A type that no longer maps to a model, a table that has been dropped, a
     * connection that is down: all of them leave this panel showing keys
     * rather than taking the dashboard down.
     *
     * @param  list<string>  $ids
     * @return array<string, string>
     */
    private function resolveType(string $type, array $ids): array
    {
        try {
            /** @var class-string|string $class */
            $class = Relation::getMorphedModel($type) ?? $type;

            if (! is_subclass_of($class, Model::class)) {
                return [];
            }

            $model = new $class;

            $found = $class::query()
                ->whereIn($model->getKeyName(), $ids)
                ->get();

            $labels = [];

            foreach ($found as $record) {
                $key = $record->getKey();

                if (! is_int($key) && ! is_string($key)) {
                    continue;
                }

                $id = (string) $key;

                $label = method_exists($record, 'analyticsLabel')
                    ? $record->analyticsLabel()
                    : null;

                // A model is free to return something that is not a usable
                // name; the numbered fallback is better than an empty cell.
                $labels[$id] = is_string($label) && $label !== ''
                    ? $label
                    : class_basename($record).' #'.$id;
            }

            return $labels;
        } catch (Throwable $e) {
            report($e);

            return [];
        }
    }

    /**
     * A title is as long as a route name.
     */
    protected function wide(): bool
    {
        return true;
    }
}
