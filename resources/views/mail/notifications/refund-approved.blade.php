@extends('mail.notifications.layout')
@section('eyebrow', 'Refund approved')
@section('title', 'Your refund was approved')
@section('preheader', 'Refund approved for order '.$v['orderNumber'].'.')
@section('body')
<p style="margin:0 0 16px;">Hi <strong style="color:#092043;">{{ $v['customerName'] }}</strong>,</p>
<p style="margin:0 0 16px;">Your refund request for order <strong style="color:#092043;">{{ $v['orderNumber'] }}</strong> was approved.</p>
@if ($v['amount'] !== null)@include('mail.notifications.partials.callout', ['tone' => 'success', 'html' => 'Refund amount: <strong>'.e($v['amount']).'</strong>'])@endif
<p style="margin:0;">We will email you again once the refund has been processed.</p>
@endsection
