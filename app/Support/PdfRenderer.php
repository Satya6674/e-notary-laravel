<?php

namespace App\Support;

use Illuminate\Support\Facades\File;
use Mpdf\Mpdf;

/**
 * Turns a Blade template into a PDF. This is the only class that knows the PDF
 * engine (mPDF), so replacing the engine touches this file and nothing else.
 *
 * Mukta and Mukta Mahee are used because the engine rejects the Noto fonts: their
 * layout tables use a lookup format it does not support. Both contain Latin, the
 * danda and the rupee sign, so mixed text never falls back to an empty box.
 */
class PdfRenderer
{
    public const FONT_DEVANAGARI = 'mukta';

    public const FONT_GURMUKHI = 'muktamahee';

    public function render(string $view, array $data = [], array $options = []): string
    {
        return $this->build($this->html($view, $data), $options);
    }

    public function html(string $view, array $data = []): string
    {
        return view($view, $data)->render();
    }

    /**
     * The engine reads font tables in ways PHP reports as warnings, and the framework
     * turns warnings into exceptions. They are expected here and only here.
     */
    private function build(string $html, array $options): string
    {
        set_error_handler(fn () => true, E_WARNING | E_NOTICE | E_DEPRECATED | E_USER_DEPRECATED);

        $limit = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '10000000');

        try {
            $pdf = new Mpdf($this->config($options));
            $pdf->SetTitle($options['title'] ?? 'E-Notary');
            $pdf->SetAuthor('E-Notary');
            $pdf->SetCreator('E-Notary');
            $pdf->WriteHTML($html);

            return $pdf->Output('', 'S');
        } finally {
            ini_set('pcre.backtrack_limit', (string) $limit);
            restore_error_handler();
        }
    }

    private function config(array $options): array
    {
        $cache = storage_path('framework/cache/mpdf');
        File::ensureDirectoryExists($cache);

        return [
            'mode'                  => 'utf-8',
            'format'                => ($options['landscape'] ?? false) ? 'A4-L' : 'A4',
            'tempDir'               => $cache,
            'fontDir'               => [resource_path('fonts/pdf')],
            'fontdata'              => [
                self::FONT_DEVANAGARI => ['R' => 'Mukta-Regular.ttf', 'B' => 'Mukta-Bold.ttf', 'useOTL' => 0xFF],
                self::FONT_GURMUKHI   => ['R' => 'MuktaMahee-Regular.ttf', 'B' => 'MuktaMahee-Bold.ttf', 'useOTL' => 0xFF],
            ],
            'default_font'          => app()->getLocale() === 'pa' ? self::FONT_GURMUKHI : self::FONT_DEVANAGARI,
            'default_font_size'     => 10,
            'autoScriptToLang'      => true,
            'autoLangToFont'        => true,
            'languageToFont'        => new PdfLanguageFonts(),
            'margin_left'           => 15,
            'margin_right'          => 15,
            'margin_top'            => 36,
            'margin_bottom'         => 20,
            'margin_header'         => 0,
            'margin_footer'         => 7,
            'shrink_tables_to_fit'  => 0,
            'packTableData'         => true,
            'showImageErrors'       => false,
        ];
    }
}
