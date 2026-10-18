@php
    use App\Support\ObservabilityAccess;
    use Filament\Support\Enums\IconSize;
    use Illuminate\View\ComponentAttributeBag;

    use function Filament\Support\generate_icon_html;

    $user = auth()->user();
    $canAccess = ObservabilityAccess::allowed($user);
    $horizonUrl = url('/'.trim((string) config('horizon.path', 'horizon'), '/'));
    $logViewerUrl = url('/'.trim((string) config('log-viewer.route_path', 'log-viewer'), '/'));
@endphp

@if ($canAccess)
    <div class="mky-sidebar-group">
        <ul role="list" class="mky-sidebar-group-items">
            <li class="mky-sidebar-item">
                <a
                    href="{{ $horizonUrl }}"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="mky-sidebar-item-link"
                    x-tooltip="{
                        content: @js('Horizon'),
                        placement: document.dir === 'rtl' ? 'left' : 'right',
                        theme: $store.theme,
                        onShow: () => $store.sidebar.isCollapsed,
                    }"
                >
                    {{
                        generate_icon_html(
                            'heroicon-o-queue-list',
                            attributes: (new ComponentAttributeBag)->class(['mky-sidebar-item-icon']),
                            size: IconSize::Large,
                        )
                    }}

                    <span
                        class="mky-sidebar-item-label"
                        x-cloak
                        x-show="! $store.sidebar.isCollapsed"
                        x-transition:enter="transition-opacity duration-200"
                        x-transition:enter-start="opacity-0"
                        x-transition:enter-end="opacity-100"
                    >
                        Horizon
                    </span>
                </a>
            </li>

            <li class="mky-sidebar-item">
                <a
                    href="{{ $logViewerUrl }}"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="mky-sidebar-item-link"
                    x-tooltip="{
                        content: @js('Log Viewer'),
                        placement: document.dir === 'rtl' ? 'left' : 'right',
                        theme: $store.theme,
                        onShow: () => $store.sidebar.isCollapsed,
                    }"
                >
                    {{
                        generate_icon_html(
                            'heroicon-o-document-magnifying-glass',
                            attributes: (new ComponentAttributeBag)->class(['mky-sidebar-item-icon']),
                            size: IconSize::Large,
                        )
                    }}

                    <span
                        class="mky-sidebar-item-label"
                        x-cloak
                        x-show="! $store.sidebar.isCollapsed"
                        x-transition:enter="transition-opacity duration-200"
                        x-transition:enter-start="opacity-0"
                        x-transition:enter-end="opacity-100"
                    >
                        Log Viewer
                    </span>
                </a>
            </li>
        </ul>
    </div>
@endif
