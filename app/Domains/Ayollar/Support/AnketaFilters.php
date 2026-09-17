<?php

declare(strict_types=1);

namespace App\Domains\Ayollar\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * REYESTR FILTRLARI — BITTA JOYDA.
 *
 * Avval bu shartlar `AnketaController::index` ichida yashardi, eksport
 * esa faqat `district_id` ni bilardi. Natijasi ekranda ko'rinardi:
 * foydalanuvchi ehtiyoj bo'yicha 1 200 ta ayolni filtrlab, «Eksport»
 * bosganda 300 000 qatorli fayl olardi — ya'ni ekrandagi ro'yxat bilan
 * fayl BIR XIL EMAS edi.
 *
 * Endi ikkalasi ham shu klassni chaqiradi: ro'yxat nimani ko'rsatsa,
 * fayl ham shuni beradi.
 */
final class AnketaFilters
{
    /** To'g'ridan-to'g'ri ustunga tushadigan MATNLI filtrlar. */
    private const SIMPLE = ['category', 'status', 'age_group', 'balance_row'];

    /** UUID turidagi ustunlar — shakli tekshirilishi SHART. */
    private const AREA = ['district_id', 'mahalla_id'];

    /**
     * @param  Builder<\App\Domains\Ayollar\Models\Anketa>  $query
     */
    public static function apply(Builder $query, Request $request): void
    {
        foreach (self::SIMPLE as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->string($filter)->toString());
            }
        }

        // Hudud filtri ALOHIDA: ustun `uuid` turida va yaroqsiz
        // qiymat SQL darajasida 500 berardi. Sabab `AreaFilter` da.
        foreach (self::AREA as $filter) {
            AreaFilter::apply($query, $request, $filter);
        }

        /*
            QIZIL BELGI.

            Qizil qatorlar `balance_row` EMAS: ular alohida jadvalda
            (`anketa_red_flags`) va bitta ayolda bir nechtasi bo'ladi.
            Shuning uchun yuqoridagi oddiy `where` ular uchun ishlamaydi.
        */
        if ($request->filled('red_flag')) {
            $flag = $request->string('red_flag')->toString();

            $query->whereHas('redFlags', fn ($q) => $q->where('flag_code', $flag));
        }

        self::applyNeed($query, $request->filled('need') ? $request->string('need')->toString() : null);

        /*
            Qidiruv: F.I.Sh., ro'yxat raqami yoki QR token.

            JShShIR bo'yicha qidiruv ALOHIDA endpoint'da
            (`check-duplicate`): uni bu yerga qo'shish har qidiruvda
            maxfiy maydonni so'rov satriga, ya'ni server jurnaliga va
            brauzer tarixiga tushirishni anglatardi.
        */
        if ($request->filled('q')) {
            $term = $request->string('q')->trim()->toString();

            $query->where(function ($q) use ($term): void {
                $q->where('reg_number', 'ilike', "%{$term}%")
                    ->orWhere('qr_token', strtoupper($term))
                    ->orWhereHas('woman', fn ($w) => $w->where('full_name_norm', 'ilike', '%'.mb_strtolower($term).'%'));
            });
        }
    }

    /**
     * EHTIYOJ BO'YICHA FILTR — xaritadan ro'yxatga.
     *
     * Ehtiyojlar xaritasi «kasb-hunar istagi: 1 200» deb ko'rsatadi,
     * lekin u son bilan hech narsa qilib bo'lmasdi: kimlar ekanini
     * ko'rish uchun yo'l yo'q edi. Endi bandni bosish shu filtr bilan
     * reyestrni ochadi.
     *
     * Shakli: `q15` yoki `q17:texnikum` (guruhli bandda joy bilan).
     *
     * @param  Builder<\App\Domains\Ayollar\Models\Anketa>  $query
     */
    public static function applyNeed(Builder $query, ?string $need): void
    {
        if ($need === null || $need === '') {
            return;
        }

        $parsed = self::parseNeed($need);

        /*
            YAROQSIZ KALIT — BO'SH RO'YXAT, TO'LIQ RO'YXAT EMAS.

            Avval bu yer yaroqsiz kalitni shunchaki e'tiborsiz
            qoldirardi va foydalanuvchi BUTUN reyestrni ko'rardi —
            ustida esa «Ehtiyoj bo'yicha…» degan tasma turardi. Ya'ni
            5 330 ta ayol «bu ehtiyojga ega» bo'lib ko'rinardi.

            Filtr tushunilmasa, javob «hech narsa» bo'lishi kerak:
            bo'sh ro'yxat savol tug'diradi, noto'g'ri ro'yxat esa
            yo'q.
        */
        if ($parsed === null) {
            $query->whereRaw('false');

            return;
        }

        [$question, $place] = $parsed;

        /*
            XARITADAGI SON BILAN RO'YXAT UZUNLIGI BIR XIL BO'LSIN.

            Ehtiyojlar xaritasi faqat HISOBGA KIRADIGAN anketalarni
            sanaydi (`countable`: tugallangan holat + yashil/sariq
            toifa). Reyestr esa odatda qoralamalarni ham ko'rsatadi.

            Ikkalasi turlicha bo'lsa, «1 200» ni bosgan foydalanuvchi
            1 240 qatorli ro'yxatni ko'rardi va qaysi raqam to'g'ri
            ekanini bila olmasdi. Shuning uchun ehtiyoj filtri
            yoqilganda ro'yxat ham xarita bilan bir xil to'plamga
            qisqaradi.
        */
        $query->countable();

        $query->whereRaw(self::needYesSql($question));

        if (Rules::questionGroups($question) !== [] && $place !== null && $place !== '') {
            $query->whereRaw("answers -> 'q{$question}' ->> 'joy' = ?", [$place]);
        }
    }

    /**
     * Ehtiyoj bayrog'ining JSONB yo'li.
     *
     * Guruhli bandda (`q17`) ehtiyoj ICHKI kalitda — `{istak, joy}`.
     * Oddiy bandda bandning o'zida. Ikkalasi bir xil ko'rinsa ham,
     * `->>` guruhli bandda BUTUN JSON satrni qaytaradi va shart hech
     * qachon rost bo'lmasdi.
     *
     * Band raqami `int` — chaqiruvchi uni `parseNeed()` dan oladi.
     */
    public static function needFlagSql(int $question): string
    {
        return Rules::questionGroups($question) !== []
            ? "answers -> 'q{$question}' ->> 'istak'"
            : "answers ->> 'q{$question}'";
    }

    /**
     * «Ehtiyoj BOR» sharti.
     *
     * «Ha» ning bir necha yozilishi bor: klient `true` yuboradi, import
     * esa `1` yoki `ha` bo'lishi mumkin. Uchalasi bir xil ma'noda.
     */
    public static function needYesSql(int $question): string
    {
        return 'lower('.self::needFlagSql($question).") in ('true','1','ha','yes')";
    }

    /**
     * Ehtiyoj kalitini BAND RAQAMI va JOY ga ajratadi.
     *
     * Kalit so'rovdan keladi va band raqami to'g'ridan-to'g'ri SQL
     * matniga tushadi (JSONB yo'li bog'lanuvchi parametr bo'la
     * olmaydi). Shuning uchun shakl QAT'IY tekshiriladi va raqam
     * `int` ga aylantiriladi — inyeksiya uchun joy qolmaydi.
     *
     * `need_questions` ro'yxatida yo'q band ham rad etiladi — `q11`
     * ehtiyoj bandi emas, u toifalash bandi. Rad etilgan kalit bilan
     * nima bo'lishini `applyNeed()` hal qiladi: bo'sh ro'yxat.
     *
     * @return array{0: int, 1: ?string}|null
     */
    public static function parseNeed(?string $need): ?array
    {
        if ($need === null || $need === '') {
            return null;
        }

        [$key, $place] = array_pad(explode(':', $need, 2), 2, null);

        if (preg_match('/^q\d{1,2}$/', (string) $key) !== 1) {
            return null;
        }

        $question = (int) ltrim((string) $key, 'q');

        if (! in_array($question, Rules::needQuestions(), true)) {
            return null;
        }

        return [$question, $place];
    }

    /**
     * Filtrning o'qiladigan nomi — eksport sarlavhasi va jurnal uchun.
     */
    public static function needLabel(?string $need): ?string
    {
        $parsed = self::parseNeed($need);

        if ($parsed === null) {
            return null;
        }

        [$question, $place] = $parsed;
        $title = Rules::questionTitle($question);

        return $place === null || $place === '' ? $title : $title.' — '.Rules::label($place);
    }
}
