@extends('mail.notifications.layout')
@section('eyebrow', 'Request received')
@section('title', 'We received your cancellation request')
@section('preheader', 'We are reviewing your request for order '.$v['orderNumber'].'.')
@section('body')
<p style="margin:0 0 16px;">Hi <strong style="color:#092043;">{{ $v['customerName'] }}</strong>,</p>
<p style="margin:0 0 16px;">We received your cancellation/refund request for order <strong style="color:#092043;">{{ $v['orderNumber'] }}</strong>.</p>
@include('mail.notifications.partials.callout', ['tone' => 'warning', 'html' => '<strong>This does not cancel your order.</strong> Our team will review the request and let you know the decision.'])
@endsection
