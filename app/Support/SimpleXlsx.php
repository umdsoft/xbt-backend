<?php

declare(strict_types=1);

namespace App\Support;

use ZipArchive;

/**
 * Minimal XLSX yozuvchi — DEPENDENCY-SIZ (faqat ZipArchive).
 *
 * Bitta varaq, inline satrlar (sharedStrings kerak emas). Kirill/UTF-8 to'g'ri
 * saqlanadi. PhpSpreadsheet kabi og'ir kutubxona o'rniga — oddiy jadval eksporti
 * uchun yetarli va yengil. XLSX = ZIP(bir nechta XML), OOXML minimal to'plami.
 */
final class SimpleXlsx
{
    /**
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, string|int|float|null>>  $rows
     * @return string  xlsx fayl binary'si
     */
    public static function build(array $headers, array $rows, string $sheetName = 'Sheet1'): string
    {
        $sheet = self::sheetXml(array_merge([$headers], $rows));

        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', self::contentTypes());
        $zip->addFromString('_rels/.rels', self::rootRels());
        $zip->addFromString('xl/workbook.xml', self::workbook($sheetName));
        $zip->addFromString('xl/_rels/workbook.xml.rels', self::workbookRels());
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
        $zip->close();

        $bin = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $bin;
    }

    private static function esc(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /** 0->A, 25->Z, 26->AA ... */
    private static function colLetter(int $i): string
    {
        $s = '';
        $i++;
        while ($i > 0) {
            $m = ($i - 1) % 26;
            $s = chr(65 + $m).$s;
            $i = intdiv($i - 1, 26);
        }

        return $s;
    }

    /** @param  array<int, array<int, mixed>>  $rows */
    private static function sheetXml(array $rows): string
    {
        $body = '';
        foreach ($rows as $r => $cells) {
            $rn = $r + 1;
            $body .= '<row r="'.$rn.'">';
            foreach (array_values($cells) as $c => $val) {
                $ref = self::colLetter($c).$rn;
                $text = self::esc((string) ($val ?? ''));
                $body .= '<c r="'.$ref.'" t="inlineStr"><is><t xml:space="preserve">'.$text.'</t></is></c>';
            }
            $body .= '</row>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<sheetData>'.$body.'</sheetData></worksheet>';
    }

    private static function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'</Types>';
    }

    private static function rootRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>';
    }

    private static function workbook(string $name): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="'.self::esc(mb_substr($name, 0, 31)).'" sheetId="1" r:id="rId1"/></sheets></workbook>';
    }

    private static function workbookRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'</Relationships>';
    }
}
