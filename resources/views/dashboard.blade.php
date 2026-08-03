@php
    use Divoto\Cairn\Widgets\WidgetLayout;

    $query = fn (array $overrides = []) => url($path).'?'.http_build_query($filters->toQuery($overrides));
@endphp

<x-cairn::layout :path="$path" title="Cairn">
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
                    <a class="chip" href="{{ $query([$dimension => null]) }}"
                       aria-label="Remove the {{ $dimension }} filter">
                        <b>{{ $dimension }}</b> {{ $value }} <span aria-hidden="true">✕</span>
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

            <section class="panel @if ($schema->layout === WidgetLayout::Feed) panel-wide @endif"
                     aria-labelledby="w-{{ $widget->key() }}">
                <div class="panel-head">
                    <h2 id="w-{{ $widget->key() }}">{{ $widget->title() }}</h2>
                    @if ($widget->description())
                        <p class="panel-note">{{ $widget->description() }}</p>
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
</x-cairn::layout>
