@extends('mail.notifications.layout')
@section('eyebrow', 'Refund update')
@section('title', 'Update on your refund request')
@section('preheader', 'Update on your refund for order '.$v['orderNumber'].'.')
@section('body')
<p style="margin:0 0 16px;">Hi <strong style="color:#092043;">{{ $v['customerName'] }}</strong>,</p>
<p style="margin:0 0 16px;">After review, we are unable to approve the refund request for order <strong style="color:#092043;">{{ $v['orderNumber'] }}</strong>.</p>
<p style="margin:0;">If you have questions, please contact us using the details below.</p>
@endsection
