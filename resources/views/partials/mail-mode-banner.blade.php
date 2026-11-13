{{-- Internal diagnostic for Admins only: says when this copy cannot reach ordinary recipients.
     Citizens, Notaries and visitors never see it; their pages say only what happened to their own email. --}}
@if(auth()->user()?->isAdmin())
@php($mailMode = \App\Support\Platform::mailMode())
@if($mailMode === 'safe_preview')
    <div class="en-mode-banner en-no-print" role="status">{{ __('Safe Preview: no real email or SMS is sent. Messages are stored in the preview only.') }}</div>
    @if(\App\Support\Platform::smsMode() === 'mock')<div class="en-mode-banner en-no-print" role="status">{{ __('Mock SMS: nothing reaches a phone. Read test codes at') }} <a href="http://127.0.0.1:8003" target="_blank" rel="noopener">127.0.0.1:8003</a></div>@endif
@elseif($mailMode === 'local_gmail')
    <div class="en-mode-banner is-live en-no-print" role="status">{{ \App\Support\Platform::smsMode() === 'off' ? __('Local Gmail: real email goes only to approved local test accounts. All others are blocked. SMS is off.') : __('Local Gmail: real email goes only to approved local test accounts. All others are blocked. Paid SMS is on, for one approved number only.') }}</div>
@elseif($mailMode === 'misconfigured')
    <div class="en-mode-banner is-live en-no-print" role="status">{{ __('Live Mail is switched on but not set up correctly, so no email is sent.') }}</div>
@elseif($mailMode === 'live' && \App\Support\Platform::liveMailApplies())
    <div class="en-mode-banner is-live en-no-print" role="status">{{ __('Live Mail: real email is sent through Gmail for account, application, appointment and support events. Watch Delivery for failures.') }}</div>
@elseif($mailMode === 'live_test' && \App\Support\Platform::liveMailApplies())
    <div class="en-mode-banner is-live en-no-print" role="status">{{ __('Live Mail: real email goes only to approved addresses. All other recipients are blocked.') }}</div>
@elseif($mailMode === 'live_test')
    <div class="en-mode-banner is-live en-no-print" role="status">{{ __('Live Test: real messages go only to approved test recipients. All others are blocked.') }}</div>
@endif
@endif
