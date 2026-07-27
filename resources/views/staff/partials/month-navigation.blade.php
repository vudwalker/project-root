@php
    /*
     * 店舗コードがある場合は店舗別画面、ない場合は個人画面のURLを作ります。
     * これにより、月を移動しても現在の画面種類を保てます。
     */
    $navigationRoute = $storeCode ?? null
        ? route('staff.store', ['store' => $storeCode])
        : route('staff.top');

    // $queryには、画面間で引き継ぐ追加のクエリ条件が入ります。
    $previousUrl = $navigationRoute.'?'.http_build_query(['month' => $calendar['previous_month']] + $query);
    $nextUrl = $navigationRoute.'?'.http_build_query(['month' => $calendar['next_month']] + $query);
@endphp

<nav class="month-navigation" aria-label="月の切り替え">
    {{-- 通常のリンクなので、JavaScriptが無効でも前月・翌月へ移動できます。 --}}
    <a class="month-navigation__arrow" href="{{ $previousUrl }}" aria-label="前月を表示">◁</a>
    <span class="month-navigation__label">{{ $calendar['month_label'] }}</span>
    <a class="month-navigation__arrow" href="{{ $nextUrl }}" aria-label="翌月を表示">▷</a>
</nav>
