@extends('mail.notifications.layout')
@section('eyebrow', 'On its way')
@section('title', 'Your order is on its way!')
@section('preheader', 'Order '.$v['orderNumber'].' has been dispatched. Rider details will follow on WhatsApp.')
@section('body')
@include('mail.notifications.partials.progress', ['step' => 'On its way'])
<p style="margin:0 0 16px;">Hi <strong style="color:#092043;">{{ $v['firstName'] ?: 'there' }}</strong>,</p>
<p style="margin:0 0 16px;">It's time for the exciting part! Your order <strong style="color:#092043;">{{ $v['orderNumber'] }}</strong> has been dispatched and is on its way to you.</p>
@include('mail.notifications.partials.callout', ['tone' => 'info', 'html' => '<strong>Rider details:</strong> we\'ll share them on WhatsApp as soon as they\'re confirmed. Please keep your phone nearby so you don\'t miss any delivery updates.'])
@include('mail.notifications.partials.track')
<p style="margin:24px 0 0;">Thank you for choosing Vidorck Hair. We appreciate your trust in us and look forward to getting your order safely into your hands.</p>
@include('mail.notifications.partials.signoff')
@endsection
