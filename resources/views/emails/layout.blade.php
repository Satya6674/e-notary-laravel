<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="color-scheme" content="light">
<title>{{ $subject ?? config('app.name') }}</title>
</head>
<body style="margin:0;padding:0;background:#FAF8F3;font-family:Arial,Helvetica,sans-serif;color:#24263A;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#FAF8F3;padding:24px 12px;">
<tr><td align="center">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#FFFFFF;border:1px solid #E7E3D8;border-radius:12px;overflow:hidden;">
  <tr>
    <td style="background:#171B34;border-bottom:3px solid #D5AF48;padding:20px 28px;">
      <span style="display:inline-block;width:32px;height:32px;line-height:32px;background:#C9252B;border-radius:7px;color:#FFFFFF;font-weight:700;text-align:center;font-size:16px;vertical-align:middle;">E</span>
      <span style="color:#FFFFFF;font-family:Georgia,'Times New Roman',serif;font-size:21px;font-weight:700;margin-left:10px;vertical-align:middle;">E-Notary</span>
    </td>
  </tr>
  <tr>
    <td style="padding:28px;font-size:15px;line-height:1.6;">
      @yield('body')
    </td>
  </tr>
  <tr>
    <td style="padding:18px 28px;background:#FAF8F3;font-size:12px;color:#6A6C80;line-height:1.5;border-top:1px solid #E7E3D8;">
      This message was sent by E-Notary ({{ config('app.url') }}), an independent software platform.
      It is not a government service and provides no legally recognised notarisation.
      If you did not expect this email you can ignore it.
      @if(config('mail.support_address'))
        Need help? Contact <a href="mailto:{{ config('mail.support_address') }}" style="color:#C9252B;">{{ config('mail.support_address') }}</a>.
      @endif
    </td>
  </tr>
</table>
</td></tr>
</table>
</body>
</html>
