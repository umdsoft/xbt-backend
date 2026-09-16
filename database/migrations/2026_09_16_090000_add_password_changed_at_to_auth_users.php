<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `auth.users.password_changed_at` — parol qachon o'zgartirilgan.
 *
 * NEGA KERAK: «hisobim ishlamayapti» degan murojaatda administratorda
 * hech qanday iz yo'q edi. Parol o'zgarganmi yoki foydalanuvchi eski
 * parolni terayaptimi — buni ajratib bo'lmasdi, va har safar parol
 * qaytadan tiklanardi. Bitta sana shu savolga javob beradi.
 *
 * QO'SHIMCHA USTUN, mavjud ma'lumotga TEGMAYDI: `nullable`, standart
 * qiymatsiz. Eski qatorlar `null` bo'lib qoladi — ya'ni «parol
 * ochilgandan beri o'zgarmagan». Jadvalni qayta yozish talab
 * qilinmaydi, shuning uchun jonli tizimda ham xavfsiz.
 *
 * Parolning O'ZI yoki uning tarixi SAQLANMAYDI — faqat sana.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::connection('auth')->hasTable('users')) {
            return;
        }

        if (Schema::connection('auth')->hasColumn('users', 'password_changed_at')) {
            return;
        }

        Schema::connection('auth')->table('users', function (Blueprint $table) {
            $table->timestamp('password_changed_at')->nullable()->after('last_login_at');
        });
    }

    public function down(): void
    {
        if (! Schema::connection('auth')->hasColumn('users', 'password_changed_at')) {
            return;
        }

        Schema::connection('auth')->table('users', function (Blueprint $table) {
            $table->dropColumn('password_changed_at');
        });
    }
};
