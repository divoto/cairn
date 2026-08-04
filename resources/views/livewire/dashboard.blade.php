{{--
    The Livewire adapter's view.

    Exactly one root element, which Livewire requires — it needs somewhere to
    hang the component's identity and refuses to render otherwise. Before
    0.1.2 this component rendered the full-page dashboard, layout and all, so
    Livewire saw a doctype and an <html> element and threw on every render.

    The page shell is deliberately absent: this is embedded in the host
    application's own layout, which supplies the document. The stylesheet is
    inlined for the same reason the packaged layout inlines it — it is smaller
    than the request that would fetch it, and it cannot break when a
    deployment forgets to republish assets.
--}}
<div class="cairn cairn-livewire">
    <style>{!! Divoto\Cairn\Support\Assets::css() !!}</style>

    @include('cairn::partials.dashboard')
</div>
