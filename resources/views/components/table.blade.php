@php
    use Divoto\Cairn\Enums\Metric;

    $dimension = $schema->dimension ?? '';
    $enum = $dimension ? Divoto\Cairn\Enums\Dimension::tryFrom($dimension) : null;
    $metrics = $schema->metrics ?: [Metric::Pageviews];
    $primary = $metrics[0];

    // The proportion bar is relative to the largest row, not to the total:
    // with a long tail, scaling to the total leaves every bar invisible.
    $largest = max(1.0, $rows->max(fn ($row) => $row->metric($primary) ?? 0.0) ?: 1.0);
@endphp

<table>
    <caption class="sr-caption">{{ $title }}, ranked by {{ strtolower($primary->label()) }}</caption>
    <thead>
        <tr>
            <th scope="col">{{ $enum?->label() ?? ucfirst($dimension ?: 'Value') }}</th>
            @foreach ($metrics as $metric)
                <th scope="col" class="num">{{ $metric->label() }}</th>
            @endforeach
        </tr>
    </thead>
    <tbody>
        @foreach ($rows as $row)
            @php
                $raw = $row->dimension($dimension);
                $value = $enum?->display($raw) ?? (string) ($raw ?? '—');
                $share = ($row->metric($primary) ?? 0.0) / $largest * 100;
                $link = $schema->filterAs
                    ? url($path).'?'.http_build_query($filters->toQuery([$schema->filterAs => (string) $raw]))
                    : null;
            @endphp
            <tr>
                <td>
                    @if ($link)
                        <a class="cell" href="{{ $link }}" title="{{ $value }}">
                            <span class="bar" style="width: {{ number_format($share, 2) }}%"></span>
                            <span>{{ $value }}</span>
                        </a>
                    @else
                        <span class="cell" title="{{ $value }}">
                            <span class="bar" style="width: {{ number_format($share, 2) }}%"></span>
                            <span>{{ $value }}</span>
                        </span>
                    @endif
                </td>
                @foreach ($metrics as $metric)
                    <td class="num">{{ Divoto\Cairn\Support\Format::metric($metric, $row->metric($metric)) }}</td>
                @endforeach
            </tr>
        @endforeach
    </tbody>
</table>
