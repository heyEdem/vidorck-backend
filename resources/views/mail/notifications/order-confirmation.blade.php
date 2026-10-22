@extends('mail.notifications.layout')
@section('eyebrow', 'Payment confirmed')
@section('title', 'Your order is confirmed!')
@section('preheader', 'We received your payment of '.$v['total'].'. Order '.$v['orderNumber'].' is confirmed and being prepared.')
@section('body')
@include('mail.notifications.partials.progress', ['step' => 'Paid'])
<p style="margin:0 0 16px;">Hi <strong style="color:#092043;">{{ $v['firstName'] ?: 'there' }}</strong>,</p>
<p style="margin:0 0 16px;">Thank you for shopping with Vidorck Hair! We're happy to confirm that your payment of <strong style="color:#092043;">{{ $v['total'] }}</strong> has been received.</p>
<p style="margin:0 0 16px;">Our team is getting your order ready for dispatch. We'll keep you updated as it moves, so you'll know what to expect at every stage.</p>
@include('mail.notifications.partials.summary')
@include('mail.notifications.partials.callout', ['tone' => 'info', 'html' => '<strong>Delivery fee:</strong> '.e($v['feeNote'])])
@include('mail.notifications.partials.track')
<p style="margin:24px 0 0;">Thank you for choosing Vidorck Hair. We truly appreciate your support and can't wait for your order to reach you!</p>
@include('mail.notifications.partials.signoff')
@endsection
