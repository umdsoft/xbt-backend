<?php

declare(strict_types=1);

namespace App\Domains\Ayollar\Services;

use App\Domains\Ayollar\Models\Staff;
use App\Domains\Ayollar\Support\AyollarAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * HISOB OCHISH — uchta jadvalga bitta amal.
 *
 * Ayollar foydalanuvchisi UCH joyda yashaydi va uchalasi ham
 * bo'lmasa hisob YARIM ishlaydi:
 *
 *   `auth.users`               — KIM (login, parol, F.I.Sh., telefon)
 *   `auth.user_system_access`  — NIMA QILA OLADI (rol)
 *   `ayollar.staff`            — NIMANI KO'RADI (tuman/MFY doirasi)
 *
 * Uchtasidan bittasi tushib qolsa xato JIM bo'ladi: foydalanuvchi
 * kiradi, lekin bo'sh ekran ko'radi (`staff` yo'q) yoki 403 oladi
 * (`user_system_access` yo'q). Shuning uchun yozish bitta
 * tranzaksiyada va bitta joyda turadi.
 *
 * NEGA SERVIS: bu mantiq avval faqat CLI buyrug'ida edi. Endi
 * administrator uni brauzerdan ham bajaradi — ikki nusxa yozilsa,
 * biri o'zgarganda ikkinchisi eskirib qolardi.
 */
class StaffProvisioner
{
    /**
     * Hisob yaratadi yoki yangilaydi.
     *
     * @param  array{login:string,name:string,phone?:?string,role:string,district_id?:?string,mahalla_id?:?string,org_code?:?string,position?:?string,password?:?string,is_active?:bool}  $data
     * @return array{user_id:string,password:?string,created:bool}
     */
    public function save(array $data, ?string $userId = null): array
    {
        $this->assertScope($data);

        $auth = DB::connection('auth');
        $systemId = $auth->table('systems')->where('code', AyollarAccess::SYSTEM_CODE)->value('id');

        if ($systemId === null) {
            throw new RuntimeException('auth.systems da `ayollar` tizimi yo‘q.');
        }

        $password = $data['password'] ?? null;
        $created = $userId === null;

        return DB::connection('auth')->transaction(function () use ($auth, $systemId, $data, $userId, $password, $created) {
            $id = $userId ?? (string) Str::uuid();

            $fields = [
                'login' => $data['login'],
                'name' => $data['name'],
                'phone' => $data['phone'] ?? null,
                'is_active' => $data['is_active'] ?? true,
                'updated_at' => now(),
            ];

            // Parol FAQAT berilganda yoziladi. Tahrirlashda uni har
            // safar qayta yozish foydalanuvchini tizimdan chiqarib
            // yuborardi — administrator esa faqat telefonini
            // tuzatmoqchi bo'lgan bo'lishi mumkin.
            if ($password !== null && $password !== '') {
                $fields['password'] = Hash::make($password);
            }

            if ($created) {
                $auth->table('users')->insert($fields + [
                    'id' => $id,
                    'created_at' => now(),
                ]);
            } else {
                $auth->table('users')->where('id', $id)->update($fields);
            }

            // Bir foydalanuvchi — bir tizimda BITTA rol. Eskisini
            // o'chirib qayta yozamiz, aks holda rol o'zgarganda ikkita
            // grant qolib, huquqlar birlashib ketardi.
            $auth->table('user_system_access')
                ->where('user_id', $id)->where('system_id', $systemId)->delete();

            $auth->table('user_system_access')->insert([
                'id' => (string) Str::uuid(),
                'user_id' => $id,
                'system_id' => $systemId,
                'role' => $data['role'],
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            Staff::query()->updateOrCreate(
                ['user_id' => $id],
                [
                    'region_id' => DB::connection('master')->table('regions')->value('id'),
                    'district_id' => $data['district_id'] ?? null,
                    'mahalla_id' => $data['mahalla_id'] ?? null,
                    'org_code' => $data['org_code'] ?? null,
                    // `?? null` MAJBURIY: `position` ixtiyoriy maydon va
                    // so'rovda umuman bo'lmasligi mumkin. Faqat `?:`
                    // ishlatilganda «Undefined array key» xatosi chiqib,
                    // hisob ochilmay qolardi.
                    'position' => ($data['position'] ?? null) ?: AyollarAccess::ROLE_NAMES[$data['role']],
                    'is_active' => $data['is_active'] ?? true,
                ],
            );

            return ['user_id' => $id, 'password' => $password, 'created' => $created];
        });
    }

    /**
     * Hisobni o'chiradi — YUMSHOQ.
     *
     * Yozuv o'chirilmaydi, faqat faolsizlantiriladi. Sabab: anketalarda
     * `created_by` shu foydalanuvchiga ishora qiladi va uni yo'qotish
     * «kim to'ldirgan?» degan savolni javobsiz qoldirardi. Anketa esa
     * hujjat — uning mualliflik zanjiri uzilmasligi kerak.
     */
    public function deactivate(string $userId): void
    {
        DB::connection('auth')->table('users')
            ->where('id', $userId)
            ->update(['is_active' => false, 'updated_at' => now()]);

        Staff::query()->where('user_id', $userId)->update(['is_active' => false]);
    }

    /** Tasodifiy parol — administrator o'zi o'ylab topmasligi uchun. */
    public function randomPassword(): string
    {
        // Chalkashadigan belgilar (0/O, 1/l/I) YO'Q: parol qog'ozga
        // yozib beriladi va faol uni planshetda qo'lda teradi.
        $letters = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz';
        $digits = '23456789';

        /*
            HARF VA RAQAM KAFOLATLANADI.

            Avval 12 belgi bitta alifbodan tanlanardi va natijada
            har yettinchi parol RAQAMSIZ chiqardi. Markaziy siyosat
            (PasswordPolicy) esa harf va raqamni talab qiladi — ya'ni
            tizim o'zi bergan parol o'z qoidasidan o'tmasdi.
        */
        $pick = static fn (string $set, int $n): array => array_map(
            static fn () => $set[random_int(0, strlen($set) - 1)],
            range(1, $n),
        );

        $chars = [...$pick($letters, 9), ...$pick($digits, 3)];

        // Raqamlar oxirida to'planib qolmasin — aks holda naqsh taxmin qilinadi.
        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }

        return implode('', $chars);
    }

    /**
     * Rol va doira mosligi.
     *
     * MFY FAQAT FAOL uchun majburiy. U aniq bir mahallada, aniq
     * ko'chalarda yuradi — MFYsiz uning marshruti ham, anketasi ham
     * ma'nosiz.
     *
     * Qolgan rollarga TUMAN yetarli:
     *   MFY raisi           MFY berilsa o'sha MFY, aks holda tuman
     *   Hokim yordamchisi   tuman hokimi o'rinbosari butun tumanni ko'radi
     *   Tuman bo'limi/idorasi  tuman
     *
     * Avval uchala MFY darajasidagi rolga ham MFY majburiy edi va
     * tuman hokimi o'rinbosariga hisob ochib bo'lmasdi — u bitta
     * MFYga qamalardi.
     *
     * ENG MUHIMI: DOIRASIZ hisob ochilmaydi. Rol berilgan, lekin na
     * MFY na tuman biriktirilgan foydalanuvchi kiradi va BO'SH ekran
     * ko'radi — bu eng yomon xato turi, chunki hech narsa buzilmaydi,
     * shunchaki ishlamaydi va sababi ko'rinmaydi.
     *
     * @param  array<string, mixed>  $data
     */
    private function assertScope(array $data): void
    {
        $role = $data['role'];
        $level = AyollarAccess::ROLE_SCOPE[$role] ?? null;

        if ($level === AyollarAccess::SCOPE_REGION) {
            return;
        }

        $hasMahalla = ! empty($data['mahalla_id']);
        $hasDistrict = ! empty($data['district_id']);

        if ($role === AyollarAccess::ROLE_ACTIVIST && ! $hasMahalla) {
            throw new RuntimeException(
                'Faol uchun MFY majburiy — u aniq mahallada ishlaydi va MFYsiz marshrut tuzilmaydi.',
            );
        }

        if (! $hasMahalla && ! $hasDistrict) {
            throw new RuntimeException(
                'Kamida tuman tanlanishi kerak — aks holda foydalanuvchi bo‘sh ekran ko‘radi.',
            );
        }
    }
}
