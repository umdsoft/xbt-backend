<?php

declare(strict_types=1);

namespace Tests\Feature\Qurilish;

use App\Domains\Qurilish\Models\ConstructionObject;
use App\Domains\Qurilish\Models\ObjectMedia;
use App\Domains\Qurilish\Models\ObjectStage;
use App\Domains\Qurilish\Models\WeeklyReport;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Haftalik ijro hisoboti, bosqich medialari va arxiv.
 *
 * Kafolatlar: hisobot faqat ijro bosqichida yuritiladi, bir haftaga bitta
 * hisobot, haftalik o'sish HOSILA (qo'lda kiritilmaydi), tasdiqlanmagan
 * hisobot tahrirlanmaydi va kiritilmagan haftalar ko'rinadi.
 */
class QurilishWeeklyTest extends QurilishObjectTestCase
{
    // ---------- Haftalik hisobot ----------

    public function test_weekly_report_only_during_execution(): void
    {
        [$object, $customer] = $this->executionObject();

        // Loyihalash bosqichidagi obyektda haftalik hisobot yuritilmaydi.
        $planning = $this->makeObject([
            'name' => $this->tag('P'),
            'customer_org_id' => $object->customer_org_id,
            'current_stage' => 'designer_selection',
        ]);

        $this->actingAs($customer, 'sanctum')
            ->postJson($this->url($planning), ['works_done' => 'Иш'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('object');

        $this->actingAs($customer, 'sanctum')
            ->postJson($this->url($object), ['works_done' => 'Асфальт ётқизилди', 'progress_pct' => 40])
            ->assertStatus(201)
            ->assertJsonPath('data.status', 'qoralama');
    }

    public function test_one_report_per_week_and_week_progress_is_derived(): void
    {
        [$object, $customer] = $this->executionObject();

        $first = CarbonImmutable::now()->subWeeks(2)->toDateString();
        $second = CarbonImmutable::now()->subWeek()->toDateString();

        // JSON da 30.0 «30» bo'lib chiqadi, shuning uchun qat'iy emas,
        // SONLI taqqoslash: bu yerda muhimi tur emas, qiymat.
        $res = $this->actingAs($customer, 'sanctum')
            ->postJson($this->url($object), ['date' => $first, 'progress_pct' => 30, 'works_done' => 'Пойдевор'])
            ->assertStatus(201);
        $this->assertEqualsWithDelta(30, $res->json('data.week_progress_pct'), 0.001);

        // Ikkinchi hafta: 55 % — o'sish 25 %, uni foydalanuvchi kiritmaydi.
        $res = $this->actingAs($customer, 'sanctum')
            ->postJson($this->url($object), ['date' => $second, 'progress_pct' => 55, 'works_done' => 'Девор'])
            ->assertStatus(201);
        $this->assertEqualsWithDelta(25, $res->json('data.week_progress_pct'), 0.001);

        // Bir haftaga ikkinchi hisobot ochilmaydi — mavjudi yangilanadi.
        $this->actingAs($customer, 'sanctum')
            ->postJson($this->url($object), ['date' => $second, 'progress_pct' => 60, 'works_done' => 'Девор ва том'])
            ->assertStatus(201);

        $this->assertSame(2, WeeklyReport::query()->where('object_id', $object->id)->count());
    }

    public function test_empty_report_cannot_be_submitted(): void
    {
        [$object, $customer] = $this->executionObject();

        $id = $this->actingAs($customer, 'sanctum')
            ->postJson($this->url($object), ['progress_pct' => 10])
            ->assertStatus(201)->json('data.id');

        // Bo'sh hisobot moderator vaqtini yeydi — u yuborilmaydi.
        $this->actingAs($customer, 'sanctum')
            ->postJson($this->url($object)."/{$id}/submit")
            ->assertStatus(422)
            ->assertJsonValidationErrors('works_done');
    }

    public function test_full_weekly_moderation_cycle(): void
    {
        [$object, $customer] = $this->executionObject();
        $moderator = $this->makeUser('qurilish_prokuratura');

        $id = $this->draft($object, $customer);

        $this->actingAs($customer, 'sanctum')
            ->postJson($this->url($object)."/{$id}/submit")
            ->assertOk()->assertJsonPath('data.status', 'tasdiqlash_kutilmoqda');

        // Yuborilgan hisobot endi tahrirlanmaydi.
        $this->actingAs($customer, 'sanctum')
            ->postJson($this->url($object), ['progress_pct' => 99, 'works_done' => 'Ўзгартириш'])
            ->assertStatus(422)->assertJsonValidationErrors('status');

        // Buyurtmachi o'zi tasdiqlay olmaydi.
        $this->actingAs($customer, 'sanctum')
            ->postJson($this->url($object)."/{$id}/approve")->assertStatus(403);

        $this->actingAs($moderator, 'sanctum')
            ->postJson($this->url($object)."/{$id}/review")
            ->assertOk()->assertJsonPath('data.status', 'korib_chiqilmoqda');

        $this->actingAs($moderator, 'sanctum')
            ->postJson($this->url($object)."/{$id}/reject", ['reason' => ''])
            ->assertStatus(422)->assertJsonValidationErrors('reason');

        $this->actingAs($moderator, 'sanctum')
            ->postJson($this->url($object)."/{$id}/approve")
            ->assertOk()->assertJsonPath('data.status', 'tasdiqlangan');
    }

    public function test_rejected_report_returns_to_draft_and_keeps_reason(): void
    {
        [$object, $customer] = $this->executionObject();
        $moderator = $this->makeUser('qurilish_prokuratura');

        $id = $this->draft($object, $customer);
        $this->actingAs($customer, 'sanctum')->postJson($this->url($object)."/{$id}/submit")->assertOk();
        $this->actingAs($moderator, 'sanctum')->postJson($this->url($object)."/{$id}/review")->assertOk();

        $this->actingAs($moderator, 'sanctum')
            ->postJson($this->url($object)."/{$id}/reject", ['reason' => 'Сурат илова қилинмаган'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rad_etilgan')
            ->assertJsonPath('data.rejection_reason', 'Сурат илова қилинмаган');

        // Rad etilgan hisobot yana tahrirlanadi.
        $this->actingAs($customer, 'sanctum')
            ->postJson($this->url($object), ['progress_pct' => 45, 'works_done' => 'Суратлар қўшилди'])
            ->assertStatus(201)->assertJsonPath('data.status', 'qoralama');
    }

    public function test_archive_lists_newest_first_and_shows_missing_weeks(): void
    {
        [$object, $customer] = $this->executionObject();

        $this->actingAs($customer, 'sanctum')->postJson($this->url($object), [
            'date' => CarbonImmutable::now()->subWeeks(3)->toDateString(),
            'progress_pct' => 20, 'works_done' => 'Учинчи ҳафта',
        ])->assertStatus(201);

        $this->actingAs($customer, 'sanctum')->postJson($this->url($object), [
            'date' => CarbonImmutable::now()->toDateString(),
            'progress_pct' => 70, 'works_done' => 'Жорий ҳафта',
        ])->assertStatus(201);

        $res = $this->actingAs($customer, 'sanctum')->getJson($this->url($object))->assertOk();

        $rows = $res->json('data');
        $this->assertCount(2, $rows);
        $this->assertSame('Жорий ҳафта', $rows[0]['works_done'], 'Архив янгисидан бошланади');

        // Oradagi ikki hafta kiritilmagan — nazoratning asosiy signali.
        $missing = collect($res->json('missing'));
        $this->assertGreaterThanOrEqual(2, $missing->count());
    }

    public function test_weekly_queue_is_scoped(): void
    {
        [$object, $customer] = $this->executionObject();
        $id = $this->draft($object, $customer);
        $this->actingAs($customer, 'sanctum')->postJson($this->url($object)."/{$id}/submit")->assertOk();

        $res = $this->actingAs($this->makeUser('qurilish_prokuratura'), 'sanctum')
            ->getJson('/api/qurilish/weekly/queue')->assertOk();
        $this->assertNotNull(collect($res->json('data'))->firstWhere('object_id', $object->id));

        $otherOrg = $this->makeOrganization('Бошқа буюртмачи', ['is_customer' => true]);
        $res = $this->actingAs($this->makeUser('qurilish_buyurtmachi', $otherOrg), 'sanctum')
            ->getJson('/api/qurilish/weekly/queue')->assertOk();
        $this->assertNull(collect($res->json('data'))->firstWhere('object_id', $object->id));
    }

    // ---------- Media ----------

    public function test_photo_upload_is_attached_to_a_stage(): void
    {
        Storage::fake('local');
        [$object, $customer] = $this->executionObject();

        $res = $this->actingAs($customer, 'sanctum')
            ->postJson("/api/qurilish/objects/{$object->id}/media", [
                'file' => UploadedFile::fake()->image('ijro.jpg', 800, 600),
                'stage_code' => 'execution',
                'taken_at' => CarbonImmutable::now()->subDay()->toDateString(),
                'title' => 'Асфальт ётқизиш',
            ])->assertStatus(201);

        $this->assertSame('photo', $res->json('data.kind'));
        $this->assertSame('execution', $res->json('data.stage_code'));
        $this->assertSame(800, $res->json('data.width'));
        // URL `/api` префиксисиз қайтади: SPA `fileUrl()` уни ўзи қўшади.
        // Иккинчи марта қўшилса `/api/api/...` бўлиб, сурат юкланмай қоларди.
        $url = (string) $res->json('data.url');
        $this->assertStringStartsWith('/qurilish/objects/', $url);
        $this->assertStringEndsWith('/file', $url);

        // Файлнинг ўзи ҳам берилиши керак.
        $this->actingAs($customer, 'sanctum')->get('/api'.$url)->assertOk();

        $listed = $this->actingAs($customer, 'sanctum')
            ->getJson("/api/qurilish/objects/{$object->id}/media?stage_code=execution")->assertOk();

        $this->assertSame(1, $listed->json('counts.photo'));
    }

    public function test_future_photo_date_is_rejected(): void
    {
        Storage::fake('local');
        [$object, $customer] = $this->executionObject();

        // Kelajakdagi sana dalilni soxtalashtiradi.
        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/qurilish/objects/{$object->id}/media", [
                'file' => UploadedFile::fake()->image('kelajak.jpg'),
                'taken_at' => CarbonImmutable::now()->addWeek()->toDateString(),
            ])->assertStatus(422)->assertJsonValidationErrors('taken_at');
    }

    public function test_unsupported_file_type_is_rejected(): void
    {
        Storage::fake('local');
        [$object, $customer] = $this->executionObject();

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/qurilish/objects/{$object->id}/media", [
                'file' => UploadedFile::fake()->create('hisobot.pdf', 100, 'application/pdf'),
            ])->assertStatus(422)->assertJsonValidationErrors('file');
    }

