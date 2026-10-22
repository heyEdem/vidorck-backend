@extends('mail.notifications.layout-text')
@section('body')
Hi {!! $v['customerName'] !!}, your refund for order {!! $v['orderNumber'] !!} has been completed.

@if ($v['amount'] !== null)
Amount refunded: {!! $v['amount'] !!}
@endif

It can take a few days to appear with your bank or mobile money provider.

@endsection
