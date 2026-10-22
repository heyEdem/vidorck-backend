{{-- $tone: info | success | warning; content via $slot-like $html. --}}
@php($tones = ['info' => ['#eef3ff', '#0a42d7', '#071c78'], 'success' => ['#edf8dd', '#149447', '#225c20'], 'warning' => ['#fff6e5', '#d98a00', '#7a4b00']])
@php([$bg, $edge, $ink] = $tones[$tone ?? 'info'])
<div style="margin:24px 0;padding:16px 20px;background-color:{{ $bg }};border-left:4px solid {{ $edge }};border-radius:6px;font-size:15px;line-height:1.6;color:{{ $ink }};">{!! $html !!}</div>