    public function test_only_one_cover_per_stage(): void
    {
        Storage::fake('local');
        [$object, $customer] = $this->executionObject();

        $ids = [];
        foreach (['a.jpg', 'b.jpg'] as $name) {
            $ids[] = $this->actingAs($customer, 'sanctum')
                ->postJson("/api/qurilish/objects/{$object->id}/media", [
                    'file' => UploadedFile::fake()->image($name),
                    'stage_code' => 'execution',
                ])->assertStatus(201)->json('data.id');
        }

        foreach ($ids as $id) {
            $this->actingAs($customer, 'sanctum')
                ->postJson("/api/qurilish/objects/{$object->id}/media/{$id}/cover")->assertOk();
        }

        $covers = ObjectMedia::query()
            ->where('object_id', $object->id)->where('stage_code', 'execution')
            ->where('is_cover', true)->count();

        $this->assertSame(1, $covers, 'Бир босқичда фақат битта асосий сурат бўлади');
    }

    public function test_viewer_cannot_upload_media(): void
    {
        Storage::fake('local');
        [$object] = $this->executionObject();

        $this->actingAs($this->makeUser('qurilish_hokimlik'), 'sanctum')
            ->postJson("/api/qurilish/objects/{$object->id}/media", [
                'file' => UploadedFile::fake()->image('a.jpg'),
            ])->assertStatus(403);
    }

