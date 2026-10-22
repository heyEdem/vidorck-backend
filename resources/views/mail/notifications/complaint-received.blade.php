@extends('mail.notifications.layout')
@section('eyebrow', 'Message received')
@section('title', 'We received your message')
@section('preheader', 'Your reference is '.$v['reference'].'.')
@section('body')
<p style="margin:0 0 16px;">Thank you for contacting Vidorck Hair. Our team has received your message and will follow up.</p>
@include('mail.notifications.partials.callout', ['tone' => 'info', 'html' => 'Your reference: <strong>'.e($v['reference']).'</strong>'])
@endsection
