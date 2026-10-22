@extends('mail.notifications.layout-text')
@section('body')
@include('mail.notifications.partials.progress-text', ['step' => 'Delivered'])

Hi {!! $v['firstName'] ?: 'there' !!},

Your order {!! $v['orderNumber'] !!} has been marked as delivered. We hope everything arrived safely and that you absolutely love your purchase.

Thank you for trusting Vidorck Hair. Your support means a lot to us, and we're grateful to have you as a customer.

If anything isn't right with your order, {{ array_filter($v['support']) ? 'reach out using the details below' : 'get in touch with us' }} and we'll be happy to help.

We'd love to see you shop with us again!

@include('mail.notifications.partials.signoff-text', ['closing' => 'With love'])
@endsection
