<?php

declare(strict_types=1);

namespace App\Domains\Hr\Enums;

enum AppealStatus: string
{
    case DRAFT = 'draft';
    case SUBMITTED = 'submitted';
    case TRIAGED = 'triaged';
    case ROUTED = 'routed';
    case IN_REVIEW = 'in_review';
    case DECIDED = 'decided';
    case COMPLETED = 'completed';
    case CLOSED = 'closed';
    case REOPENED = 'reopened';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Қоралама',
            self::SUBMITTED => 'Юборилди',
            self::TRIAGED => 'Сараланди',
            self::ROUTED => 'Йўналтирилди',
            self::IN_REVIEW => 'Кўриб чиқилмоқда',
            self::DECIDED => 'Қарор қабул қилинди',
            self::COMPLETED => 'Якунланди',
            self::CLOSED => 'Ёпилди',
            self::REOPENED => 'Қайта очилди',
        };
    }

    public static function values(): string
    {
        return implode(',', array_column(self::cases(), 'value'));
    }
}
