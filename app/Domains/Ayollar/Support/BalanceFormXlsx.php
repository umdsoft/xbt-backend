<?php

declare(strict_types=1);

namespace App\Domains\Ayollar\Support;

use ZipArchive;

/**
 * RASMIY BALANS SHAKLI — XLSX KO'RINISHIDA.
 *
 * NEGA ALOHIDA YOZUVCHI. `App\Support\SimpleXlsx` — bezaksiz tekis
 * jadval yozadi va u SEKIZ MODUL tomonidan ishlatiladi; unga
 * birlashtirilgan kataklar, ranglar va burilgan matn qo'shish
 * hammasiga tegib ketardi. Rasmiy shakl esa aynan shu bezaklarsiz
 * tanib bo'lmaydigan holga keladi: rangli bloklar, chap chetdagi tik
 * sarlavhalar, «шундан» qatorlarining kursivi.
 *
 * NEGA KUTUBXONA EMAS. PhpSpreadsheet umumiy backendga yangi
 * bog'liqlik qo'shish demakdir — sakkizta jonli modul yashaydigan
 * joyda. Bu yerda kerak bo'lgani OOXML ning kichik bir qismi:
 * uslublar jadvali, birlashtirish va ustun kengliklari.
 *
 * XLSX = ZIP(bir nechta XML). Kirill matn `inline` satrlarda ketadi,
 * shuning uchun `sharedStrings.xml` kerak emas.
 */
final class BalanceFormXlsx
{
    /** Uslub indekslari — `cellXfs` dagi tartib bilan bir xil. */
    public const S_DEFAULT = 0;

    public const S_TITLE = 1;

    public const S_HEAD_GREEN = 2;

    public const S_HEAD_YELLOW = 3;

    public const S_HEAD_RED = 4;

    public const S_PERCENT_ROW = 5;

    public const S_ITEM = 6;

    public const S_ITEM_SUB = 7;

    public const S_NUMBER = 8;

    public const S_SIDE = 9;

    public const S_TOTAL = 10;

    public const S_TOTAL_NUM = 11;

    /**
     * @param  array<int, array{0: string, 1: string|int|null, 2: int, 3?: string}>  $rows
     *                                                                                     Har qator: [matn, qiymat, uslub, chapdagi tik sarlavha?]
     * @param  array<int, string>  $merges  Masalan `A5:A20`
     */
    public static function build(array $rows, array $merges, string $sheetName = 'Balans'): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive;
        $zip->open($tmp, ZipArchive::OVERWRITE);

        $zip->addFromString('[Content_Types].xml', self::contentTypes());
        $zip->addFromString('_rels/.rels', self::rootRels());
        $zip->addFromString('xl/workbook.xml', self::workbook($sheetName));
        $zip->addFromString('xl/_rels/workbook.xml.rels', self::workbookRels());
        $zip->addFromString('xl/styles.xml', self::styles());
        $zip->addFromString('xl/worksheets/sheet1.xml', self::sheet($rows, $merges));

        $zip->close();

