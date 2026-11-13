<?php

namespace App\Support;

use Mpdf\Language\LanguageToFontInterface;

/** Tells the PDF engine which embedded font shapes each script found in the text. */
class PdfLanguageFonts implements LanguageToFontInterface
{
    public function getLanguageOptions($llcc, $adobeCJKfonts)
    {
        return match (strtok(strtolower((string) $llcc), '-_')) {
            'hi', 'mr', 'ne', 'sa' => [false, PdfRenderer::FONT_DEVANAGARI],
            'pa' => [false, PdfRenderer::FONT_GURMUKHI],
            default => [false, ''],
        };
    }
}
