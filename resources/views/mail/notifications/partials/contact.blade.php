@if (! empty($v['support']['whatsapp']) || ! empty($v['support']['phone']) || ! empty($v['support']['email']))
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color:#f7f8f1;border:1px solid #dce2e7;border-radius:10px;">
<tr><td style="padding:18px 22px;font-size:14px;line-height:1.7;color:#5b6b7c;">
<div style="margin:0 0 4px;font-size:14px;font-weight:700;color:#092043;">Need help?</div>
@if (! empty($v['support']['whatsapp']))<div>WhatsApp: <strong style="color:#092043;">{{ $v['support']['whatsapp'] }}</strong></div>@endif
@if (! empty($v['support']['phone']))<div>Phone: <strong style="color:#092043;">{{ $v['support']['phone'] }}</strong></div>@endif
@if (! empty($v['support']['email']))<div>Email: <a href="mailto:{{ $v['support']['email'] }}" style="color:#0a42d7;font-weight:700;text-decoration:none;">{{ $v['support']['email'] }}</a></div>@endif
</td></tr>
</table>
@endif
