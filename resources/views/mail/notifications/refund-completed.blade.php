@extends('mail.notifications.layout')
@section('eyebrow', 'Refund complete')
@section('title', 'Your refund is complete')
@section('preheader', 'Refund completed for order '.$v['orderNumber'].'.')
@section('body')
<p style="margin:0 0 16px;">Hi <strong style="color:#092043;">{{ $v['customerName'] }}</strong>,</p>
<p style="margin:0 0 16px;">Your refund for order <strong style="color:#092043;">{{ $v['orderNumber'] }}</strong> has been completed.</p>
@if ($v['amount'] !== null)@include('mail.notifications.partials.callout', ['tone' => 'success', 'html' => 'Amount refunded: <strong>'.e($v['amount']).'</strong>'])@endif
<p style="margin:0;">It can take a few days to appear with your bank or mobile money provider.</p>
@endsection
