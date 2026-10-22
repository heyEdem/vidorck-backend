@include('mail.notifications.partials.button', ['href' => $v['trackUrl'], 'label' => 'Track your order'])
<p style="margin:0;font-size:13px;line-height:1.6;color:#5b6b7c;text-align:center;">Enter your order number and the phone number you used at checkout.<br><a href="{{ $v['trackUrl'] }}" style="color:#0a42d7;word-break:break-all;">{{ $v['trackUrl'] }}</a></p>
