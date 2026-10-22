{{-- Order journey tracker: $step is the current stage (Paid | Packed | On its way | Delivered). --}}
@php($steps = ['Paid', 'Packed', 'On its way', 'Delivered'])
@php($at = array_search($step, $steps, true))
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin:8px 0 28px;table-layout:fixed;">
<tr>
@foreach ($steps as $i => $label)
@php($state = $i < $at ? 'done' : ($i === $at ? 'current' : 'upcoming'))
<td data-step="{{ $label }}" data-state="{{ $state }}" valign="top" style="padding:0 {{ $i < 3 ? 4 : 0 }}px 0 {{ $i > 0 ? 4 : 0 }}px;">
<div style="height:6px;line-height:6px;font-size:0;border-radius:999px;background-color:{{ $state === 'upcoming' ? '#dce2e7' : ($state === 'current' ? '#a8e720' : '#0a42d7') }};">&nbsp;</div>
<div style="padding-top:8px;font-size:12px;line-height:1.3;font-weight:{{ $state === 'upcoming' ? 600 : 800 }};color:{{ $state === 'upcoming' ? '#93a1b0' : '#092043' }};">@if ($state === 'done')&#10003; @endif{{ $label }}</div>
</td>
@endforeach
</tr>
</table>
