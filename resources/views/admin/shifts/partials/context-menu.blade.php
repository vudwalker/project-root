<details class="admin-context-menu">
    <summary class="admin-context-menu__summary">{{ $screen['contextName'] }}</summary>
    <div class="admin-context-menu__panel">
        @foreach ($contextOptions as $option)
            <a
                class="admin-context-menu__link"
                href="{{ $option['url'] }}"
                @if ($option['current']) aria-current="page" @endif
            >
                {{ $option['label'] }}
            </a>
        @endforeach
    </div>
</details>
