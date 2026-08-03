@php
    use Divoto\Cairn\Enums\Metric;
    use Divoto\Cairn\Support\Format;

    $row = $widget->rows($filters)->first();
    $schema = $widget->schema();
    $points = $series ?? collect();

    // Chart geometry. Computed here rather than in JavaScript so the chart is
    // in the HTML — readable with scripting disabled, and printable.
    $width = 900;
    $height = 200;
    $peak = max(1.0, $points->max(fn ($p) => $p->metric(Metric::Pageviews) ?? 0.0) ?: 1.0);
    $count = max(1, $points->count() - 1);
@endphp

<div class="stats">
    @foreach ($schema->metrics as $metric)
        @php
            $value = $row?->metric($metric);
            $change = $row?->change($metric);
        @endphp
        <div>
            <p class="stat-label">
                {{ $metric->label() }}
                @if ($metric === Metric::Visitors && $row?->approximate)
                    <abbr class="approx"
                          title="Summed daily counts. Cairn's salt rotates every 24 hours, so a visitor returning on another day is counted again — there is no cross-day identity to deduplicate against.">~</abbr>
                @endif
            </p>
            <div class="stat-value">{{ Format::metric($metric, $value) }}</div>

            @if ($change === null)
                <div class="stat-change flat">
                    {{ $filters->comparison === Divoto\Cairn\Enums\Comparison::None ? '' : 'No comparison' }}
                </div>
            @else
                <div class="stat-change {{ $change > 0 ? 'up' : ($change < 0 ? 'down' : 'flat') }}">
                    {{ $change > 0 ? '↑' : ($change < 0 ? '↓' : '→') }}
                    {{ number_format(abs($change) * 100, 1) }}%
                </div>
            @endif
        </div>
    @endforeach
</div>

@if ($points->isNotEmpty())
    @php
        $coords = $points->values()->map(function ($point, $index) use ($count, $peak, $width, $height) {
            $x = $count === 0 ? 0 : ($index / $count) * $width;
            $y = $height - (($point->metric(Metric::Pageviews) ?? 0.0) / $peak) * ($height - 12);

            return round($x, 2).','.round($y, 2);
        })->all();

        $line = implode(' ', $coords);
        $area = '0,'.$height.' '.$line.' '.$width.','.$height;
    @endphp

    <svg class="chart" viewBox="0 0 {{ $width }} {{ $height + 18 }}" role="img"
         aria-label="Pageviews over {{ strtolower($filters->rangeLabel()) }}. The figures are in the table below.">
        <line class="chart-grid" x1="0" y1="{{ $height }}" x2="{{ $width }}" y2="{{ $height }}" />
        <polygon class="chart-area" points="{{ $area }}" />
        <polyline class="chart-line" points="{{ $line }}" />

        <text class="chart-label" x="0" y="{{ $height + 14 }}">
            {{ $points->first()?->bucket?->toFormattedDateString() }}
        </text>
        <text class="chart-label" x="{{ $width }}" y="{{ $height + 14 }}" text-anchor="end">
            {{ $points->last()?->bucket?->toFormattedDateString() }}
        </text>
    </svg>

    {{-- Every chart has a table. A line drawn as an image is unreadable to a
         screen reader and unusable to anyone who needs the actual figures. --}}
    <details class="data">
        <summary>Show these figures as a table</summary>

        <table>
            <caption>Pageviews and visitors per {{ $filters->interval()->value }}</caption>
            <thead>
                <tr>
                    <th scope="col">Period</th>
                    <th scope="col" class="num">Pageviews</th>
                    <th scope="col" class="num">Visitors</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($points as $point)
                    <tr>
                        <th scope="row">{{ $point->bucket?->toFormattedDateString() }}</th>
                        <td class="num">{{ Format::metric(Metric::Pageviews, $point->metric(Metric::Pageviews)) }}</td>
                        <td class="num">{{ Format::metric(Metric::Visitors, $point->metric(Metric::Visitors)) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </details>
@else
    <p class="empty">Nothing recorded in this period.</p>
@endif
