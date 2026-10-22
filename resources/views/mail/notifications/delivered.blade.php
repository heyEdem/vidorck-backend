@extends('mail.notifications.layout')
@section('eyebrow', 'Delivered')
@section('title', 'Your order has been delivered!')
@section('preheader', 'Order '.$v['orderNumber'].' has arrived. We hope you love your purchase.')
@section('body')
@include('mail.notifications.partials.progress', ['step' => 'Delivered'])
<p style="margin:0 0 16px;">Hi <strong style="color:#092043;">{{ $v['firstName'] ?: 'there' }}</strong>,</p>
<p style="margin:0 0 16px;">Your order <strong style="color:#092043;">{{ $v['orderNumber'] }}</strong> has been marked as delivered. We hope everything arrived safely and that you absolutely love your purchase.</p>
@include('mail.notifications.partials.callout', ['tone' => 'success', 'html' => '<strong>Thank you for trusting Vidorck Hair.</strong> Your support means a lot to us, and we\'re grateful to have you as a customer.'])
<p style="margin:0 0 16px;">If anything isn't right with your order, @if (array_filter($v['support'])) reach out using the details below @else get in touch with us @endif and we'll be happy to help.</p>
<p style="margin:0;">We'd love to see you shop with us again!</p>
@include('mail.notifications.partials.signoff', ['closing' => 'With love'])
@endsection
