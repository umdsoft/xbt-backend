<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ижтимоий шартнома ХОНАДОНГА (кадастр биноси) боғланади.
 *
 * Dastlab `house_id -> houses` ishlatilgan edi, lekin `houses` jadvali
 * dangasa to'ldiriladi (deyarli bo'sh) va rais aynan kadastr binosi bilan
 * ishlaydi. Shuning uchun barqaror havola — `building_id -> master.buildings`.
 *
 * `street_id` denormallashtiriladi: statistika ko'cha kesimida yig'iladi va
 * har safar `buildings` bilan join qilmaslik uchun ko'cha shu yerda saqlanadi.
 * Bino ko'chasi o'zgarmaydi, shuning uchun nusxa xavfsiz.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('mahalla')->table('social_contracts', function (Blueprint $t) {
            $t->uuid('building_id')->nullable()->after('house_id')->index();
            $t->uuid('street_id')->nullable()->after('building_id')->index();
        });
    }

    public function down(): void
    {
        Schema::connection('mahalla')->table('social_contracts', function (Blueprint $t) {
            $t->dropColumn(['building_id', 'street_id']);
        });
    }
};
