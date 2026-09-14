# Раҳбар контекст панели — амалга ошириш режаси

> **Агент ишчилар учун:** МАЖБУРИЙ КИЧИК КЎНИКМА: `superpowers:subagent-driven-development`.

**Дизайн:** `D:\kadr\platform\docs\superpowers\specs\2026-09-14-rahbar-kontekst-paneli-design.md` — **аввал ўқинг.**

**Мақсад:** «Атроф» харитасига контекст панели қўшиш — раҳбар юрганда турган маҳалласи ҳақидаги маълумот ўзи чиқиб турсин.

---

## Глобал чекловлар

1. **Backend:** PHP 8.4 — `C:\php84\php.exe`. Стандарт `php` 8.3 ва ишламайди.
   **Мобил:** Flutter PATH да йўқ — `C:\flutter\bin\flutter.bat`.
2. Тестлар: фақат `DatabaseTransactions`, `$connectionsToTransact = ['pgsql','auth','master','mahalla']`.
   **`ayollar` уланиши бу рўйхатга ҚЎШИЛМАЙДИ** — эндпойнт ундан фақат ЎҚИЙДИ.
3. **Аёллар доменига (`app/Domains/Ayollar`, `routes/api/ayollar.php`, `D:\kadr\ayollar*`) БИР ҚАТОР ҲАМ тегилмайди.**
4. **`capture/` ва `upload/` га тегилмайди**; раҳбар дарахти улардан import қилмайди (`readonly_guarantee_test` буни қулфлайди).
5. Матнлар кирилл ўзбекчада, ранглар фақат `AppColors`.
6. Коммит хабарлари ўзбекча, аттрибуция қаторларисиз.

---

### Task B1: Аёллар жамланма эндпойнти

**Репо:** `D:\kadr\platform`, тармоқ `feature/ayollar-summary`, база `main`.

**Файллар:**
- Ўзгартириш: `config/database.php` (ayollar уланиши)
- Ўзгартириш: `config/mahalla.php` (small-cell чегараси)
- Яратиш: `app/Domains/Mahalla/Services/AyollarSummary.php`
- Яратиш: `app/Domains/Mahalla/Http/Controllers/Api/Executive/AyollarSummaryController.php`
- Ўзгартириш: `routes/api/mahalla.php`
- Яратиш: `tests/Feature/Mahalla/AyollarSummaryTest.php`

- [ ] **Қадам 1: `ayollar` уланишини қўшиш**

`config/database.php` га — `deploy/ayollar` тармоғидаги билан **АЙНАН БИР ХИЛ** (merge зиддиятини олдини олиш учун):

```php
        'ayollar' => [
            'driver' => 'pgsql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => env('DB_AYOLLAR_SEARCH_PATH', 'ayollar,master,public'),
            'sslmode' => env('DB_SSLMODE', 'prefer'),
        ],
```
`'mahalla'` уланишидан кейин, `'sqlsrv'` дан олдин қўй (деплой тармоғидаги жой).

`config/mahalla.php` га:
```php
    /*
     * Аёллар жамланмасида кичик сонларни яшириш чегараси.
     * Нозик тоифада бундан кам бўлса аниқ сон берилмайди — кичик маҳаллада
     * «зўравонлик қурбони: 1» шахсни очиб беради (small-cell disclosure).
     */
    'ayollar_small_cell_threshold' => (int) env('MAHALLA_AYOLLAR_SMALL_CELL', 5),
```

- [ ] **Қадам 2: Йиқиладиган тестларни ёзиш**

`tests/Feature/Mahalla/AyollarSummaryTest.php`. Ёрдамчиларни `TumanViewerScopeTest.php` дан ол
(`makeTumanUser`, `districtId`, `anotherDistrictId`, `makeUserWithRole`) — такрорлама, ўша файлдан
`protected` методларни мерос қилиб олиш учун ё кўчир, ё умумий trait га чиқар (танловингни ҳисоботда ёз).

Қамрайдиган ҳолатлар:

