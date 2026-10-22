@extends('mail.notifications.layout-text')
@section('body')
@include('mail.notifications.partials.progress-text', ['step' => 'On its way'])

Hi {!! $v['firstName'] ?: 'there' !!},

It's time for the exciting part! Your order {!! $v['orderNumber'] !!} has been dispatched and is on its way to you.

Rider details: we'll share them on WhatsApp as soon as they're confirmed. Please keep your phone nearby so you don't miss any delivery updates.

@include('mail.notifications.partials.track-text')

Thank you for choosing Vidorck Hair. We appreciate your trust in us and look forward to getting your order safely into your hands.

@include('mail.notifications.partials.signoff-text')
@endsection
