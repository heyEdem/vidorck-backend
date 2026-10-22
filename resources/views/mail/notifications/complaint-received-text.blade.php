@extends('mail.notifications.layout-text')
@section('body')
Thank you for contacting Vidorck Hair. Our team has received your message and will follow up.

Your reference: {!! $v['reference'] !!}
@endsection
