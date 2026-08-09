{{--
    The Livewire dashboard as a full page.

    Used only when `cairn.dashboard.driver` is `livewire`, which is also the
    only condition under which the component it names is registered.

    `@livewire` rather than `<livewire:cairn-dashboard />` on purpose. This file
    lives under `resources/views`, which is registered as the `cairn::`
    namespace unconditionally, and `view:cache` compiles every Blade file in
    every registered path with no regard for configuration or class existence.
    An unregistered *directive* compiles to its own literal text and the command
    passes; an unresolvable *component tag* is a compile error, and would fail
    the deployment of every application that had Cairn without Livewire — the
    same way the Pulse cards' views once did.

    `embedded: false` tells the component the shell around it already carries
    the stylesheet, so it does not inline a second copy.
--}}
<x-cairn::layout :path="$path" title="Cairn">
    @livewire('cairn-dashboard', ['embedded' => false])
</x-cairn::layout>
