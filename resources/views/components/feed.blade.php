<ul class="feed">
    @foreach ($rows as $row)
        <li>
            <code title="A daily-rotating hash. It identifies nobody and is unrelated to yesterday's.">{{ $row->dimension('visitor') }}</code>
            <span class="page" title="{{ $row->dimension('page') }}">{{ $row->dimension('page') }}</span>
            <time datetime="{{ $row->dimension('seen') }}">{{ $row->dimension('seen') }}</time>
        </li>
    @endforeach
</ul>
