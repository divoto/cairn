@php $live = $rows->first()?->metrics['live'] ?? 0.0; @endphp

<div class="live">
    @if ($live > 0)<span class="pulse" aria-hidden="true"></span>@endif
    <span class="stat-value">{{ number_format($live) }}</span>
    <span class="stat-label">{{ $live === 1.0 ? 'visitor' : 'visitors' }} in the last 5 minutes</span>
</div>
