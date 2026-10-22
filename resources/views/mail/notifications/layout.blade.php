<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="x-apple-disable-message-reformatting">
<meta name="color-scheme" content="light only">
<meta name="supported-color-schemes" content="light only">
<title>@yield('title')</title>
<style>
  body { margin:0 !important; padding:0 !important; width:100% !important; }
  a { color:#0a42d7; }
  @media only screen and (max-width:620px) {
    .vh-outer { padding:16px 8px !important; }
    .vh-pad { padding-left:24px !important; padding-right:24px !important; }
    .vh-title { font-size:22px !important; }
  }
</style>
</head>
<body style="margin:0;padding:0;background-color:#f7f8f1;font-family:'Nestlé Text','Trebuchet MS',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#092043;-webkit-text-size-adjust:100%;">
{{-- Inbox preview text; hidden in the message itself. --}}
<div style="display:none;max-height:0;overflow:hidden;opacity:0;mso-hide:all;">@hasSection('preheader')@yield('preheader')@else @yield('title')@endif&#8204;&nbsp;&#8204;&nbsp;&#8204;&nbsp;&#8204;&nbsp;&#8204;&nbsp;</div>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color:#f7f8f1;">
<tr><td align="center" class="vh-outer" style="padding:40px 12px;">
  <table role="presentation" width="600" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:600px;background-color:#ffffff;border-radius:14px;overflow:hidden;box-shadow:0 4px 18px rgba(9,32,67,0.10);">
    {{-- Header --}}
    <tr><td align="center" class="vh-pad" style="background-color:#071c78;background-image:linear-gradient(135deg,#071c78 0%,#0a42d7 100%);padding:36px 40px 32px;">
      @if (config('mail.logo_url'))
        <img src="{{ config('mail.logo_url') }}" alt="Vidorck Hair" width="132" style="display:block;margin:0 auto 18px;border:0;max-width:132px;height:auto;">
      @else
        <div style="margin:0 0 18px;font-size:13px;font-weight:800;letter-spacing:4px;text-transform:uppercase;color:#a8e720;">Vidorck Hair</div>
      @endif
      @hasSection('eyebrow')
        <div style="margin:0 0 12px;"><span style="display:inline-block;padding:5px 14px;border-radius:999px;background-color:#a8e720;color:#092043;font-size:11px;font-weight:800;letter-spacing:1.5px;text-transform:uppercase;">@yield('eyebrow')</span></div>
      @endif
      <h1 class="vh-title" style="margin:0;color:#ffffff;font-size:26px;line-height:1.3;font-weight:700;">@yield('title')</h1>
    </td></tr>
    <tr><td style="height:5px;line-height:5px;font-size:0;background-color:#a8e720;">&nbsp;</td></tr>
    {{-- Body --}}
    <tr><td class="vh-pad" style="padding:40px;font-size:16px;line-height:1.65;color:#2c3e55;">
      @yield('body')
    </td></tr>
    {{-- Help --}}
    <tr><td class="vh-pad" style="padding:0 40px 32px;">
      @include('mail.notifications.partials.contact')
    </td></tr>
    {{-- Footer --}}
    <tr><td align="center" class="vh-pad" style="background-color:#04143e;padding:26px 40px;">
      <div style="font-size:13px;font-weight:800;letter-spacing:3px;text-transform:uppercase;color:#a8e720;">Vidorck Hair</div>
      <div style="margin-top:8px;font-size:12px;line-height:1.6;color:#aab6c8;">&copy; {{ date('Y') }} Vidorck Hair. All rights reserved.</div>
    </td></tr>
  </table>
</td></tr>
</table>
</body>
</html>
