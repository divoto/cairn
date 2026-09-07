/*
 * Cairn's optional beacon.
 *
 * Measures the handful of things a server cannot see: how long a page was
 * actually looked at, how far down it was read, the viewport it was read in,
 * and the Core Web Vitals. Everything else Cairn records is captured
 * server-side and needs none of this.
 *
 * Hand-written, dependency-free, and under 2KB minified — the project forbids a
 * build step, so this ships as source plus a minified copy and never touches a
 * bundler.
 *
 * What it deliberately does not collect: exact screen dimensions (the server
 * buckets the viewport width and discards the rest), device memory, CPU count,
 * installed fonts, canvas signatures, or anything else whose only use is
 * fingerprinting. It reads no cookies and writes none.
 *
 * The dashboard is fully functional without this file. Widgets that depend on
 * it render an explanatory empty state rather than a misleading zero.
 */
(function (window, document) {
    'use strict';

    var script = document.currentScript;
    if (!script) { return; }

    var endpoint = script.getAttribute('data-cairn-endpoint');
    if (!endpoint || !window.navigator || !window.navigator.sendBeacon) { return; }

    // Honour the same signals the server does. A visitor who has asked not to
    // be measured must not be measured by the beacon either.
    if (window.navigator.doNotTrack === '1' || window.navigator.globalPrivacyControl === true) { return; }
    if (document.cookie.indexOf('cairn_opt_out=') !== -1) { return; }

    var page, sent;

    // CLS is the one vital where zero is the best score rather than nothing
    // measured. LCP and event timing exist on more engines than layout shift
    // does, so a browser without that observer has to send CLS as absent, or
    // every visit from it would count as a page that never shifted.
    var measuresShift = !!(window.PerformanceObserver
        && window.PerformanceObserver.supportedEntryTypes
        && window.PerformanceObserver.supportedEntryTypes.indexOf('layout-shift') !== -1);

    function start() {
        page = {
            u: location.pathname,
            t: Date.now(),
            s: 0,               // deepest scroll, as a percentage
            v: window.innerWidth || 0,
            z: 0,               // timezone offset, minutes
            l: 0, i: 0,         // LCP, INP
            c: measuresShift ? 0 : null
        };

        try { page.z = new Date().getTimezoneOffset(); } catch (e) {}

        sent = false;
        measureScroll();
    }

    function measureScroll() {
        var body = document.body, html = document.documentElement;
        if (!body || !html) { return; }

        var height = Math.max(body.scrollHeight, html.scrollHeight) - window.innerHeight;
        var depth = height > 0 ? Math.round(((window.scrollY || 0) / height) * 100) : 100;

        if (depth > page.s) { page.s = depth > 100 ? 100 : depth; }
    }

    function send() {
        if (sent || !page) { return; }

        var seconds = Math.round((Date.now() - page.t) / 1000);

        // A page nobody looked at, and a tab left open for a week, are both
        // noise. Twelve hours is the ceiling.
        if (seconds < 1 || seconds > 43200) { sent = true; return; }

        sent = true;

        try {
            window.navigator.sendBeacon(endpoint, new Blob([JSON.stringify({
                url: page.u,
                seconds: seconds,
                scroll: page.s,
                viewport: page.v,
                timezone: page.z,
                lcp: Math.round(page.l),
                inp: Math.round(page.i),
                cls: page.c === null ? null : Math.round(page.c * 1000) / 1000
            })], { type: 'application/json' }));
        } catch (e) {}
    }

    function observe(type, handler) {
        try {
            var observer = new window.PerformanceObserver(handler);
            observer.observe({ type: type, buffered: true });
        } catch (e) {}
    }

    function vitals() {
        if (!window.PerformanceObserver) { return; }

        observe('largest-contentful-paint', function (list) {
            var entries = list.getEntries();
            var last = entries[entries.length - 1];
            if (last) { page.l = last.startTime; }
        });

        observe('layout-shift', function (list) {
            list.getEntries().forEach(function (entry) {
                if (!entry.hadRecentInput && page.c !== null) { page.c += entry.value; }
            });
        });

        observe('event', function (list) {
            list.getEntries().forEach(function (entry) {
                if (entry.duration > page.i) { page.i = entry.duration; }
            });
        });
    }

    // A single-page navigation ends one page and starts another. Without this,
    // an SPA would report one enormous session on its first URL.
    function navigated() {
        send();
        start();
        vitals();
    }

    start();
    vitals();

    addEventListener('scroll', measureScroll, { passive: true });
    addEventListener('pagehide', send);
    addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'hidden') { send(); }
    });

    document.addEventListener('livewire:navigated', navigated);
    document.addEventListener('turbo:load', navigated);
    document.addEventListener('inertia:navigate', navigated);
})(window, document);