```php
public function test_tuman_can_read_summary_for_own_mahalla(): void
// 200, javobda mahalla.id, started, balance, flags kalitlari bor

public function test_tuman_cannot_read_summary_for_another_district(): void
// 404 (403 EMAS — begona mahalla id sining MAVJUDLIGI oshkor bo'lmasin)

public function test_started_is_false_when_balance_total_is_zero(): void
// Shovot mahallalarida hozir aynan shunday

public function test_sensitive_flag_below_threshold_is_suppressed(): void
// ayollar.anketa_red_flags ga TEST QATORI qo'shib, violence_victim = 1 bo'lsa
// javobda count berilmasin, suppressed = true bo'lsin

public function test_non_sensitive_flag_is_not_suppressed(): void
// chronic_illness = 1 bo'lsa aniq son chiqsin

public function test_sensitive_flag_at_or_above_threshold_is_exact(): void
// violence_victim = 5 bo'lsa aniq son chiqsin

public function test_response_never_contains_personal_fields(): void
// Javob JSON matnida 'full_name', 'pinfl', 'passport', 'phone', 'address',
// 'birth_date' kabi kalitlar UMUMAN bo'lmasin. Bu KAFOLAT testi.

public function test_viloyat_can_read_any_mahalla(): void
// regressiya
```

> **Тест фиксураси ҳақида диққат:** `ayollar` уланиши `connectionsToTransact` да ЙЎҚ, демак
> у ерга ёзилган қатор **қайтарилмайди**. Шунинг учун ё (а) `ayollar` ни ҳам рўйхатга қўш
> (лекин у ҳолда base `TestCase` чекловини текшир), ё (б) тестдан кейин `finally` да ўзинг
> ўчир. Танловингни ва сабабини ҳисоботда ёз. **Умумий дев базасига ахлат қолдирма.**

- [ ] **Қадам 3: Йиқилишини кўриш** — `C:\php84\php.exe vendor/bin/phpunit --filter=AyollarSummaryTest`

- [ ] **Қадам 4: `AyollarSummary` сервисини ёзиш**

```php
final class AyollarSummary
{
    /** Нозик тоифалар — кичик сонлар яширилади. */
    private const SENSITIVE = [
        'violence_victim', 'protection_order', 'minor_mother', 'probation',
        'prevention_record', 'narcology_record', 'human_trafficking',
    ];

    /** 13 та байроқ коди → кирилл ёрлиқ. */
    private const LABELS = [
        'chronic_illness' => 'Сурункали касаллик',
        'divorced_widowed' => 'Ажрашган / бева',
        'conflict_family' => 'Низоли оила',
        'social_registry' => 'Ижтимоий реестрда',
        'alimony_problem' => 'Алимент муаммоси',
        'violence_victim' => 'Зўравонлик қурбони',
        'protection_order' => 'Ҳимоя ордери',
        'minor_mother' => 'Вояга етмаган она',
        'probation' => 'Пробация назоратида',
        'prevention_record' => 'Профилактика ҳисобида',
        'narcology_record' => 'Наркология ҳисобида',
        'alien_ideology' => 'Бегона мафкура таъсирида',
        'human_trafficking' => 'Одам савдоси қурбони',
    ];

    public function forMahalla(string $mahallaId): array { /* ... */ }
}
```

Мантиқ:
1. Схема борлигини текшир — йўқ бўлса `['available' => false, ...]` қайтар, портлама.
2. Энг охирги давр учун `ayollar.mahalla_balances` қаторини ол (`order by period_year desc, period_month desc limit 1`).
3. `ayollar.anketa_red_flags` ни `anketas` орқали шу маҳаллага боғлаб, `flag_code` бўйича `count(*)` ол.
4. Ҳар кодга ёрлиқ қўй; нозик ва `count < threshold` бўлса `count` ни `null` қил, `suppressed = true`.
5. `started = (balance.total ?? 0) > 0`.
6. `urgent_total` = нозик тоифалар йиғиндиси (**яширилмайди** — жами сон шахсни очмайди).

> Ноль қийматли байроқларни ҳам қайтар (13 тасини ҳаммасини) — мобил томон рўйхатни
> барқарор кўрсатсин, маълумот келганда элемент «сакраб» пайдо бўлмасин.

