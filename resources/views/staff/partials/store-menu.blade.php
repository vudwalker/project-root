@php
    // 個人画面では店舗未選択のためnull、店舗別画面では店舗コードが入ります。
    $currentStoreCode = $storeCode ?? null;
@endphp

<div class="store-menu" data-store-menu>
    {{--
        data-store-menu-triggerはJavaScriptが開閉ボタンを見つけるための目印です。
        aria-expandedは、メニューの開閉状態を支援技術へ伝えます。
    --}}
    <button
        class="store-menu__trigger"
        type="button"
        aria-expanded="false"
        aria-controls="store-menu-list"
        data-store-menu-trigger
    >
        @if ($currentStoreCode && isset($stores[$currentStoreCode]))
            {{ $stores[$currentStoreCode]['name'] }} ▽
        @else
            店舗別▽
        @endif
    </button>

    {{-- hidden属性はJavaScriptで付け外しし、店舗一覧を開閉します。 --}}
    <div class="store-menu__list" id="store-menu-list" data-store-menu-list hidden>
        @foreach ($stores as $availableStore)
            <a
                class="store-menu__link"
                {{-- 表示中の年月と追加条件を保ったまま、選んだ店舗へ移動します。 --}}
                href="{{ route('staff.store', ['store' => $availableStore['code']]).'?'.http_build_query(['month' => $calendar['month_value']] + $query) }}"
                @if ($currentStoreCode === $availableStore['code']) aria-current="page" @endif
            >
                {{ $availableStore['name'] }}
            </a>
        @endforeach
    </div>

    {{-- JavaScript無効時も店舗移動できる通常リンクを残します。 --}}
    <noscript>
        <div class="store-menu__fallback">
            @foreach ($stores as $availableStore)
                <a href="{{ route('staff.store', ['store' => $availableStore['code']]).'?'.http_build_query(['month' => $calendar['month_value']] + $query) }}">
                    {{ $availableStore['name'] }}
                </a>
            @endforeach
        </div>
    </noscript>
</div>
