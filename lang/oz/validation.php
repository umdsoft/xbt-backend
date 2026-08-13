<?php

declare(strict_types=1);

/*
 * O'zbek (kirill) validatsiya xabarlari.
 *
 * NEGA alohida `oz` lokali: platformada bir nechta domen bor va ularning
 * hammasi kirillga o'tgan emas. Global `APP_LOCALE` ni o'zgartirish boshqa
 * domenlarning javoblarini ham almashtirardi. Shu sabab lokal FAQAT qurilish
 * marshrutlari uchun `EnsureQurilish` middleware'ida yoqiladi.
 *
 * Bu yerda barcha qoidalar emas, domenda HAQIQATAN ishlatiladiganlari bor.
 * Ro'yxatga yangi qoida qo'shilsa (masalan `regex`), shu yerga ham qo'shiladi;
 * tarjimasi yo'q qoida inglizcha matn bilan chiqadi va bu darrov ko'zga tashlanadi.
 */
return [
    'required' => ':attribute майдонини тўлдиринг.',
    'required_if' => ':attribute майдонини тўлдиринг.',
    'filled' => ':attribute майдони бўш бўлмаслиги керак.',
    'present' => ':attribute майдони юборилиши шарт.',

    'string' => ':attribute матн кўринишида бўлиши керак.',
    'integer' => ':attribute бутун сон бўлиши керак.',
    'numeric' => ':attribute сон бўлиши керак.',
    'boolean' => ':attribute «ҳа» ёки «йўқ» бўлиши керак.',
    'array' => ':attribute рўйхат кўринишида бўлиши керак.',
    'date' => ':attribute тўғри сана бўлиши керак.',
    'uuid' => ':attribute тўғри идентификатор бўлиши керак.',
    'in' => 'Танланган :attribute қиймати нотўғри.',
    'exists' => 'Танланган :attribute топилмади.',
    'unique' => 'Бундай :attribute аллақачон мавжуд.',
    'confirmed' => ':attribute тасдиғи мос келмади.',
    'email' => ':attribute тўғри электрон почта бўлиши керак.',

    'min' => [
        'numeric' => ':attribute :min дан кичик бўлмаслиги керак.',
        'string' => ':attribute камида :min белгидан иборат бўлиши керак.',
        'array' => ':attribute камида :min та элементдан иборат бўлиши керак.',
        'file' => ':attribute камида :min килобайт бўлиши керак.',
    ],
    'max' => [
        'numeric' => ':attribute :max дан ошмаслиги керак.',
        'string' => ':attribute :max белгидан ошмаслиги керак.',
        'array' => ':attribute :max та элементдан ошмаслиги керак.',
        'file' => 'Файл ҳажми :max килобайтдан ошмаслиги керак.',
    ],
    'between' => [
        'numeric' => ':attribute :min ва :max оралиғида бўлиши керак.',
        'string' => ':attribute :min–:max белги оралиғида бўлиши керак.',
    ],

    'file' => ':attribute файл бўлиши керак.',
    'mimes' => 'Файл тури мос эмас. Рухсат этилган: :values.',
    'mimetypes' => 'Файл тури мос эмас. Рухсат этилган: :values.',

    /*
     * Maydon nomlari. Bularsiz xabar «The reason field...» ko'rinishida
     * texnik nom bilan chiqadi va foydalanuvchiga hech nima demaydi.
     */
    'attributes' => [
        'reason' => 'Рад этиш сабаби',
        'note' => 'Изоҳ',
        'name' => 'Объект номи',
        'malumot' => 'Босқич маълумоти',
        'started_at' => 'Бошланган сана',
        'completed_at' => 'Якунланган сана',
        'deadline_date' => 'Топшириш муддати',
        'deadline_year' => 'Топшириш йили',
        'limit_amount' => 'Ажратилган лимит',
        'tender_amount' => 'Тендер қиймати',
        'contract_amount' => 'Шартнома қиймати',
        'disbursed_amount' => 'Ўзлаштирилган маблағ',
        'financed_amount' => 'Молиялаштирилган маблағ',
        'program_id' => 'Давлат дастури',
        'sector_id' => 'Соҳа',
        'district_id' => 'Туман',
        'mahalla_id' => 'Маҳалла',
        'work_type' => 'Иш тури',
        'customer_org_id' => 'Буюртмачи',
        'designer_org_id' => 'Лойиҳачи',
        'contractor_org_id' => 'Пудратчи',
        'department_org_id' => 'Бошқарма',
        'lifecycle' => 'Ҳолати',
        'status' => 'Ҳолат',
        'file' => 'Файл',
        'category' => 'Тоифа',
        'stage_code' => 'Босқич',
        'estimated_amount' => 'Тахминий қиймат',
        'target_year' => 'Режалаштирилган йил',
        'priority' => 'Устуворлик',
        'login' => 'Логин',
        'password' => 'Парол',
    ],
];