- [ ] **Қадам 5: Контроллер ва маршрут**

```php
Route::get('/mahallas/{mahalla}/ayollar-summary', AyollarSummaryController::class)
    ->name('mahalla.ayollar-summary')
    ->whereUuid('mahalla');
```
`executive` гуруҳи ичида, бошқа `mahallas/{mahalla}/...` маршрутлари ёнида.

Контроллер `ExecutiveScope::mahalla($request->user(), $mahalla)` орқали моделни олади
(Task 4 даги учта контроллер билан бир хил намуна), сўнг `AyollarSummary::forMahalla()`.

- [ ] **Қадам 6: Тестларни ўтказиш + тўлиқ тўплам**

```
C:\php84\php.exe vendor/bin/phpunit --filter=AyollarSummaryTest
C:\php84\php.exe vendor/bin/phpunit
```

- [ ] **Қадам 7: Мутация исботи**

1. Яшириш шартини олиб ташла (`suppressed` ҳеч қачон true бўлмасин) →
   `test_sensitive_flag_below_threshold_is_suppressed` **йиқилиши шарт**.
2. `ExecutiveScope::mahalla()` ўрнига `Mahalla::findOrFail()` қўй →
   `test_tuman_cannot_read_summary_for_another_district` **йиқилиши шарт**.

Иккаласини қайтар, натижани ҳисоботда ёз.

- [ ] **Қадам 8: Коммит** — `feat(mahalla): ayollar jamlanmasi — rahbar paneli uchun (faqat agregat)`

---

### Task M1: Жонли «яқиндаги объектлар»

**Репо:** `D:\kadr\mahalla_mobile`, тармоқ `feature/kontekst-panel`, база `main`.

**Файллар:** Яратиш `lib/features/map/nearest_objects.dart`; тест `test/nearest_objects_test.dart`

**Беради:**
```dart
/// Нуқталарни ЖОРИЙ GPS позициясидан қайта ҳисоблаб тартиблайди.
///
/// НЕГА клиентда: сервер `distance_m` ни СЎРОВ пайтидаги марказдан беради,
/// кейинги сўровгача эса 750 м (радиус×0.25) юрилиши мумкин. Оралиқда
/// серверники ёлғон бўлади. Бу функция ҳар GPS тикида қайта ҳисоблайди.
List<NearestObject> nearestFrom({
  required List<NearbyPoint> points,
  required double lat,
  required double lng,
  int limit = 5,
});

class NearestObject {
  final NearbyPoint point;
  final double distanceM; // ҚАЙТА ҲИСОБЛАНГАН, серверники эмас
}
```

`distanceMeters()` — `lib/core/geo/haversine.dart` дан. Уни ўзгартирма.

Тестлар:
- масофа бўйича тартиб тўғри
- `limit` ҳурмат қилинади
- **сервер `distance_m` эътиборга олинмайди** — атайлаб нотўғри `distance_m` берилган нуқта ҳам тўғри жойда турсин (бу тестсиз функция маъносини йўқотади)
- бўш рўйхат → бўш натижа
- бир хил масофадаги иккита нуқта барқарор тартибда (`id` бўйича)

**Мутация:** `distanceMeters` ўрнига `p.distanceM` (серверники) қўй → «сервер эътиборга олинмайди» тести йиқилсин.

Коммит: `feat(mobil): yaqindagi obyektlar — masofa klientda qayta hisoblanadi`

---

### Task M2: Панел маълумот қатлами

**Файллар:** Яратиш `lib/features/rahbar/ayollar_models.dart`, `lib/features/rahbar/ayollar_repository.dart`;
тест `test/ayollar_models_test.dart`

**Эндпойнт:** `GET /api/mahalla/executive/mahallas/{id}/ayollar-summary` (Task B1)

Модел `AyollarSummary`: `mahalla`, `available`, `started`, `period`, `balance{total,green,yellow,red,status}`,
`flags[{code,label,count,suppressed}]`, `urgentTotal`.

