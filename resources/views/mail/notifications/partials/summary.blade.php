<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin:24px 0;border:1px solid #dce2e7;border-radius:10px;border-collapse:separate;">
<tr><td colspan="2" style="padding:14px 20px;background-color:#f7f8f1;border-bottom:1px solid #dce2e7;border-radius:10px 10px 0 0;">
<span style="font-size:12px;font-weight:800;letter-spacing:1.5px;text-transform:uppercase;color:#5b6b7c;">Order</span>
<span style="float:right;font-size:15px;font-weight:800;color:#092043;">{{ $v['orderNumber'] }}</span>
</td></tr>
@foreach ($v['items'] as $item)
<tr>
<td style="padding:12px 20px 12px {{ $item['child'] ? 40 : 20 }}px;border-bottom:1px solid #eef1f4;font-size:15px;color:{{ $item['child'] ? '#5b6b7c' : '#092043' }};">@if ($item['child'])&#8627; @endif{{ $item['name'] }}@if ($item['size'] !== '')<span style="color:#5b6b7c;"> &middot; {{ $item['size'] }}</span>@endif</td>
<td align="right" style="padding:12px 20px;border-bottom:1px solid #eef1f4;font-size:15px;color:#5b6b7c;white-space:nowrap;">&times; {{ $item['qty'] }}</td>
</tr>
@endforeach
<tr>
<td style="padding:16px 20px;font-size:15px;font-weight:700;color:#092043;">Total paid</td>
<td align="right" style="padding:16px 20px;font-size:18px;font-weight:800;color:#092043;white-space:nowrap;">{{ $v['total'] }}</td>
</tr>
</table>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin:0 0 8px;font-size:14px;line-height:1.6;">
<tr><td width="50%" valign="top" style="padding:0 10px 0 0;"><div style="font-size:12px;font-weight:800;letter-spacing:1.5px;text-transform:uppercase;color:#5b6b7c;">Payment</div><div style="color:#092043;">{{ $v['paymentMethod'] }}</div></td>
<td width="50%" valign="top" style="padding:0 0 0 10px;"><div style="font-size:12px;font-weight:800;letter-spacing:1.5px;text-transform:uppercase;color:#5b6b7c;">Delivery to</div><div style="color:#092043;">{{ implode(', ', $v['delivery']) }}</div></td></tr>
</table>
