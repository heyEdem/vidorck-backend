@extends('mail.notifications.layout')
@section('eyebrow', 'Packed')
@section('title', 'Your order is packed and ready!')
@section('preheader', 'Good news! Order '.$v['orderNumber'].' is packed. We will contact you to arrange delivery.')
@section('body')
@include('mail.notifications.partials.progress', ['step' => 'Packed'])
<p style="margin:0 0 16px;">Hi <strong style="color:#092043;">{{ $v['firstName'] ?: 'there' }}</strong>,</p>
<p style="margin:0 0 16px;">Good news! Your order <strong style="color:#092043;">{{ $v['orderNumber'] }}</strong> has been packed and is ready for dispatch. It's almost on its way.</p>
@include('mail.notifications.partials.callout', ['tone' => 'info', 'html' => '<strong>What happens next:</strong> we\'re arranging your delivery and will contact you by WhatsApp or phone to confirm your courier and delivery fee before dispatch.'])
<p style="margin:0 0 16px;">Please keep an eye on your phone so we can coordinate the delivery with you.</p>
@include('mail.notifications.partials.track')
<p style="margin:24px 0 0;">Thank you for shopping with Vidorck Hair. We're working to get your order to you smoothly and safely!</p>
@include('mail.notifications.partials.signoff')
@endsection
