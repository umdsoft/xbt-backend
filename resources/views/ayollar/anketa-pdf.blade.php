{{--
    ANKETA PDF — rasmiy hujjat (promt §7).

    QR yuqori O'NG burchakda, 25×25 mm. Ostida raqam MATN bilan — QR
    ishlamasa (bosma sifati, ho'l qog'oz) uni qo'lda kiritish uchun.

    SHRIFT: DejaVu Sans (dompdf bilan birga keladi). Manrope va IBM Plex
    ishlatilmadi — ular `woff2` formatida va dompdf TTF talab qiladi.
    Rasmiy hujjatda kirill/lotin belgilarining TO'G'RI chiqishi shrift
    tanlovidan muhimroq: DejaVu ikkalasini ham to'liq qoplaydi.

    RANGLAR — Figma `01 · Fondation` dan.
--}}
<!DOCTYPE html>
<html lang="uz">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 14mm 14mm 16mm 14mm; }

        body {
            font-family: "DejaVu Sans", sans-serif;
            font-size: 9pt;
            color: #12262F;
            line-height: 1.45;
        }

        .head { width: 100%; }
        .head td { vertical-align: top; }

        .title { font-size: 17pt; font-weight: bold; color: #0E2430; letter-spacing: -0.4pt; }
        .subtitle { font-size: 8pt; color: #5F7078; margin-top: 2mm; }
        .meta { font-size: 7.5pt; color: #12808C; margin-top: 1mm; }

        /*
           QR bloki — o'ng ustun, 25x25 mm (promt §7 minimumi).

           Modullar TO'RTBURCHAK sifatida chiziladi: har qatordagi
           ketma-ket qora modullar bittaga birlashtirilgan. 1369 katak
           o'rniga ~350 element — dompdf xotirasi uchun sezilarli farq.
        */
        .qr-cell { width: 27mm; text-align: right; }
        .qr-box { position: relative; width: 25mm; height: 25mm; margin-left: auto; }
        .qr-box b { position: absolute; background: #0E2430; display: block; }
        .qr-number { font-size: 6pt; color: #5F7078; margin-top: 1.5mm; text-align: right; }

        .rule { border-top: 0.4mm solid #DCE2DF; margin: 5mm 0 4mm; }

        .eyebrow {
            font-size: 7pt; font-weight: bold; color: #5F7078;
            letter-spacing: 0.8pt; text-transform: uppercase; margin-bottom: 2mm;
        }

        table.facts { width: 100%; border-collapse: collapse; }
        table.facts td { padding: 1.6mm 0; border-bottom: 0.2mm solid #EDF0EE; }
        table.facts td.k { width: 42mm; color: #5F7078; font-size: 8pt; }

        .badge {
            display: inline-block; padding: 1mm 2.5mm; border-radius: 2mm;
            font-size: 8pt; font-weight: bold;
        }
        .green  { background: #E3F0E9; color: #2F7D5B; }
        .yellow { background: #F8EEDA; color: #C4881A; }
        .neutral{ background: #EDF0EE; color: #5F7078; }
        .red    { background: #F6E4E2; color: #B23A32; }

        .section { margin-top: 6mm; }
        table.answers { width: 100%; border-collapse: collapse; }
        table.answers td { padding: 1.3mm 0; border-bottom: 0.2mm solid #EDF0EE; vertical-align: top; }
        table.answers td.q { width: 10mm; color: #8B9AA0; font-size: 7.5pt; }
        table.answers td.t { width: 62mm; color: #5F7078; font-size: 8pt; }

        .note {
            margin-top: 6mm; padding: 3mm; background: #F1F3F1;
            border-radius: 2mm; font-size: 7.5pt; color: #5F7078;
        }

        .sign { width: 100%; margin-top: 8mm; border-collapse: collapse; }
        .sign td { width: 33%; padding-top: 12mm; font-size: 7.5pt; color: #5F7078; }
        .sign .line { border-top: 0.3mm solid #8B9AA0; padding-top: 1.5mm; }

        .footer { margin-top: 8mm; font-size: 6.5pt; color: #8B9AA0; }
    </style>
</head>
<body>

<table class="head">
    <tr>
        <td>
            <div class="title">Xotin-qizlar anketasi</div>
            <div class="subtitle">Ayollar Balansi · Xorazm viloyati hokimligi</div>
            <div class="meta">{{ $district ?? '—' }} · {{ $mahalla ?? '—' }}</div>
        </td>
        <td class="qr-cell">
            {{-- To'rtburchaklar: dompdf'da HAR QANDAY muhitda bir xil
                 chiqadi (SVG/PNG'dan farqli — ular kutubxona yoki
                 kengaytma talab qiladi). --}}
            @php($m = 25 / $qr['size'])
            <div class="qr-box">
                @foreach ($qr['runs'] as $r)<b style="left:{{ round($r['x'] * $m, 3) }}mm;top:{{ round($r['y'] * $m, 3) }}mm;width:{{ round($r['w'] * $m, 3) }}mm;height:{{ round($m, 3) }}mm"></b>@endforeach
            </div>
            <div class="qr-number">{{ $anketa->reg_number }}</div>
        </td>
    </tr>
</table>

<div class="rule"></div>

<div class="eyebrow">Hujjat maʼlumotlari</div>

<table class="facts">
    <tr><td class="k">Roʻyxat raqami</td><td><b>{{ $anketa->reg_number }}</b></td></tr>
    <tr><td class="k">Toʻldirilgan sana</td><td>{{ optional($anketa->filled_at)->format('d.m.Y') ?? '—' }}</td></tr>
    <tr><td class="k">Yosh guruhi</td><td>{{ $ageGroup }}</td></tr>
    <tr>
        <td class="k">Toifa</td>
        <td>
            <span class="badge {{ $anketa->category === 'green' ? 'green' : ($anketa->category === 'yellow' ? 'yellow' : 'neutral') }}">
                {{ $categoryLabel }}
            </span>
            @if ($balanceRow)
                <span style="color:#5F7078"> · {{ $balanceRow }}</span>
            @endif
        </td>
    </tr>
    <tr><td class="k">Anketa shakli</td><td>v{{ $anketa->form_version }}</td></tr>
    <tr><td class="k">Toʻldirgan xodim</td><td>{{ $filledByPosition ?? 'Koʻrsatilmagan' }}</td></tr>
</table>

@if (count($redFlags))
    <div class="section">
        <div class="eyebrow">Alohida ishlash talab etiladi</div>
        @foreach ($redFlags as $flag)
            <span class="badge red" style="margin-right:1.5mm">{{ $flag }}</span>
        @endforeach
    </div>
@endif

@foreach ($sections as $section)
    @if (count($section['items']))
        <div class="section">
            <div class="eyebrow">{{ $section['number'] }}-boʻlim · {{ $section['title'] }}</div>
            <table class="answers">
                @foreach ($section['items'] as $item)
                    <tr>
                        <td class="q">{{ $item['number'] }}</td>
                        <td class="t">{{ $item['title'] }}</td>
                        <td>{{ $item['value'] }}</td>
                    </tr>
                @endforeach
            </table>
        </div>
    @endif
@endforeach

@if ($hasSensitive)
    <div class="section">
        <div class="eyebrow">Ijtimoiy nazorat (V boʻlim)</div>
        <div style="font-size:8pt;color:#5F7078">
            Bu boʻlim javoblari hujjatga chiqarilmaydi. Ular tizimda saqlanadi va
            faqat vakolatli xodimga, jurnalga yozilgan holda ochiladi.
        </div>
    </div>
@endif

<div class="note">
    Hujjat haqiqiyligini QR kod orqali tekshirish mumkin. Tekshiruv sahifasida
    shaxsiy maʼlumot koʻrsatilmaydi — faqat hujjat faktlari va imzolar zanjiri.
    QR ishlamasa, roʻyxat raqamini qoʻlda kiriting.
</div>

<table class="sign">
    <tr>
        <td><div class="line">Anketani toʻldirgan</div></td>
        <td><div class="line" style="margin-left:4mm">MFY raisi</div></td>
        <td><div class="line" style="margin-left:4mm">Sana</div></td>
    </tr>
</table>

<div class="footer">
    Yuklab oldi: {{ $downloadedBy }} · {{ $downloadedAt }} · Ayollar Balansi · Digital Xorazm
</div>

</body>
</html>
