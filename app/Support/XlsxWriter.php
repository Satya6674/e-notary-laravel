<?php

namespace App\Support;

use RuntimeException;
use ZipArchive;

/**
 * Writes a plain .xlsx workbook (Office Open XML) with one or more sheets of text and numbers.
 * No styles, formulas or images: a value is a number cell when it is an int or float and an
 * inline text cell otherwise, so text from the database can never become a formula.
 */
class XlsxWriter
{
    /** @var array<string, list<list<scalar|null>>> */
    private array $sheets = [];

    /** @param  list<list<scalar|null>>  $rows */
    public function sheet(string $name, array $rows): self
    {
        // Excel limits names to 31 characters and refuses []:*?/\
        $name = mb_substr(trim(preg_replace('/[\[\]:*?\/\\\\]/u', ' ', $name)) ?: 'Sheet', 0, 31);
        $base = $name;

        for ($i = 2; isset($this->sheets[$name]); $i++) {
            $name = mb_substr($base, 0, 28).' '.$i;
        }

        $this->sheets[$name] = $rows;

        return $this;
    }

    public function toString(): string
    {
        if (! $this->sheets) {
            $this->sheet('Sheet', []);
        }

        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive;

        if ($path === false || $zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('The spreadsheet could not be created.');
        }

        try {
            $names = array_keys($this->sheets);

            $zip->addFromString('[Content_Types].xml', $this->contentTypes(count($names)));
            $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
                .'</Relationships>');
            $zip->addFromString('xl/workbook.xml', $this->workbook($names));
            $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRels(count($names)));

            foreach (array_values($this->sheets) as $i => $rows) {
                $zip->addFromString('xl/worksheets/sheet'.($i + 1).'.xml', $this->worksheet($rows));
            }

            $zip->close();

            return (string) file_get_contents($path);
        } finally {
            @unlink($path);
        }
    }

    private function contentTypes(int $count): string
    {
        $sheets = '';

        for ($i = 1; $i <= $count; $i++) {
            $sheets .= '<Override PartName="/xl/worksheets/sheet'.$i.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .$sheets.'</Types>';
    }

    private function workbook(array $names): string
    {
        $sheets = '';

        foreach ($names as $i => $name) {
            $sheets .= '<sheet name="'.$this->escape($name).'" sheetId="'.($i + 1).'" r:id="rId'.($i + 1).'"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets>'.$sheets.'</sheets></workbook>';
    }

    private function workbookRels(int $count): string
    {
        $rels = '';

        for ($i = 1; $i <= $count; $i++) {
            $rels .= '<Relationship Id="rId'.$i.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$i.'.xml"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$rels.'</Relationships>';
    }

    private function worksheet(array $rows): string
    {
        $xml = '';

        foreach (array_values($rows) as $r => $row) {
            $cells = '';

            foreach (array_values($row) as $c => $value) {
                $ref = $this->column($c).($r + 1);

                if ($value === null || $value === '') {
                    continue;
                }

                $cells .= is_int($value) || is_float($value)
                    ? '<c r="'.$ref.'"><v>'.$value.'</v></c>'
                    : '<c r="'.$ref.'" t="inlineStr"><is><t xml:space="preserve">'.$this->escape((string) $value).'</t></is></c>';
            }

            $xml .= '<row r="'.($r + 1).'">'.$cells.'</row>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$xml.'</sheetData></worksheet>';
    }

    private function column(int $index): string
    {
        $name = '';

        for ($index++; $index > 0; $index = intdiv($index - 1, 26)) {
            $name = chr(65 + ($index - 1) % 26).$name;
        }

        return $name;
    }

    private function escape(string $value): string
    {
        // XML 1.0 forbids most control characters, even escaped.
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value) ?? '';

        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
