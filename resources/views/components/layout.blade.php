{{--
    The dashboard shell.

    Everything is server-rendered. There is no JavaScript required to read any
    number on this page — filters are links, the chart is an inline SVG, and
    every chart has a table underneath it. JavaScript adds one thing only: a
    theme toggle.
--}}
<!DOCTYPE html>
<html lang="en" data-theme="{{ $theme ?? 'auto' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    {{-- The dashboard must never appear in search results, and must never
         be measured by Cairn itself. --}}
    <meta name="robots" content="noindex, nofollow">

    <title>{{ $title ?? 'Cairn' }}</title>

    {{-- Inlined: ~8KB, smaller than the request that would fetch it, and it
         cannot break when a deployment forgets to republish assets. --}}
    <style>{!! Divoto\Cairn\Support\Assets::css() !!}</style>
</head>
<body>
    <a class="skip" href="#main">Skip to content</a>

    <header class="masthead">
        <div class="wrap masthead-inner">
            <a class="brand" href="{{ url($path) }}">
                <span class="brand-mark" aria-hidden="true">
                    <span></span><span></span><span></span>
                </span>
                <span class="brand-text">
                    Cairn
                    <small>Every visitor adds a stone. Nobody leaves a name.</small>
                </span>
            </a>

            <button type="button" class="theme" data-cairn-theme aria-label="Switch colour theme">
                <span aria-hidden="true">◐</span>
            </button>
        </div>
    </header>

    <main class="wrap" id="main">
        {{ $slot }}
    </main>

    <footer class="wrap foot">
        <p>
            Cookieless by default. No IP address is stored, and the visitor hash
            behind these numbers is regenerated from a new salt every 24 hours.
        </p>
    </footer>

    <script>
        // The only script on the page. The dashboard is fully readable without
        // it — this remembers a theme preference, nothing more.
        (function () {
            var root = document.documentElement;
            var key = 'cairn:theme';
            var stored = null;

            try { stored = localStorage.getItem(key); } catch (e) { /* private mode */ }
            if (stored) { root.setAttribute('data-theme', stored); }

            var button = document.querySelector('[data-cairn-theme]');
            if (!button) { return; }

            button.addEventListener('click', function () {
                var order = ['auto', 'light', 'dark'];
                var next = order[(order.indexOf(root.getAttribute('data-theme')) + 1) % order.length];

                root.setAttribute('data-theme', next);
                try { localStorage.setItem(key, next); } catch (e) { /* private mode */ }
            });
        })();
    </script>
</body>
</html>
