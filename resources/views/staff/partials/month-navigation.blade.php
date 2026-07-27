@php
    $navigationRoute = $storeCode ?? null
        ? route('staff.store', ['store' => $storeCode])
        : route('staff.top');
    $previousUrl = $navigationRoute.'?'.http_build_query(['month' => $calendar['previous_month']] + $query);
    $nextUrl = $navigationRoute.'?'.http_build_query(['month' => $calendar['next_month']] + $query);
@endphp

<nav class="month-navigation" aria-label="月の切り替え">
    <a class="month-navigation__arrow" href="{{ $previousUrl }}" aria-label="前月を表示">◁</a>
    <span class="month-navigation__label">{{ $calendar['month_label'] }}</span>
    <a class="month-navigation__arrow" href="{{ $nextUrl }}" aria-label="翌月を表示">▷</a>
</nav>
