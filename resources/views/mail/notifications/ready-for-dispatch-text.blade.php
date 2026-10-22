@extends('mail.notifications.layout-text')
@section('body')
@include('mail.notifications.partials.progress-text', ['step' => 'Packed'])

Hi {!! $v['firstName'] ?: 'there' !!},

Good news! Your order {!! $v['orderNumber'] !!} has been packed and is ready for dispatch. It's almost on its way.

What happens next: we're arranging your delivery and will contact you by WhatsApp or phone to confirm your courier and delivery fee before dispatch.

Please keep an eye on your phone so we can coordinate the delivery with you.

@include('mail.notifications.partials.track-text')

Thank you for shopping with Vidorck Hair. We're working to get your order to you smoothly and safely!

@include('mail.notifications.partials.signoff-text')
@endsection
