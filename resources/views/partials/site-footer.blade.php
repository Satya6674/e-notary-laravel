{{-- Links come from config/site_links.php: change them there, not here. --}}
@php($contact = \App\Support\SiteLinks::contact())
<footer class="en-footer en-no-print">
    <div class="en-wrap">
        <div class="en-footer-grid">
            <div class="en-footer-brand">
                <a class="en-logo mb-3" href="{{ route('home') }}">
                    <span class="en-brand-mark" aria-hidden="true"><i class="fas fa-gavel"></i></span>
                    <span class="en-brand-name">E-Notary</span>
                </a>
                <p>{{ __('One place for citizens, notaries and administrators to submit documents, follow each step and keep an organized record.') }}</p>
                @if($contact['email'] || $contact['phone'])
                    <h2>{{ __('Contact') }}</h2>
                    <ul>
                        @if($contact['email'])<li><a href="mailto:{{ $contact['email'] }}"><i class="fas fa-envelope me-2" aria-hidden="true"></i>{{ $contact['email'] }}</a></li>@endif
                        @if($contact['phone'])<li><a href="tel:{{ preg_replace('/[^0-9+]/', '', $contact['phone']) }}"><i class="fas fa-phone me-2" aria-hidden="true"></i>{{ $contact['phone'] }}</a></li>@endif
                    </ul>
                @endif
                @if($contact['social'])
                    <ul class="en-footer-social">
                        @foreach($contact['social'] as $link)
                            <li><a href="{{ $link['url'] }}" rel="noopener" target="_blank" aria-label="{{ $link['label'] }}"><i class="fas {{ $link['icon'] }}" aria-hidden="true"></i></a></li>
                        @endforeach
                    </ul>
                @endif
            </div>
            @foreach(\App\Support\SiteLinks::footer() as $column)
                <div class="{{ $column['wide'] ? 'en-footer-wide' : '' }}">
                    <h2>{{ __($column['title']) }}</h2>
                    @if($column['links'])
                        <ul>
                            @foreach($column['links'] as $link)
                                <li><a href="{{ $link['url'] }}">{{ __($link['label']) }}</a></li>
                            @endforeach
                        </ul>
                    @endif
                    @if($column['categories'])
                        <ul class="en-footer-cats">
                            @foreach($column['categories'] as $category)
                                <li>
                                    <a href="{{ $category['url'] }}">
                                        <i class="fas {{ $category['icon'] }}" aria-hidden="true"></i>
                                        <span><strong>{{ __($category['name']) }}</strong>{{ __($category['text']) }}</span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                    @if($column['cta'])
                        <a class="en-footer-cta" href="{{ $column['cta']['url'] }}">{{ __($column['cta']['label']) }} <i class="fas fa-arrow-right" aria-hidden="true"></i></a>
                    @endif
                </div>
            @endforeach
        </div>
        <div class="en-footer-base">
            <span>&copy; {{ date('Y') }} E-Notary</span>
            <span>@include('partials.disclosure')</span>
        </div>
    </div>
</footer>