        $bin = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $bin;
    }

    private static function esc(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /**
     * @param  array<int, array{0: string, 1: string|int|null, 2: int}>  $rows
     * @param  array<int, string>  $merges
     */
    private static function sheet(array $rows, array $merges): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            // O'lcham OCHIQ ko'rsatiladi: usiz ba'zi o'qigichlar bo'sh
            // chap ustunni tashlab, qolganini surib yuboradi.
            .'<dimension ref="A1:C'.max(count($rows), 1).'"/>'
            // A — chap chetdagi tik sarlavha, B — ko'rsatkich nomi, C — qiymat.
            .'<cols>'
            .'<col min="1" max="1" width="4.5" customWidth="1"/>'
            .'<col min="2" max="2" width="62" customWidth="1"/>'
            .'<col min="3" max="3" width="14" customWidth="1"/>'
            .'</cols>'
            .'<sheetData>';

        foreach ($rows as $i => $row) {
            [$text, $value, $style] = $row;
            $side = $row[3] ?? '';

            $r = $i + 1;

            // Qog'ozdagi kabi: uzun nomlar ikki qatorga sig'ishi uchun
            // balandlik erkin qoldiriladi, o'ralish uslubda.
            $xml .= '<row r="'.$r.'">';

            /*
                A USTUNI — CHAP CHETDAGI TIK SARLAVHA.

                Matn faqat birlashmaning BIRINCHI katagiga yoziladi:
                Excel birlashtirilgan sohada aynan shu katakni
                ko'rsatadi, qolganlari bo'sh qolishi SHART. Ularga ham
                yozilsa, fayl buzuq deb hisoblanadi.
            */
            $xml .= $side !== ''
                ? '<c r="A'.$r.'" s="'.self::S_SIDE.'" t="inlineStr"><is><t>'.self::esc($side).'</t></is></c>'
                : '<c r="A'.$r.'" s="'.self::S_SIDE.'"/>';

            $xml .= '<c r="B'.$r.'" s="'.$style.'" t="inlineStr">'
                .'<is><t xml:space="preserve">'.self::esc($text).'</t></is></c>';

            $xml .= self::valueCell('C'.$r, $value, $style);

            $xml .= '</row>';
        }

        $xml .= '</sheetData>';

        if ($merges !== []) {
            $xml .= '<mergeCells count="'.count($merges).'">';
            foreach ($merges as $ref) {
                $xml .= '<mergeCell ref="'.self::esc($ref).'"/>';
            }
            $xml .= '</mergeCells>';
        }

        return $xml.'</worksheet>';
    }

    /**
     * Qiymat katagi.
     *
     * SON SON BO'LIB YOZILADI, matn emas: aks holda Excelda ustunni
     * yig'ib bo'lmasdi va foydalanuvchi raqamlarni qo'lda qayta
     * terishga majbur bo'lardi. Foiz esa matn — u «62,0%» ko'rinishida
     * qog'ozdagidek turadi.
     */
    private static function valueCell(string $ref, string|int|null $value, int $style): string
    {
        $numStyle = in_array($style, [self::S_TOTAL, self::S_TOTAL_NUM], true)
            ? self::S_TOTAL_NUM
            : self::S_NUMBER;

        if ($value === null || $value === '') {
            return '<c r="'.$ref.'" s="'.$numStyle.'"/>';
        }

        if (is_int($value)) {
            return '<c r="'.$ref.'" s="'.$numStyle.'"><v>'.$value.'</v></c>';
        }

        return '<c r="'.$ref.'" s="'.$numStyle.'" t="inlineStr">'
            .'<is><t xml:space="preserve">'.self::esc($value).'</t></is></c>';
    }

    /**
     * Uslublar jadvali.
     *
     * Ranglar rasmiy shakldan olingan: yashil blok ochiq zaytun,
     * sariq blok qumrang, qizil blok pushti. Ular qog'ozda bosilganda
     * ham ajralib turadi va rangli chop etish shart emas.
     */
    private static function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'

            .'<fonts count="5">'
            .'<font><sz val="10"/><name val="Calibri"/></font>'
            .'<font><b/><sz val="11"/><name val="Calibri"/></font>'
            .'<font><i/><sz val="9"/><name val="Calibri"/></font>'
            .'<font><b/><sz val="10"/><name val="Calibri"/></font>'
            .'<font><i/><sz val="10"/><name val="Calibri"/></font>'
            .'</fonts>'

            .'<fills count="7">'
            .'<fill><patternFill patternType="none"/></fill>'
            .'<fill><patternFill patternType="gray125"/></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFE2EFDA"/><bgColor indexed="64"/></patternFill></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFFFF2CC"/><bgColor indexed="64"/></patternFill></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFF8CBCB"/><bgColor indexed="64"/></patternFill></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFF2F2F2"/><bgColor indexed="64"/></patternFill></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFDDEBF7"/><bgColor indexed="64"/></patternFill></fill>'
            .'</fills>'

            .'<borders count="2">'
            .'<border><left/><right/><top/><bottom/><diagonal/></border>'
            .'<border>'
            .'<left style="thin"><color rgb="FF9EA9A6"/></left>'
            .'<right style="thin"><color rgb="FF9EA9A6"/></right>'
            .'<top style="thin"><color rgb="FF9EA9A6"/></top>'
            .'<bottom style="thin"><color rgb="FF9EA9A6"/></bottom>'
            .'<diagonal/></border>'
            .'</borders>'

            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'

            .'<cellXfs count="12">'
            // 0 default
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            // 1 sarlavha
            .'<xf numFmtId="0" fontId="1" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1" applyAlignment="1">'
            .'<alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            // 2 yashil blok sarlavhasi
            .'<xf numFmtId="0" fontId="3" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1">'
            .'<alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            // 3 sariq blok sarlavhasi
            .'<xf numFmtId="0" fontId="3" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1">'
            .'<alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            // 4 qizil blok sarlavhasi
            .'<xf numFmtId="0" fontId="3" fillId="4" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1">'
            .'<alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            // 5 «Фоизда» qatori
            .'<xf numFmtId="0" fontId="4" fillId="5" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1">'
            .'<alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            // 6 oddiy qator
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1">'
            .'<alignment vertical="center" wrapText="1" indent="1"/></xf>'
            // 7 «шундан» qatori — kursiv, ichkariga surilgan
            .'<xf numFmtId="0" fontId="4" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1" applyAlignment="1">'
            .'<alignment vertical="center" wrapText="1" indent="3"/></xf>'
            // 8 son
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1">'
            .'<alignment horizontal="center" vertical="center"/></xf>'
            // 9 chap chetdagi TIK sarlavha
            .'<xf numFmtId="0" fontId="3" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1" applyAlignment="1">'
            .'<alignment horizontal="center" vertical="center" textRotation="90" wrapText="1"/></xf>'
            // 10 JAMI qatori
            .'<xf numFmtId="0" fontId="1" fillId="6" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1">'
            .'<alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            // 11 JAMI soni
            .'<xf numFmtId="0" fontId="1" fillId="6" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1">'
            .'<alignment horizontal="center" vertical="center"/></xf>'
            .'</cellXfs>'

            .'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            .'</styleSheet>';
    }

    private static function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'</Types>';
    }

    private static function rootRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>';
    }

    private static function workbook(string $sheetName): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="'.self::esc(mb_substr($sheetName, 0, 31)).'" sheetId="1" r:id="rId1"/></sheets>'
            .'</workbook>';
    }

    private static function workbookRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .'</Relationships>';
    }
}
