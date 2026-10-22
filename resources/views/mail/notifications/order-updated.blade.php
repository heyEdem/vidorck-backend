@extends('mail.notifications.layout')
@section('eyebrow', 'Order updated')
@section('title', 'We updated your order')
@section('preheader', 'Updated items for order '.$v['orderNumber'].'.')
@section('body')
<p style="margin:0 0 16px;">Hi <strong style="color:#092043;">{{ $v['customerName'] }}</strong>,</p>
<p style="margin:0 0 16px;">As agreed with you, we have updated the items in order <strong style="color:#092043;">{{ $v['orderNumber'] }}</strong>. This is your order as it stands now.</p>
@include('mail.notifications.partials.summary')
@if ($v['amount'] !== null)@include('mail.notifications.partials.callout', ['tone' => 'info', 'html' => 'New items total: <strong>'.e($v['amount']).'</strong>. Any difference from what you paid has been settled with you directly.'])@endif
@include('mail.notifications.partials.track')
@endsection
