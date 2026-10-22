@extends('mail.notifications.layout-text')
@section('body')
Hi {!! $v['customerName'] !!}, your refund request for order {!! $v['orderNumber'] !!} was approved.

@if ($v['amount'] !== null)
Refund amount: {!! $v['amount'] !!}
@endif

We will email you again once the refund has been processed.

@endsection
