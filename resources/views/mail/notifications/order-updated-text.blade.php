@extends('mail.notifications.layout-text')
@section('body')
Hi {!! $v['customerName'] !!}, as agreed with you, we have updated the items in order {!! $v['orderNumber'] !!}. This is your order as it stands now.

@include('mail.notifications.partials.summary-text')

@if ($v['amount'] !== null)
New items total: {!! $v['amount'] !!}. Any difference from what you paid has been settled with you directly.
@endif

@include('mail.notifications.partials.track-text')

@endsection
