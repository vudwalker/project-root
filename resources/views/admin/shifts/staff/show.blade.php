@extends('layouts.admin')

@section('title', 'スタッフ別シフト確認｜管理画面')

@section('content')
    @include('admin.shifts.partials.toolbar', ['screenType' => 'staff'])

    <section class="admin-shift-workspace" aria-labelledby="staff-shift-title">
        <h1 id="staff-shift-title" class="admin-visually-hidden">
            {{ $screen['contextName'] }} {{ $calendar['month_label'] }} スタッフ別シフト確認
        </h1>

        @include('admin.shifts.partials.grid', ['screenType' => 'staff'])

        <div class="admin-staff-readonly-note">
            <span>閲覧専用</span>
            <span>{{ $screen['saveStatus'] }}</span>
            <span @class(['is-warning' => $isNg])>{{ $screen['publishStatus'] }}</span>
        </div>
    </section>
@endsection
