@extends('layouts.staff')

@section('title', 'スタッフ用シフト')

@section('content')
    <div class="staff-screen staff-screen--personal">
        <div class="personal-scale-wrapper" data-personal-scale-wrapper>
            <div class="personal-scale-content" data-personal-scale-content>
                <header class="staff-header">
                    @include('staff.partials.month-navigation')
                    <div class="staff-header__user">{{ $loginUser['name'] }}</div>
                    @include('staff.partials.store-menu')
                </header>

                @include('staff.partials.personal-calendar')
            </div>
        </div>
    </div>
@endsection
