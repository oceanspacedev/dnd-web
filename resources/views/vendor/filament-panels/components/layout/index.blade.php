@php
    use Filament\Support\Enums\Width;
    use Filament\Support\Enums\MaxWidth;

    $livewire ??= null;
    $hasTopbar = filament()->hasTopbar();
    $hasNavigation = filament()->hasNavigation();
    $isSidebarCollapsibleOnDesktop = filament()->isSidebarCollapsibleOnDesktop();
    $isSidebarFullyCollapsibleOnDesktop = filament()->isSidebarFullyCollapsibleOnDesktop();
    $renderHookScopes = $livewire?->getRenderHookScopes();
    $defaultWidth = enum_exists(Width::class) ? Width::SevenExtraLarge : MaxWidth::SevenExtraLarge;
    $maxContentWidth ??= (filament()->getMaxContentWidth() ?? $defaultWidth);

    if (is_string($maxContentWidth)) {
        if (enum_exists(Width::class)) {
            $maxContentWidth = Width::tryFrom($maxContentWidth) ?? $maxContentWidth;
        } elseif (enum_exists(MaxWidth::class)) {
            $maxContentWidth = MaxWidth::tryFrom($maxContentWidth) ?? $maxContentWidth;
        }
    }
@endphp

<x-filament-panels::layout.base :livewire="$livewire">
    <div class="flex h-screen overflow-hidden bg-gray-50 fi-layout dark:bg-gray-950" x-data @keydown.window.escape="$store.sidebar.close()">
        @if ($hasNavigation)
            <div
                x-cloak
                x-data="{}"
                x-on:click="$store.sidebar.close()"
                x-show="$store.sidebar.isOpen"
                x-transition.opacity.300ms
                class="fi-sidebar-close-overlay"
            ></div>

            @persist('sidebar')
                @livewire(filament()->getSidebarLivewireComponent())
            @endpersist
        @endif

        <div
            @if ($isSidebarCollapsibleOnDesktop || $isSidebarFullyCollapsibleOnDesktop)
                x-data="{}"
                x-bind:class="{
                    'fi-main-ctn-sidebar-open': $store.sidebar.isOpen,
                }"
            @endif
            class="fi-main-ctn flex w-0 flex-1 flex-col overflow-hidden bg-white ring-1 ring-gray-200 lg:my-2 lg:rounded-tl-xl lg:rounded-bl-xl dark:bg-gray-900 dark:ring-white/20"
        >
            <div class="flex flex-1 flex-col justify-between overflow-hidden overflow-y-auto">
                @if ($hasTopbar)
                    @livewire(filament()->getTopbarLivewireComponent())
                @endif

                <main
                    @class([
                        'fi-main mky-main flex-1',
                        ($maxContentWidth instanceof \BackedEnum) ? "fi-width-{$maxContentWidth->value}" : $maxContentWidth,
                    ])
                >
                    {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::CONTENT_START, scopes: $renderHookScopes) }}

                    {{ $slot }}

                    {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::CONTENT_END, scopes: $renderHookScopes) }}
                </main>

                {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::FOOTER, scopes: $renderHookScopes) }}
            </div>
        </div>
    </div>
</x-filament-panels::layout.base>
