@extends('mail.notifications.layout')
@section('eyebrow', 'Refund delayed')
@section('title', 'Problem with your refund')
@section('preheader', 'Refund issue for order '.$v['orderNumber'].'.')
@section('body')
<p style="margin:0 0 16px;">Hi <strong style="color:#092043;">{{ $v['customerName'] }}</strong>,</p>
<p style="margin:0 0 16px;">We could not complete the refund for order <strong style="color:#092043;">{{ $v['orderNumber'] }}</strong>.</p>
@include('mail.notifications.partials.callout', ['tone' => 'warning', 'html' => 'Our team is looking into it and will contact you. <strong>You do not need to do anything.</strong>'])
@endsection
