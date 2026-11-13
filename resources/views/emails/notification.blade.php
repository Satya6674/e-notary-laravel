@extends('emails.layout')

@section('body')
  <p style="margin:0 0 16px;font-size:16px;">{{ $greeting ?? 'Hello,' }}</p>

  @foreach($introLines as $line)
    <p style="margin:0 0 14px;">{{ $line }}</p>
  @endforeach

  @if(!empty($reference))
    <p style="margin:0 0 14px;padding:10px 14px;background:#F4EBD0;border-left:4px solid #D5AF48;border-radius:6px;">
      <strong>Reference:</strong> {{ $reference }}
    </p>
  @endif

  @if(!empty($actionUrl))
    <table role="presentation" cellpadding="0" cellspacing="0" style="margin:22px 0;">
      <tr><td style="background:#C9252B;border-radius:8px;">
        <a href="{{ $actionUrl }}" style="display:inline-block;padding:12px 22px;color:#ffffff;text-decoration:none;font-weight:700;font-size:15px;">{{ $actionText }}</a>
      </td></tr>
    </table>
  @endif

  @foreach($outroLines as $line)
    <p style="margin:0 0 14px;">{{ $line }}</p>
  @endforeach

  @if(!empty($actionUrl))
    <p style="margin:18px 0 0;font-size:12px;color:#6A6C80;word-break:break-all;">
      If the button does not work, copy this link into your browser:<br>
      <a href="{{ $actionUrl }}" style="color:#C9252B;">{{ $actionUrl }}</a>
    </p>
  @endif
@endsection
