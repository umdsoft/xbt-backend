<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Yoshlar\Models\Organization;
use App\Domains\Yoshlar\Models\Protocol;
use App\Domains\Yoshlar\Models\Task;
use App\Domains\Yoshlar\Models\Youth;
use Illuminate\Database\Seeder;

/**
 * Rasmiy hujjat namunalari — FAQAT lokal sinov uchun.
 *
 * Ikki hujjat kiritiladi:
 *   1) YOʻL XARITASI — «Murojaatchining F.I.Sh» ustuni bor, mexanizm
 *      raqamlangan qadamlardan iborat;
 *   2) BAYONNOMA — oʻlchanadigan maqsadli bandlar («kamida 10 ta
 *      ijtimoiy obyektda»).
 *
 * Ikki turni ham kiritish shart, chunki ekran ustunlar toʻplamini hujjat
 * TURIGA qarab oʻzgartiradi — bittasi bilan bu xatti-harakat sinalmaydi.
 *
 * `firstOrCreate` — qayta chaqirilganda yozuv ikkilanmaydi.
 */
class YoshlarDocumentDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->error('Bu seeder production muhitida ishlamaydi.');

            return;
        }

        $org = Organization::query()->where('type', 'tuman_sektor')->first()
            ?? Organization::query()->first();

        if ($org === null) {
            $this->command?->error('Ijrochi tashkilot yoʻq — avval tashkilotlarni yarating.');

            return;
        }

        $roadmap = $this->roadmap($org);
        $minutes = $this->minutes($org);

        $this->command?->info(
            "Hujjat namunalari tayyor: yoʻl xaritasi {$roadmap->id}, bayonnoma {$minutes->id}"
        );
    }

    private function roadmap(Organization $org): Protocol
    {
        $protocol = Protocol::query()->firstOrCreate(
            ['number' => 'YX-2026-01', 'protocol_date' => '2026-08-15'],
            [
                'type' => Protocol::TYPE_ROADMAP,
                'event_title' => 'Hokim va yoshlar uchrashuvi',
                'topic' => 'Startap tashabbuskori yoshlar takliflarini qoʻllab-quvvatlash va muammolarni bartaraf qilish yoʻl xaritasi',
                'issued_by' => 'Xorazm viloyati hokimi',
                'year' => 2026,
            ],
        );

        // Murojaatchi FAQAT ismi mos kelganda bogʻlanadi.
        //
        // Avvalgi variant reyestrdan BIRINCHI topilgan yoshni bogʻlab
        // qoʻyardi va ekranda taklif muallifi sifatida butunlay boshqa
        // odam koʻrinardi — bu sunʼiy maʼlumot emas, YOLGʻON maʼlumot.
        // Haqiqiy ish oqimi ham shunday: mos yozuv topilmasa, ism matn
        // boʻlib qoladi va keyinroq qoʻlda bogʻlanadi.
        $youth = Youth::query()
            ->where('last_name', 'Jumanazarov')
            ->where('first_name', 'Asilbek')
            ->first();

        $this->item($protocol, '1', [
            'title' => '«Pay» toʻlov loyihasi doirasida amaldagi toʻlov tizimlari har bir tranzaksiyadan 2 foiz komissiya ushlab qolmoqda. Nisbatan past komissiya stavkasini taklif etuvchi toʻlov tizimlari va banklar boʻyicha rasmiy maʼlumotnoma taqdim etish hamda ular bilan muzokaralar olib borishda koʻmaklashish.',
            'steps' => [
                ['no' => 1, 'text' => 'Toʻlov tashkilotlari va banklarning ekvayring tariflari boʻyicha rasmiy maʼlumotnoma tayyorlash.', 'deadline' => null],
                ['no' => 2, 'text' => 'Loyiha uchun qulay shart taklif eta oladigan toʻlov tashkilotlari bilan muzokaralar tashkil etish.', 'deadline' => null],
                ['no' => 3, 'text' => 'Hamkorlik shartnomasini rasmiylashtirishda amaliy koʻmak koʻrsatish.', 'deadline' => null],
            ],
            'deadline_text' => '1 oy muddat',
            'deadline' => '2026-09-15',
            'responsible_text' => 'Markaziy bankning viloyat bosh boshqarmasi (K.Kurbanov)',
            'applicant_youth_id' => $youth?->id,
            'applicant_name' => 'Jumanazarov Asilbek Sherali oʻgʻli',
            'assigned_org_id' => $org->id,
            'district_id' => $org->district_id,
            'priority' => 'orta',
            'sort_order' => 1,
        ], [
            'status' => 'ijroda',
            'progress' => 40,
        ]);

        $this->item($protocol, '2', [
            'title' => '«ZakoWatt» loyihasini kengroq auditoriyaga — umumtaʼlim maktablari va oliy taʼlim muassasalari talabalariga yetkazish. Onlayn turnirlar bilan bir qatorda oflayn turnirlar tashkil etishda amaliy koʻmak berish hamda gʻoliblarni ragʻbatlantirish uchun kitob va vaucherlar ajratish.',
            'steps' => [
                ['no' => 1, 'text' => 'Loyihani taʼlim muassasalariga tanishtirish va tanishtiruv jadvalini shakllantirish.', 'deadline' => null],
                ['no' => 2, 'text' => 'Maktab va OTMlarda oflayn turnirlar oʻtkazishni tashkil etish.', 'deadline' => null],
                ['no' => 3, 'text' => 'Gʻoliblarni ragʻbatlantirish uchun kitob va vaucherlar ajratish.', 'deadline' => null],
            ],
            'deadline_text' => '1 oy muddat',
            'deadline' => '2026-09-15',
            'responsible_text' => "Yoshlar ishlari viloyat boshqarmasi (B.Olimov),\nviloyat maktabgacha va maktab taʼlimi boshqarmasi (X.Bektemirov)",
            'applicant_name' => 'Kenjayev Muhammad',
            'assigned_org_id' => $org->id,
            'district_id' => $org->district_id,
            'priority' => 'yuqori',
            'sort_order' => 2,
        ], [
            'status' => 'belgilandi',
        ]);

        return $protocol;
    }

    private function minutes(Organization $org): Protocol
    {
        $protocol = Protocol::query()->firstOrCreate(
            ['number' => 'GM63798627', 'protocol_date' => '2026-08-20'],
            [
                'type' => Protocol::TYPE_BAYONNOMA,
                'event_title' => 'Hokim va yoshlar uchrashuvi bayonnomasi',
                'topic' => 'Startap loyihalarini pilot tarzda joriy etish boʻyicha topshiriqlar',
                'issued_by' => 'Xorazm viloyati hokimi',
                'year' => 2026,
            ],
        );

        $this->item($protocol, '6', [
            'title' => 'Viloyat favqulodda vaziyatlar boshqarmasi «AI MCHS Detektor» intellektual xavfsizlik tizimini texnik jihatdan ekspertizadan oʻtkazsin va ijobiy xulosa berilgan taqdirda tizimni pilot tarzda joriy etish boʻyicha bosqichma-bosqich reja ishlab chiqsin.',
            'mechanism' => 'Pilot maydonchalar: maktab, bolalar bogʻchasi, shifoxona, talabalar turar joyi.',
            'deadline_text' => '2026-yil 1-noyabr',
            'deadline' => '2026-11-01',
            'responsible_text' => 'Viloyat favqulodda vaziyatlar boshqarmasi (I.Matchonov)',
            'assigned_org_id' => $org->id,
            'district_id' => $org->district_id,
            // «...kamida 10 ta ijtimoiy obyektda pilot tarzda joriy etish...»
            'target_value' => 10,
            'target_unit' => 'ijtimoiy obyekt',
            'priority' => 'yuqori',
            'sort_order' => 6,
        ], [
            'target_done' => 4,
            'status' => 'ijroda',
            'progress' => 40,
        ]);

        $this->item($protocol, '7', [
            'title' => 'Viloyat qishloq xoʻjaligi boshqarmasi hamda Fermer, dehqon xoʻjaliklari va tomorqa yer egalari viloyat kengashi «Agro Robo» loyihasini sinovdan oʻtkazish uchun pilot maydoncha ajratsin hamda 2027-yilgi qishloq xoʻjaligi mavsumiga qadar dala sinovlari oʻtkazilishini taʼminlasin.',
            'deadline_text' => '2026-yil 1-noyabr',
            'deadline' => '2026-11-01',
            'responsible_text' => 'Viloyat qishloq xoʻjaligi boshqarmasi (I.Masharipov)',
            'assigned_org_id' => $org->id,
            'district_id' => $org->district_id,
            'target_value' => 3,
            'target_unit' => 'fermer xoʻjaligi',
            'priority' => 'orta',
            'sort_order' => 7,
        ], [
            'target_done' => 3,
            'status' => 'tasdiq_kutilmoqda',
            'progress' => 100,
        ]);

        return $protocol;
    }

    /**
     * Bandni kiritish yoki HUJJAT MATNINI yangilash.
     *
     * `firstOrCreate` YETARLI EMAS edi: seeder matni oʻzgartirilганda
     * bazadagi eski qator shundayligicha qolib, ekranda eskirgan matn
     * koʻrinardi (aynan shu sabab bir band mexanizmsiz chiqdi).
     *
     * `updateOrCreate` ham toʻgʻri kelmaydi: u sinov paytida kiritilgan
     * IJRO holatini (status, foiz, «7/10») ham oʻchirib tashlardi.
     *
     * Shuning uchun ikkiga ajratildi:
     *   HUJJAT maydonlari — har safar yangilanadi (matn oʻzgarmas manba);
     *   IJRO maydonlari  — faqat yaratilganda qoʻyiladi (keyin tirik holat).
     *
     * @param  array<string, mixed>  $document  hujjatdan keladigan maydonlar
     * @param  array<string, mixed>  $initial  boshlangʻich ijro holati
     */
    private function item(Protocol $protocol, string $number, array $document, array $initial = []): void
    {
        $key = ['protocol_id' => $protocol->id, 'item_number' => $number];

        $task = Task::query()->where($key)->first();

        if ($task === null) {
            Task::query()->create($key + $document + $initial);

            return;
        }

        $task->update($document);
    }
}
