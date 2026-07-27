@extends('layouts.staff')

@section('title', $store['name'].' 月間シフト表')

@section('content')
    <div class="staff-screen staff-screen--store">
        <header class="staff-header staff-header--store">
            @include('staff.partials.month-navigation', ['storeCode' => $storeCode])
            <div class="staff-header__user">{{ $loginUser['name'] }}</div>
            <a
                class="staff-header__top-link"
                href="{{ route('staff.top').'?'.http_build_query(['month' => $calendar['month_value']] + $query) }}"
            >TOP</a>
        </header>

        {{-- 店舗名・日付・シフト表はまとめて横スクロール領域に入れます。 --}}
        @include('staff.partials.store-shift-table')
    </div>
@endsection