> `count` **nullable** (яширилганда `null`). UI да `suppressed` бўлса `«<5»`, `null` ва
> `!suppressed` бўлса `«—»`, акс ҳолда сон. Уччаласи ҳар хил маъно — аралаштирма.

Репозиторий `worklist_repository.dart` намунасида; 404 → «қамровингизда эмас».

**Контракт файли:** эндпойнт деплой қилингандан кейин ҳақиқий жавобни
`.superpowers/sdd/kontrakt/ayollar_summary.json` га сақла ва тестлар **ўша файлга** қарши ёзилсин
(олдинги вазифаларда тахминга қарши ёзиш 12 та номувофиқликка олиб келган).

Коммит: `feat(mobil): ayollar jamlanmasi — model va repozitoriy`

---

### Task M3: Контекст панели виджети

**Файллар:** Яратиш `lib/features/map/context_panel.dart`; тест `test/context_panel_test.dart`

**Беради:** `ContextPanel({required MahallaRef? mahalla, required List<NearestObject> nearest, ...})`

Блоклар (дизайн §3.2 тартибида): сарлавҳа+📌 · ДИҚҚАТ (шартли) · ЯҚИНДАГИ ОБЪЕКТЛАР ·
ПАСПОРТ · МИКРО-ЛОЙИҲАЛАР · АЁЛЛАР ХАТЛОВИ.

**Бўш ҳолатлар — масъулни номлаб:**
| Ҳолат | Матн |
|---|---|
| Лойиҳа йўқ | «Қайд этилмаган» + «Масъул: ҳоким ёрдамчиси» |
| Хатлов бошланмаган | «Хатлов бошланмаган» |
| Аёллар схемаси йўқ | «Маълумот мавжуд эмас» |
| Маҳалла аниқланмади | «Маҳалла аниқланмади — харитада жойлашувни кутинг» |

**ДИҚҚАТ блоки:** сигналлар бўлмаса **умуман рендер қилинмасин** (бўш карточка ҳам эмас).
Тест билан қулфла.

**Лойиҳа статуслари учун кирилл ёрлиқлар** (backend бермайди, мобил аниқлайди):
`planned` → Режалаштирилган · `in_progress` → Жараёнда · `done` → Якунланган · `cancelled` → Бекор қилинган

Тестлар: ДИҚҚАТ бўш бўлса йўқ · бўш ҳолатларда масъул номи бор ·
`suppressed` байроқ «<5» кўрсатади, аниқ сон эмас · `count=null && !suppressed` → «—».

Коммит: `feat(mobil): kontekst paneli vidjeti`

---

### Task M4: Харитага улаш (responsive) + маҳалла алмашиши

**Файллар:** Ўзгартириш `lib/features/map/map_screen.dart`; тест `test/context_panel_layout_test.dart`

- [ ] Экран эни ≥ 900 dp → `Row([SizedBox(width: 340, child: ContextPanel), Expanded(map)])`
- [ ] < 900 dp → `Stack([map, DraggableScrollableSheet(0.18 / 0.5 / 0.9)])`
- [ ] 📌 қотириш: босилганда панел GPS билан алмашмайди
- [ ] Маҳалла алмашганда: панел янгиланади + `SnackBar` «Сиз {ном}га кирдингиз»
- [ ] Панел ичидаги объектга босилса → харита ўша нуқтага марказлашади

**Тестлар:** 1400 dp да ён панел бор, 400 dp да йўқ (варақ бор) · 📌 қотирилганда
маҳалла ўзгарса ҳам панел эскисини кўрсатади.

> `map_screen.dart` ҳозир 689 қатор. Бу вазифадан кейин 800 дан ошса — панел мантиғини
> алоҳида файлга чиқар (`map_scaffold.dart`), виджет дарахтини бир файлга тиқма.

**Import тақиқи ўзгармайди:** `features/map/` `features/house|capture|upload|worklist` дан
import қилмайди. Мавжуд `test/map_import_ban_test.dart` ва `test/readonly_guarantee_test.dart`
ўтиши шарт.

Коммит: `feat(mobil): kontekst paneli xaritaga ulandi (planshet/telefon)`
