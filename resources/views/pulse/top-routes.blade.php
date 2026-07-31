<x-pulse::card :cols="$cols ?? 4" :rows="$rows ?? 2" :class="$class ?? ''">
    <x-pulse::card-header name="Top routes" details="Last 24 hours" />

    <x-pulse::scroll :expand="true">
        @if ($routes->isEmpty())
            <x-pulse::no-results />
        @else
            <x-pulse::table>
                <colgroup>
                    <col />
                    <col width="0%" />
                </colgroup>
                <x-pulse::thead>
                    <tr>
                        <x-pulse::th>Route</x-pulse::th>
                        <x-pulse::th class="text-right">Pageviews</x-pulse::th>
                    </tr>
                </x-pulse::thead>
                <tbody>
                    @foreach ($routes as $row)
                        <tr wire:key="{{ $row->dimension('route') }}">
                            <x-pulse::td class="truncate">
                                {{ $row->dimension('route') }}
                            </x-pulse::td>
                            <x-pulse::td numeric class="text-right">
                                {{ Divoto\Cairn\Support\Format::metric(
                                    Divoto\Cairn\Enums\Metric::Pageviews,
                                    $row->metric(Divoto\Cairn\Enums\Metric::Pageviews),
                                ) }}
                            </x-pulse::td>
                        </tr>
                    @endforeach
                </tbody>
            </x-pulse::table>
        @endif
    </x-pulse::scroll>
</x-pulse::card>