    public function test_media_of_another_organization_is_not_reachable(): void
    {
        Storage::fake('local');
        [$object, $customer] = $this->executionObject();

        $id = $this->actingAs($customer, 'sanctum')
            ->postJson("/api/qurilish/objects/{$object->id}/media", [
                'file' => UploadedFile::fake()->image('maxfiy.jpg'),
            ])->assertStatus(201)->json('data.id');

        $otherOrg = $this->makeOrganization('Бошқа буюртмачи', ['is_customer' => true]);
        $other = $this->makeUser('qurilish_buyurtmachi', $otherOrg);

        // Scope obyekt darajasida ishlaydi — media ham u orqali yopiladi.
        $this->actingAs($other, 'sanctum')
            ->get("/api/qurilish/objects/{$object->id}/media/{$id}/file")->assertStatus(404);
    }

    // ---------- yordamchilar ----------

    /** @return array{0: ConstructionObject, 1: User} */
    private function executionObject(): array
    {
        $org = $this->makeOrganization('Буюртмачи', ['is_customer' => true]);
        $object = $this->makeObject([
            'name' => $this->tag('W'),
            'customer_org_id' => $org,
            'current_stage' => 'execution',
        ]);

        // Ijro bosqichi 4 hafta oldin boshlangan — «kiritilmagan haftalar»
        // hisoblanishi uchun boshlanish sanasi kerak.
        $this->setStageStarted($object, 'execution', CarbonImmutable::now()->subWeeks(4)->toDateString());

        return [$object, $this->makeUser('qurilish_buyurtmachi', $org)];
    }

    private function setStageStarted(ConstructionObject $object, string $code, string $date): void
    {
        ObjectStage::query()
            ->where('object_id', $object->id)->where('stage_code', $code)
            ->update(['status' => 'qoralama', 'started_at' => $date]);
    }

    private function draft(ConstructionObject $object, User $customer): string
    {
        return (string) $this->actingAs($customer, 'sanctum')
            ->postJson($this->url($object), ['progress_pct' => 40, 'works_done' => 'Ҳафталик иш'])
            ->assertStatus(201)->json('data.id');
    }

    private function url(ConstructionObject $object): string
    {
        return "/api/qurilish/objects/{$object->id}/weekly";
    }
}
