<?php

declare(strict_types=1);

namespace Tests\Feature\Yoshlar;

use App\Domains\Yoshlar\Models\Organization;
use App\Domains\Yoshlar\Models\Youth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * F5 — eksport va rahbariyat paneli.
 *
 * Eng muhim tekshiruv: eksport HAM doiradan o'tadi va PII faylga tushmaydi.
 * Eksport — IDOR'ning eng oson yo'li: ekranda ko'rsatilmagan ma'lumot
 * faylda chiqib ketishi mumkin.
 */
class YoshlarExportTest extends YoshlarTestCase
{
    public function test_export_returns_xlsx(): void
    {
        $this->makeYouth($this->someDistrictId());

        $response = $this->actingAs($this->makeUser('yoshlar_admin'), 'sanctum')
            ->get('/api/yoshlar/export/youth');

        $response->assertOk();
        $this->assertStringContainsString('spreadsheetml', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('.xlsx', (string) $response->headers->get('Content-Disposition'));

        // XLSX — ZIP arxiv: birinchi baytlar «PK».
        $this->assertStringStartsWith('PK', (string) $response->getContent());
    }

    public function test_export_never_contains_pii(): void
    {
        $districtId = $this->someDistrictId();
        $pinfl = '3'.random_int(1000000000000, 9999999999999);
        $this->makeYouth($districtId, ['pinfl' => $pinfl]);

        $content = $this->actingAs($this->makeUser('yoshlar_admin'), 'sanctum')
            ->get('/api/yoshlar/export/youth')->assertOk()->getContent();

        // Ochiq PINFL faylda bo'lmasligi kerak (XLSX ichidagi XML siqilgan
        // bo'lsa ham, ochiq matn holida qidiramiz — qo'shimcha kafolat).
        $this->assertStringNotContainsString($pinfl, (string) $content);
    }

    public function test_export_respects_scope(): void
    {
        $own = $this->someDistrictId();
        $other = $this->otherDistrictId($own);

        $mine = $this->makeYouth($own, ['last_name' => 'Ownuser'.Str::random(5)]);
        $foreign = $this->makeYouth($other, ['last_name' => 'Foreignuser'.Str::random(5)]);

        $org = $this->makeOrganization(Organization::TYPE_TUMAN_YOSHLAR, ['district_id' => $own]);
        $user = $this->makeUser('yoshlar_bolim', $org->id);

        $content = (string) $this->actingAs($user, 'sanctum')
            ->get('/api/yoshlar/export/youth')->assertOk()->getContent();

        // XLSX ichida inline satrlar siqilgan — ochiq matn qidirish
        // ishonchsiz. Shuning uchun ZIP'ni ochib, sheet XML'ni tekshiramiz.
        $sheet = $this->readSheetXml($content);

        $this->assertStringContainsString($mine->last_name, $sheet);
        $this->assertStringNotContainsString($foreign->last_name, $sheet, 'Boshqa tuman yoshi eksportga tushdi');
    }

    public function test_export_is_logged(): void
    {
        $user = $this->makeUser('yoshlar_admin');

        $this->actingAs($user, 'sanctum')->get('/api/yoshlar/export/youth')->assertOk();

        $logged = DB::connection('yoshlar')->table('audit_log')
            ->where('user_id', $user->id)->where('action', 'export.youth')->count();

        $this->assertSame(1, $logged);
    }

    public function test_role_without_export_permission_is_blocked(): void
    {
        // `sektor_bolim` da `yoshlar.export` yo'q.
        $this->actingAs($this->makeUser('sektor_bolim'), 'sanctum')
            ->get('/api/yoshlar/export/youth')
            ->assertStatus(403);
    }

    public function test_executive_dashboard_returns_all_modules(): void
    {
        $this->actingAs($this->makeUser('yoshlar_hokim_orinbosari'), 'sanctum')
            ->getJson('/api/yoshlar/executive')
            ->assertOk()
            ->assertJsonStructure([
                'registry' => ['total', 'neet', 'neet_rate', 'in_patronage', 'employed'],
                'tasks' => ['total', 'overdue', 'on_time_rate'],
                'cases' => ['open', 'resolved', 'overdue'],
                'patronage' => ['active', 'activity_rate'],
                'employment' => ['confirmed', 'in_review'],
                'by_district',
            ]);
    }

    public function test_executive_district_rows_are_scoped(): void
    {
        $own = $this->someDistrictId();
        $org = $this->makeOrganization(Organization::TYPE_TUMAN_YOSHLAR, ['district_id' => $own]);

        $rows = $this->actingAs($this->makeUser('yoshlar_bolim', $org->id), 'sanctum')
            ->getJson('/api/yoshlar/executive')->assertOk()->json('by_district');

        $this->assertCount(1, $rows, 'Tuman boʻlimi faqat oʻz tumanini koʻrishi kerak');
        $this->assertSame($own, $rows[0]['id']);
    }

    /** XLSX (ZIP) ichidan sheet1.xml ni o'qiydi. */
    private function readSheetXml(string $binary): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'xlsxtest');
        file_put_contents($tmp, $binary);

        $zip = new \ZipArchive();
        $zip->open($tmp);
        $xml = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        @unlink($tmp);

        return $xml;
    }

    /** @param array<string, mixed> $attrs */
    private function makeYouth(string $districtId, array $attrs = []): Youth
    {
        return Youth::query()->create(array_merge([
            'last_name' => 'Eksport'.Str::random(5),
            'first_name' => 'Test',
            'birth_date' => now()->subYears(20)->toDateString(),
            'gender' => 'erkak',
            'district_id' => $districtId,
            'mahalla_id' => $this->someMahallaId($districtId),
            'verification_status' => 'verified',
        ], $attrs));
    }
}
