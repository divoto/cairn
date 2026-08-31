{{--
    The dashboard body, without a shell.

    Shared verbatim by the server-rendered page and the Livewire adapter, so
    the two cannot drift into rendering different numbers. Neither the page
    layout nor the Livewire component's single root element belongs here —
    each wrapper supplies its own.

    Expects: $path, $filters, $overview, $series, $widgets.
--}}
@php
    use Divoto\Cairn\Widgets\WidgetLayout;

    $query = fn (array $overrides = []) => url($path).'?'.http_build_query($filters->toQuery($overrides));
@endphp

{{-- Filters live entirely in the URL, so this whole bar is links. It works
     with JavaScript disabled and every state is bookmarkable. --}}
<nav class="filters" aria-label="Filters">
    <div class="ranges" role="group" aria-label="Date range">
        @foreach (Divoto\Cairn\Widgets\Filters::ranges() as $value => $label)
            <a href="{{ $query(['range' => $value]) }}"
               @if ($filters->range === $value) aria-current="true" @endif>{{ $label }}</a>
        @endforeach
    </div>

    @if ($filters->isFiltered())
        <div class="chips">
            @foreach ($filters->active() as $dimension => $value)
                @php
                    // Chips read what the table reads: a chip saying "country
                    // PK" while the row above it says "Pakistan" looks like two
                    // different filters.
                    $enum = Divoto\Cairn\Enums\Dimension::tryFrom($dimension);
                @endphp
                <a class="chip" href="{{ url($path).'?'.http_build_query($filters->with($dimension, null)->toQuery()) }}"
                   aria-label="Remove the {{ $dimension }} filter">
                    <b>{{ $dimension }}</b> {{ $enum?->display($value) ?? $value }} <span aria-hidden="true">✕</span>
                </a>
            @endforeach
        </div>
    @endif
</nav>

@if ($overview)
    <section class="panel panel-wide" aria-labelledby="w-overview">
        <div class="panel-head">
            <h2 id="w-overview">{{ $overview->title() }}</h2>
            <p class="panel-note">
                {{ $filters->rangeLabel() }}
                @if ($filters->comparison !== Divoto\Cairn\Enums\Comparison::None)
                    · compared with the {{ strtolower($filters->comparison->label()) }}
                @endif
            </p>
        </div>

        <x-cairn::overview :widget="$overview" :filters="$filters" :series="$series" />
    </section>
@endif

<div class="grid">
    @foreach ($widgets as $widget)
        @php
            $schema = $widget->schema();
            $rows = $widget->rows($filters);
        @endphp

        <section class="panel @if ($schema->isWide()) panel-wide @endif"
                 aria-labelledby="w-{{ $widget->key() }}">
            <div class="panel-head">
                <h2 id="w-{{ $widget->key() }}">{{ $widget->title() }}</h2>
                @if ($widget->description())
                    <p class="panel-note">{{ $widget->description() }}</p>
                @endif

                {{-- A table grouped by one dimension cannot also be narrowed
                     by another — v1 rolls up one dimension at a time — so a
                     panel that is still site-wide says so rather than letting
                     the filter above it imply otherwise. --}}
                @if ($filters->isFiltered() && $schema->dimension !== $filters->dimension())
                    <p class="panel-note unfiltered">Site-wide — not narrowed by the {{ $filters->dimension() }} filter.</p>
                @endif
            </div>

            @if ($rows->isEmpty())
                <p class="empty">{{ $schema->emptyMessage() }}</p>
            @elseif ($schema->layout === WidgetLayout::Stat)
                <x-cairn::stat :rows="$rows" />
            @elseif ($schema->layout === WidgetLayout::Feed)
                <x-cairn::feed :rows="$rows" />
            @else
                <x-cairn::table
                    :rows="$rows"
                    :schema="$schema"
                    :filters="$filters"
                    :path="$path"
                    :title="$widget->title()" />
            @endif
        </section>
    @endforeach
</div>
