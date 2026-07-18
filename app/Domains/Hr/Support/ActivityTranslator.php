<?php

declare(strict_types=1);

namespace App\Domains\Hr\Support;

/**
 * Audit log атамаларини ўзбекчага (Кирилл) таржима қилади.
 * AuditController ва DashboardController::recentActivity ишлатади.
 */
class ActivityTranslator
{
    /** Ҳодиса (event) номлари. */
    private const EVENTS = [
        'created' => 'Яратилди',
        'updated' => 'Янгиланди',
        'deleted' => 'Ўчирилди',
        'restored' => 'Тикланди',
        'force_deleted' => 'Бутунлай ўчирилди',
        'login' => 'Тизимга кирди',
        'logout' => 'Тизимдан чиқди',
    ];

    /** Объект (subject) турлари — class_basename бўйича. */
    private const SUBJECTS = [
        'Employee' => 'Ходим',
        'User' => 'Фойдаланувчи',
        'HrProfile' => 'Фойдаланувчи',
        'ControlPlan' => 'Назорат режа',
        'ControlPlanItem' => 'Назорат режа банди',
        'CitizenAppeal' => 'Фуқаро мурожаати',
        'YouthMeeting' => 'Ёшлар учрашуви',
        'MahallaCouncil' => 'Маҳалла кенгаши',
        'HokimYordamchisi' => 'Ҳоким ёрдамчиси',
        'YoshlarYetakchisi' => 'Ёшлар етакчиси',
        'Department' => 'Бошқарма/Бўлим',
        'Position' => 'Лавозим',
        'ItemDocument' => 'Ҳужжат',
        'WorkHistory' => 'Меҳнат фаолияти',
        'Relative' => 'Қариндош',
    ];

    public static function event(?string $description): string
    {
        if ($description === null || $description === '') {
            return '—';
        }

        return self::EVENTS[$description] ?? $description;
    }

    public static function subject(?string $classBasename): string
    {
        if ($classBasename === null || $classBasename === '') {
            return '—';
        }

        return self::SUBJECTS[$classBasename] ?? $classBasename;
    }
}
