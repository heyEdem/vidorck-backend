@extends('mail.notifications.layout-text')
@section('body')
@include('mail.notifications.partials.progress-text', ['step' => 'Paid'])

Hi {!! $v['firstName'] ?: 'there' !!},

Thank you for shopping with Vidorck Hair! We're happy to confirm that your payment of {!! $v['total'] !!} has been received.

Our team is getting your order ready for dispatch. We'll keep you updated as it moves, so you'll know what to expect at every stage.

@include('mail.notifications.partials.summary-text')

Delivery fee: {!! $v['feeNote'] !!}

@include('mail.notifications.partials.track-text')

Thank you for choosing Vidorck Hair. We truly appreciate your support and can't wait for your order to reach you!

@include('mail.notifications.partials.signoff-text')
@endsection
