@extends('layouts.app')

@section('pageTitle', trans('messages.admin.manage_sponsors'))

@section('content')
    <div class="page-header">
        <h1>{{ trans('messages.sponsor.list') }}</h1>
        @include('components.breadcrumbs.admin')
    </div>

    @include('components.errors')

    <div class="row">
        @include('admin.partials.admin-sidebar')

        <div class="col-md-9">
            @include('sponsors.partials.table', ['sponsors' => $sponsors])
        </div>
    </div>
@endsection
