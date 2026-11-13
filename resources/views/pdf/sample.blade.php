@extends('pdf.layout')

@section('body')
<div class="warn" style="text-align:center;">SAMPLE ONLY. NOT LEGALLY VALID.</div>

<h2>Sample content</h2>
<table class="fields">
    <tr><td class="k">Document type</td><td>{{ $report->label($type) }}</td></tr>
    <tr><td class="k">Purpose</td><td>{{ $purpose }}</td></tr>
</table>
<p style="margin-top:8pt;">This file contains invented sample content for trying the E-Notary workflow. It is not a real legal document.</p>
@endsection
