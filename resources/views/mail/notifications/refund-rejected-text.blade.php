@extends('mail.notifications.layout-text')
@section('body')
Hi {!! $v['customerName'] !!}, after review we are unable to approve the refund request for order {!! $v['orderNumber'] !!}.

If you have questions, please contact us using the details below.

@endsection
