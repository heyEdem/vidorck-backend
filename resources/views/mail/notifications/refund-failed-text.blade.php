@extends('mail.notifications.layout-text')
@section('body')
Hi {!! $v['customerName'] !!}, we could not complete the refund for order {!! $v['orderNumber'] !!}.

Our team is looking into it and will contact you. You do not need to do anything.

@endsection
