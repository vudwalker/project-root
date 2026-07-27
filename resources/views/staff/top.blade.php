@extends('layouts.staff')

{{-- ブラウザのタブに表示する画面名です。 --}}
@section('title', 'スタッフ用シフト')

@section('content')
    <div class="staff-screen staff-screen--personal">
        {{--
            スマートフォンでは、このwrapperの横幅に収まるように
            中のヘッダーとカレンダーをJavaScriptでまとめて縮小します。
        --}}
        <div class="personal-scale-wrapper" data-personal-scale-wrapper>
            <div class="personal-scale-content" data-personal-scale-content>
                <header class="staff-header">
                    {{-- 前月・表示中の年月・翌月を表示する共通部品です。 --}}
                    @include('staff.partials.month-navigation')
                    {{-- Controllerから渡されたログイン中スタッフの名前です。 --}}
                    <div class="staff-header__user">{{ $loginUser['name'] }}</div>
                    {{-- 個人画面と店舗別画面を切り替えるメニューです。 --}}
                    @include('staff.partials.store-menu')
                </header>

                {{-- 1か月分の個人シフトカレンダー本体です。 --}}
                @include('staff.partials.personal-calendar')
            </div>
        </div>
    </div>
@endsection
