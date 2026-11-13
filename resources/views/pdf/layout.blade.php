{{-- Shared design for every generated PDF. Each document supplies only its own body. --}}
<html lang="{{ app()->getLocale() }}">
<head>
<style>
    @page { header: html_enHeader; footer: html_enFooter; background: url('{{ resource_path('pdf/band-'.($landscape ?? false ? 'landscape' : 'portrait').'.svg') }}') no-repeat 0 0; background-image-resize: 6; }
    body { color: #24263A; font-size: 10pt; line-height: 1.4; }
    h2 { color: #171B34; font-size: 12pt; font-weight: bold; margin: 12pt 0 5pt; padding-bottom: 3pt; border-bottom: 1.2pt solid #D5AF48; page-break-after: avoid; }
    h3 { color: #171B34; font-size: 10.5pt; font-weight: bold; margin: 0 0 4pt; }
    p { margin: 0 0 6pt; }
    .band td { color: #FFFFFF; vertical-align: middle; }
    .tile { background-color: #C9252B; border: 1pt solid #D5AF48; color: #FFFFFF; font-size: 15pt; font-weight: bold; text-align: center; width: 10mm; height: 10mm; }
    .doc-title { color: #FFFFFF; font-size: 15pt; font-weight: bold; line-height: 1.15; }
    .doc-brand { color: #D5AF48; font-size: 8.5pt; }
    .meta { color: #FFFFFF; font-size: 8pt; text-align: right; line-height: 1.35; }
    .foot { border-top: 0.4pt solid #E7E3D8; color: #6A6C80; font-size: 7pt; padding-top: 2mm; }
    .page { color: #171B34; font-size: 8pt; font-weight: bold; text-align: right; white-space: nowrap; }
    table.fields { border-collapse: collapse; overflow: wrap; width: 100%; }
    table.fields td { border-bottom: 0.4pt solid #E7E3D8; padding: 3pt 0; vertical-align: top; }
    table.fields td.k { color: #6A6C80; font-size: 9pt; font-weight: bold; padding-right: 8pt; width: 32%; }
    table.grid { border-collapse: collapse; overflow: wrap; width: 100%; }
    table.grid th { background-color: #171B34; color: #FFFFFF; font-size: 8pt; font-weight: bold; padding: 4pt 5pt; text-align: left; }
    table.grid td { border-bottom: 0.4pt solid #E7E3D8; font-size: 8.5pt; padding: 4pt 5pt; vertical-align: top; }
    table.grid tr.alt td { background-color: #FAF8F3; }
    .empty { color: #6A6C80; font-size: 9pt; }
    .note { background-color: #FAF8F3; border-left: 2pt solid #D5AF48; font-size: 9pt; margin: 6pt 0; padding: 5pt 7pt; }
    .warn { background-color: #FBE6E6; border-left: 2pt solid #C9252B; color: #A01C21; font-size: 9pt; font-weight: bold; margin: 6pt 0; padding: 5pt 7pt; }
    .tag { color: #A01C21; font-weight: bold; }
    .ok { color: #1D6B38; font-weight: bold; }
    .muted { color: #6A6C80; }
    .mono { font-size: 8pt; }
</style>
</head>
<body>
<htmlpageheader name="enHeader">
    <table class="band" width="100%" cellpadding="0" cellspacing="0" style="margin-top:0;"><tr>
        <td style="width:13mm; height:25mm;"><table cellpadding="0" cellspacing="0"><tr><td class="tile">E</td></tr></table></td>
        <td><div class="doc-title">{{ __($title) }}</div><div class="doc-brand">E-Notary</div></td>
        <td class="meta" style="width:66mm;">@foreach($meta as $label => $value)<div>{{ __($label) }}: {{ $value }}</div>@endforeach</td>
    </tr></table>
</htmlpageheader>

<htmlpagefooter name="enFooter">
    <table class="foot" width="100%" cellpadding="0" cellspacing="0"><tr>
        {{-- Required on every page of every generated document: a PDF can be shared away from the site. --}}
        <td style="color:#6A6C80; font-size:8pt;"><span class="tag">{{ \App\Support\Platform::PDF_VALIDITY_NOTICE }}</span> @include('partials.disclosure')</td>
        <td class="page" style="width:26mm;">{{ __('Page') }} {PAGENO} / {nbpg}</td>
    </tr></table>
</htmlpagefooter>

@yield('body')
</body>
</html>
