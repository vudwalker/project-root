@extends('layouts.admin')

@section('title', 'スタッフ追加｜管理画面')

@push('styles')
    <link
        rel="stylesheet"
        href="{{ asset('css/admin-staff-management.css') }}?v={{ filemtime(public_path('css/admin-staff-management.css')) }}"
    >
@endpush

@section('content')
    @include('admin.staff.partials.form', [
        'formAction' => route('admin.staff.store'),
        'formMethod' => 'POST',
        'formTitle' => 'スタッフ追加',
        'isCreate' => true,
    ])
@endsection

@push('scripts')
    <script src="{{ asset('js/admin-staff-management.js') }}" defer></script>
@endpush
