@extends('layouts.staff')

{{-- 選択中の店舗名をブラウザのタブに表示します。 --}}
@section('title', $store['name'].' 月間シフト表')

@section('content')
    <div class="staff-screen staff-screen--store">
        {{-- このヘッダーは表の横スクロール領域には含めません。 --}}
        <header class="staff-header staff-header--store">
            {{-- 店舗コードを渡すことで、月移動後も同じ店舗を表示します。 --}}
            @include('staff.partials.month-navigation', ['storeCode' => $storeCode])
            {{-- ログイン中スタッフの名前です。 --}}
            <div class="staff-header__user">{{ $loginUser['name'] }}</div>
            {{-- 表示中の年月を保ったまま個人画面へ戻ります。 --}}
            <a
                class="staff-header__top-link"
                href="{{ route('staff.top').'?'.http_build_query(['month' => $calendar['month_value']] + $query) }}"
            >TOP</a>
        </header>

        {{-- 店舗名・日付・シフト表はまとめて横スクロール領域に入れます。 --}}
        @include('staff.partials.store-shift-table')
    </div>
@endsection
