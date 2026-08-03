<x-pulse::card :cols="$cols ?? 2" :rows="$rows ?? 1" :class="$class ?? ''" wire:poll.5s="">
    <x-pulse::card-header name="Live visitors" details="Last 5 minutes" />

    <x-pulse::scroll :expand="false">
        <div class="flex items-baseline gap-3 p-3">
            <span class="text-3xl font-bold tabular-nums text-gray-900 dark:text-gray-100">
                {{ number_format($visitors) }}
            </span>
            <span class="text-sm text-gray-500 dark:text-gray-400">
                {{ $visitors === 1 ? 'visitor' : 'visitors' }} on the site
            </span>
        </div>
    </x-pulse::scroll>
</x-pulse::card>
