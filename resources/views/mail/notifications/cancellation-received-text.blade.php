@extends('mail.notifications.layout-text')
@section('body')
Hi {!! $v['customerName'] !!}, we received your cancellation/refund request for order {!! $v['orderNumber'] !!}.

This does not cancel your order. Our team will review the request and tell you the decision.

@endsection
