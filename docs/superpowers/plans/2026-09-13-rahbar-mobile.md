# Раҳбар режими — мобил амалга ошириш режаси (2-таҳрир)

> **Агент ишчилар учун:** МАЖБУРИЙ КИЧИК КЎНИКМА: `superpowers:subagent-driven-development`.
> Қадамлар `- [ ]` белгиси билан кузатилади.

> **2-таҳрир изоҳи.** 1-таҳрир pre-flight кўригида 2 КРИТИК + 6 ЮҚОРИ камчилик
> топилди ва режа қайта ёзилди. Энг муҳими: `readOnly: bool` билан «фақат кўриш»
> кафолати **ёлғон** эди — import занжири `rahbar_shell → map_screen →
> worklist_screen (zoneStatusColor) → house_screen → capture_screen` орқали
> камера коди раҳбар дарахтига транзитив кирарди, кафолат тести эса буни
> кўрмасди. Ечим: навигация callback инъекцияси + `zoneStatusColor` ни кўчириш.

**Мақсад:** `mahalla_mobile` иловасига битта умумий харита қатламини қуриш,
устига раҳбар (фақат кўриш) қобиғини қўйиш.

**Технология:** Flutter 3.44 / Dart 3.12 (`C:\flutter\bin\flutter.bat` — PATH да ЙЎҚ),
Riverpod 3.3.2, Dio, `flutter_map` 8.3.2 + FMTC 10.1.1 + `flutter_map_marker_cluster` 8.2.2
+ `latlong2` 0.9.1.

**Дизайн ҳужжатлари:**
- `docs/superpowers/specs/2026-09-13-rahbar-rejimi-design.md`
- `docs/superpowers/specs/2026-09-13-mahalla-mobile-nearby-map-design.md` §4

---

## ⛔ Backend боғлиқлиги

**6- ва 7-вазифалар backend `ExecutiveScope` иши тугамагунча БОШЛАНМАЙДИ.**

Ҳозир `MahallaAccess::VIEWER_ROLES` да `tuman` бор, лекин 8 та executive
контроллери туманни **умуман текширмайди** — `DistrictDashboardController`
`District::on('master')->findOrFail($district)` қилади, қамров текшируви йўқ.
Яъни 7-вазифадаги «бошқа туман сўралса 403» тести ҳозир **ҳеч қачон ўтмайди**.

Боғлиқ иш: `docs/superpowers/plans/2026-09-13-tuman-viewer-role.md` 2–4 вазифалар
(тармоқ `feature/mahalla-tuman-role`). 1–5 мобил вазифалар бунга боғлиқ ЭМАС —
улар `/nearby` ва `/boundary` дан фойдаланади, иккаласи ҳам production'да жонли.

---

## Глобал чекловлар

1. **Flutter PATH да йўқ.** Ҳамма буйруқда тўлиқ йўл: `C:\flutter\bin\flutter.bat`.
   Репо: `D:\kadr\mahalla_mobile`, базавий коммит `750b646`, тармоқ `main`.
2. **`lib/features/capture/` ва `lib/features/upload/` ичига ТЕГИЛМАЙДИ.**
   Бу алдовга қарши йўл: жонли камера, геофенс, сурат сифати, зонага 3 ракурс.
   Дизайн §4.4 `capture_screen.dart` дан GPS ни ажратишни таклиф қилади —
   **БУ РЕЖА УНДАН АТАЙЛАБ ЧЕКИНАДИ** (у ердаги код виджет ҳолатига чуқур
   боғланган; кўчириш механик эмас). Харита ўз GPS провайдерини олади.
3. **`HomeShell` ва депутат оқими 8-вазифагача ўзгармайди**, `zoneStatusColor`
   кўчирилишидан ташқари (1-вазифа, соф refactor).
4. Ҳар вазифадан кейин: `flutter analyze` — 0 хато; `flutter test` — яшил.
5. Коммит хабарлари ўзбекча: `feat(mobil):` / `test(mobil):` / `refactor(mobil):`.
   **Аттрибуция қаторлари қўшилмайди.**
6. Матнлар **кирилл ўзбекчада**. Ранглар — фақат `AppColors`.

## Мавжуд API (кўрик билан ТАСДИҚЛАНГАН имзолар)

Буларни қайта текширишга ҳожат йўқ — pre-flight кўригида файл-қатор билан тасдиқланган:

| Символ | Ҳақиқий имзо | Жойи |
|---|---|---|
| `localCacheProvider` | `Provider<LocalCache>` (`keepAlive`) | `lib/core/local_cache.dart:62` |
| `saveJson` | `Future<void> saveJson(String key, Object json)` | `local_cache.dart:28` |
| `loadJson` | `Future<Map<String,dynamic>?> loadJson(String key)` | `local_cache.dart:41` |
| `dioProvider` | `Provider<Dio>` | `lib/core/api/api_providers.dart:34` |
| `mapDioError` | `ApiException mapDioError(DioException e)` | `lib/core/api/api_error.dart:12` |
| `distanceMeters` | `double distanceMeters(double lat1, double lng1, double lat2, double lng2)` | `lib/core/geo/haversine.dart:12` |
| `zoneStatusColor` | `Color zoneStatusColor(String status)` — **NON-nullable** | `lib/features/worklist/worklist_screen.dart:538` |
| `MahallaContext.role` | `final String? role` | `lib/features/auth/models.dart:55` |
| `AuthState.context` | `MahallaContext?` | `lib/features/auth/auth_controller.dart:42` |
| `WorklistData.stale` | майдон номи **`stale`**, `asStale()` | `lib/features/worklist/models.dart:112` |

`MapPrefsNotifier.build()` ичида `await` қилинмаган `_restore()` — **шу репонинг
мавжуд намунаси** (`auth_controller.dart:51-56` айнан шундай қилади) ва хавфсиз:
`NotifierProvider` дефолтда `autoDispose` ЭМАС, `loadJson` эса барча хатоларни
ютади (`local_cache.dart:47-49`).

## Файл структураси

| Файл | Масъулияти |
|---|---|
| `lib/core/zones.dart` | **ЎЗГАРАДИ** — `zoneStatusColor` шу ерга кўчади |
| `lib/features/worklist/worklist_screen.dart` | **ЎЗГАРАДИ** — функция олиб ташланади, import қўшилади |
| `lib/features/house/house_screen.dart` | **ЎЗГАРАДИ** — import манбаси ўзгаради |
| `lib/main.dart` | **ЎЗГАРАДИ** — FMTC/ObjectBox инициализацияси |
| `lib/core/geo/live_location.dart` | ЯНГИ — жонли GPS оқими |
| `lib/features/map/map_prefs.dart` | ЯНГИ — радиус/қатламлар + `MapLayer` |
| `lib/features/map/models.dart` | ЯНГИ — `NearbyPoint`, `NearbyData`, `MahallaRef` |
| `lib/features/map/nearby_repository.dart` | ЯНГИ — `/nearby`, `/boundary` + кеш |
| `lib/features/map/tile_provider.dart` | ЯНГИ — плитка провайдери (тестда алмаштирилади) |
| `lib/features/map/nearby_controller.dart` | ЯНГИ — GPS × радиус × қатлам |
| `lib/features/map/map_screen.dart` | ЯНГИ — `flutter_map` экрани |
| `lib/features/map/point_sheet.dart` | ЯНГИ — нуқта картаси |
| `lib/features/rahbar/rahbar_shell.dart` | ЯНГИ — раҳбар қобиғи (4 таб) |
| `lib/features/rahbar/mahalla_tab.dart` | ЯНГИ — маҳалла паспорти |
| `lib/features/rahbar/district_tab.dart` | ЯНГИ — туман рейтинги |
| `lib/features/rahbar/settings_tab.dart` | ЯНГИ — радиус, чиқиш |
| `lib/features/rahbar/executive_*.dart` | ЯНГИ — executive модел/репозиторий |

---

### Task 1: `zoneStatusColor` ни кўчириш + пакетлар + FMTC инициализацияси

> Бу вазифа **read-only кафолатининг пойдевори**. `zoneStatusColor`
> `worklist_screen.dart` да турганича қолса, ундан фойдаланган ҳар қандай файл
> `worklist_screen → house_screen → capture_screen` занжирини тортиб келади.

**Файллар:**
- Ўзгартириш: `lib/core/zones.dart` (функция кўчиб келади)
- Ўзгартириш: `lib/features/worklist/worklist_screen.dart` (функция кетади, import келади)
- Ўзгартириш: `lib/features/house/house_screen.dart` (import манбаси)
- Ўзгартириш: `pubspec.yaml`, `lib/main.dart`
- Тест: `test/zone_status_color_test.dart`

**Интерфейслар (кейинги вазифалар шуларга таянади):**
- `Color zoneStatusColor(String? status)` — **энди nullable қабул қилади**,
  `null` → `AppColors.danger` (яъни «бошланмаган»). Сабаб: `/nearby` жавобида
  `overall_status` null бўлиши мумкин, эски non-nullable имзо компиляция хатоси берарди.

- [ ] **Қадам 1: Йиқиладиган тест ёзиш**

`test/zone_status_color_test.dart`:

```dart
import 'package:flutter_test/flutter_test.dart';
import 'package:mahalla/core/zones.dart';
import 'package:mahalla/ui/theme.dart';

void main() {
  test('null статус — бошланмаган деб қаралади', () {
    expect(zoneStatusColor(null), AppColors.danger,
        reason: '/nearby да overall_status null бўлиши мумкин');
  });

  test('маълум статуслар ўз рангини беради', () {
    // Мавжуд worklist_screen.dart:538 даги switch ни ЎЗГАРТИРМАСДАН кўчир,
    // сўнг бу тестни ундаги ҳақиқий case'лар билан тўлдир.
  });
}
```

> Аввал `worklist_screen.dart:538` даги `switch` ни ЎҚИ ва иккинчи тестни
> ундаги ҳақиқий `case` қийматлари билан тўлдир. Мантиқни ўзгартирма — фақат
> кўчир ва имзони `String?` қил.

- [ ] **Қадам 2: Йиқилишини кўриш** — `flutter test test/zone_status_color_test.dart`

- [ ] **Қадам 3: Кўчириш**

`lib/core/zones.dart` га функцияни кўчир (`AppColors` import билан).
`worklist_screen.dart` дан ўчир, `import '../../core/zones.dart';` борлигини
текшир (эҳтимол аллақачон бор). `house_screen.dart:9` даги
`import '...worklist_screen.dart' show zoneStatusColor;` ни `core/zones.dart` га ўзгартир.

- [ ] **Қадам 4: Тест + analyze + мавжуд тестлар**

```
C:\flutter\bin\flutter.bat analyze
C:\flutter\bin\flutter.bat test
```
Кутилган: ҳаммаси яшил (бу соф refactor — хулқ ўзгармайди).

- [ ] **Қадам 5: Пакетлар — БИТТА буйруқда**

```
C:\flutter\bin\flutter.bat pub add flutter_map latlong2 flutter_map_tile_caching flutter_map_marker_cluster
```

> **Алоҳида-алоҳида қўшма.** `latlong2` ни биринчи ёки ёлғиз қўшсанг pub
> `^0.10.1` ёзади, кейин cluster (у `^0.9.1` талаб қилади) йиқилади.
> Кутилган пинлар: `flutter_map 8.3.2`, `latlong2 ^0.9.1`,
> `flutter_map_tile_caching 10.1.1`, `flutter_map_marker_cluster 8.2.2`.

- [ ] **Қадам 6: FMTC инициализацияси (`main.dart`)**

FMTC 10.1.1 ObjectBox'га таянади ва ишлатилишидан ОЛДИН инициализация талаб қилади.
Ҳозирги `lib/main.dart:8-10` — `void main() { runApp(...); }`, `ensureInitialized` ҳам йўқ.

```dart
Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  await FMTCObjectBoxBackend().initialise();
  await FMTCStore('osm').manage.create();
  runApp(const ProviderScope(child: MahallaApp()));
}
```

> Аниқ API номларини ўрнатилган FMTC 10.1.1 нинг ўз README/example'идан текшир —
> юқоридагиси намуна. Хато бўлса кодни тузат, тестни эмас.

- [ ] **Қадам 7: Плитка провайдерини инъекция қилинадиган қилиш**

`lib/features/map/tile_provider.dart`:

```dart
/// Плитка провайдери — ЖОНЛИ кодда FMTC кеши, ТЕСТДА оддий тармоқ/заглушка.
///
/// Нега провайдер орқали: FMTC ObjectBox'нинг нативе кутубхонасини талаб
/// қилади, у эса `flutter test` муҳитида умуман йўқ. Тўғридан-тўғри
/// ишлатилса харита виджет тестлари портлайди.
final mapTileProvider = Provider<TileProvider>((ref) => /* FMTC store provider */);
```

Виджет тестларида `overrideWithValue(NetworkTileProvider())` билан алмаштирилади.

- [ ] **Қадам 8: `capture/` ва `upload/` тегилмаганини ИСБОТЛАШ**

```
git diff --stat HEAD -- lib/features/capture/ lib/features/upload/
```
Кутилган: **бўш чиқиш**.

- [ ] **Қадам 9: Коммит**

```bash
git add -A
git commit -m "refactor(mobil): zoneStatusColor core/zones ga ko'chirildi + xarita paketlari va FMTC init"
```

---

### Task 2: Созламалар (`map_prefs.dart`)

**Файллар:** Яратиш `lib/features/map/map_prefs.dart`; тест `test/map_prefs_test.dart`

**Интерфейслар:**
- `enum MapLayer { monitoring('monitoring'), org('org'), home('home') }` —
  **фақат шу файлда** эълон қилинади
- `class MapPrefs { int radiusM; Set<MapLayer> layers; }`,
  `MapPrefs.initial` (3000 м, `{monitoring, org}`),
  `MapPrefs.radiusOptions = [500, 1000, 2000, 3000, 5000]`,
  `layersParam`, `withRadius`, `toggleLayer`, `toJson`/`fromJson`, `==`/`hashCode`
- `mapPrefsProvider` → `NotifierProvider<MapPrefsNotifier, MapPrefs>`

> **Икки қарор — изоҳда ёзилсин, акс ҳолда кўрувчи «тузатиб» юборади:**
> 1. **Уч қатлам, тўрт эмас.** Дизайн §5.4 тўртта чип санайди (Ижтимоий ·
>    Ташкилотлар · Уйлар · Мониторинг), лекин сервер `NearbyController::parseLayers`
>    фақат `monitoring|home|org` ни танийди; `is_social` — қатлам эмас,
>    ҳар нуқтанинг майдони. Шунинг учун 3 қатлам + `is_social` бўйича визуал фарқ.
> 2. **`home` дефолтда ЎЧИҚ.** Шовотда 36 268 бинодан 34 651 таси таснифланмаган
>    турар-жой — ёқилса харита ўқиб бўлмас ҳолга келади.
> 3. Радиус тўплами `[500, 1000, 2000, 3000, 5000]` — дизайн §5.4 («0.5/1/3/5»)
>    ва §4.7 («1–5, қадам 0.5») зид эди; сервер `between:200,5000` қабул қилади.

- [ ] **Қадам 1: Тест ёзиш** — дефолт қийматлар, радиус тўплами, `toggleLayer`
  нусха қайтариши, `layersParam` вергул билан, JSON айланма, нотўғри сақланган
  радиус дефолтга қайтиши
- [ ] **Қадам 2: Йиқилишини кўриш**
- [ ] **Қадам 3: Ёзиш**
- [ ] **Қадам 4: Ўтказиш**
- [ ] **Қадам 5: Коммит** — `feat(mobil): xarita sozlamalari (radius, qatlamlar)`

---

### Task 3: Атроф моделлари ва репозиторий

**Файллар:** Яратиш `lib/features/map/models.dart`, `nearby_repository.dart`;
тест `test/nearby_models_test.dart`

**Интерфейслар:**
- `NearbyPoint` — `id, lat, lng, distanceM, kind, type, isSocial, category,
  categoryLabel, address, kadastr, houseNumber, street, mahalla, monitored,
  overallStatus (String?), mine (bool)`
- `NearbyData` — `center, radiusM, currentMahalla (MahallaRef?), counts, points,
  stale (bool)`; `asStale()` — **майдон номи `stale`**, `worklist/models.dart:112` каби
- `nearbyRepositoryProvider` → `getNearby({lat, lng, radiusM, layersParam})`,
  `getBoundary(mahallaId)`

**Сервер контракти (кўрик билан майдон-майдон тасдиқланган):**

```json
{
  "center": {"lat": 41.62, "lng": 60.38},
  "radius_m": 3000,
  "current_mahalla": {"id": "uuid", "name": "ТУПРОҚҚАЛЪА МФЙ"},
  "counts": {"monitoring": 0, "home": 0, "org": 12, "returned": 12, "truncated": false},
  "points": [{
    "id": "uuid", "lat": 41.6, "lng": 60.3, "distance_m": 412,
    "kind": "org", "type": "non_residential", "is_social": true,
    "category": "maktab", "category_label": "Мактаб",
    "address": "...", "kadastr": "22:06:...", "house_number": "12",
    "street": "...", "mahalla": "...",
    "monitored": false, "overall_status": null, "mine": false
  }]
}
```

`current_mahalla` ва `overall_status` **null бўлиши мумкин** — иккаласи ҳам
nullable, тест билан қулфлансин.

**`limit` ЮБОРИЛМАЙДИ** — сервер дефолти 600, клиент буни белгиламайди.

**`GET /mahallas/{id}/boundary` контракти** (`NearbyFinder::boundaryGeoJson` дан
олинган) — GeoJSON **Feature**, `FeatureCollection` ЭМАС:

```json
{
  "type": "Feature",
  "properties": {"id": "uuid", "name": "ТУПРОҚҚАЛЪА МФЙ"},
  "geometry": {"type": "MultiPolygon", "coordinates": [[[[60.38, 41.62], ...]]]}
}
```

- Координаталар **[lng, lat]** тартибида (GeoJSON стандарти) — `latlong2`
  `LatLng(lat, lng)` кутади, яъни **алмаштириш керак**. Бу классик хато манбаи.
- Чегара `ST_SimplifyPreserveTopology(..., 0.0003)` билан соддалаштирилган (~33 м).
- Маҳалла топилмаса ёки қамровдан ташқарида бўлса — **404** (жавоб танаси эмас).
- `geometry` `MultiPolygon` ҳам, `Polygon` ҳам бўлиши мумкин — иккаласини ҳам қўллаб-қувватла.

Репозиторий намунаси `worklist_repository.dart` билан бир хил:
`ref.read(dioProvider).get(...)` → `Map<String,dynamic>.from(res.data as Map)` →
`fromJson` → кешга ёзиш → `on DioException catch` ичида тармоқ хатосида
кешдан `.asStale()`, акс ҳолда `throw mapDioError(e)`.

> `mapDioError` **`DioException`** қабул қилади, `Object` эмас — уни фақат
> `on DioException catch (e)` блокида чақир.

Кеш калитлари: `nearby_last` (битта, марказга боғлиқ эмас — акс ҳолда кеш
чексиз ўсади), `boundary_{mahallaId}`.

- [ ] **Қадам 1: Тест ёзиш** — тўлиқ жавоб, `current_mahalla: null`,
  `overall_status: null`, `truncated: true`, масофа тартиби сақланиши, `asStale()`
- [ ] **Қадам 2-4: Йиқилиш → ёзиш → ўтиш**
- [ ] **Қадам 5: Коммит** — `feat(mobil): atrof — modellar va repozitoriy`

---

### Task 4: `nearby_controller` — GPS × радиус × қатлам

**Файллар:** Яратиш `lib/features/map/nearby_controller.dart`;
тест `test/nearby_controller_test.dart`

**Интерфейслар:**
- `nearbyControllerProvider` → **`AsyncNotifierProvider.autoDispose<NearbyController, NearbyData?>`**
- `bool shouldRefetch(double? lastLat, double? lastLng, double lat, double lng, int radiusM)`
  — **соф функция, `LatLng` ЭМАС, `double` лар қабул қилади**

> **Икки мажбурий деталь (кўрик топди):**
> 1. **`.autoDispose` ШАРТ.** Riverpod 3.3.2 да `AsyncNotifierProvider` дефолтда
>    `keepAlive` (`orphan.dart:64`). Keep-alive провайдер `liveLocationProvider`
>    (autoDispose) ни ушлаб турса, таб ёпилгандан кейин ҳам **GPS процесс
>    ўлгунча ишлайверади** — дизайн §4.4 нинг тўғридан-тўғри бузилиши.
> 2. **GPS `ref.watch` БИЛАН ОЛИНМАЙДИ.** `build()` ичида `ref.watch(liveLocationProvider)`
>    ҳар GPS тикида (ҳар 12 м) `build()` ни қайта ишга туширади ва дебаунс
>    таймерини ҳар сафар йўқ қилади — дебаунс ҳеч қачон ишламайди.
>    `build()` да фақат `ref.watch(mapPrefsProvider)`; GPS эса
>    `ref.listen(liveLocationProvider, ...)` орқали, таймер notifier майдонида,
>    `ref.onDispose` да бекор қилинади.

**Сиёсат:** марказдан `радиус × 0.25` дан узоқлашса → сўров; радиус/қатлам
ўзгарса → дарҳол; дебаунс 2.5 сония; `boundary` ФАҚАТ `currentMahalla.id`
ўзгарганда.

Масофа — `distanceMeters(lat1, lng1, lat2, lng2)` (`lib/core/geo/haversine.dart`).
`haversine.dart` `latlong2` ни import қилмайди ва қилмаслиги керак.

- [ ] **Қадам 1: Соф функцияга тест ёзиш**

```dart
test('радиуснинг чорагидан кам силжиш — сўров ЙЎҚ', () {
  expect(shouldRefetch(41.6, 60.3, 41.6, 60.3, 3000), isFalse);
});

test('радиуснинг чорагидан кўп силжиш — сўров БОР', () {
  // ~1000.8 м (kEarthRadiusM=6371000 бўйича) > 750
  expect(shouldRefetch(41.6, 60.3, 41.609, 60.3, 3000), isTrue);
});

test('олдинги марказ йўқ — албатта сўров', () {
  expect(shouldRefetch(null, null, 41.6, 60.3, 3000), isTrue);
});

test('чегара радиусга БОҒЛИҚ', () {
  // ~300.2 м силжиш: 1000 м радиусда (чегара 250) сўров бор,
  // 3000 м радиусда (чегара 750) йўқ.
  expect(shouldRefetch(41.6, 60.3, 41.6027, 60.3, 1000), isTrue);
  expect(shouldRefetch(41.6, 60.3, 41.6027, 60.3, 3000), isFalse);
});
```

> Охирги тест ЭНГ МУҲИМИ — чегара қотирилмаганини исботлайди.

- [ ] **Қадам 2-4: Йиқилиш → ёзиш → ўтиш**
- [ ] **Қадам 5: Мутация** — `radiusM * 0.25` ни `750` га қотир → охирги тест
  **йиқилиши ШАРТ**. Қайтар, натижани ҳисоботда ёз.
- [ ] **Қадам 6: Коммит** — `feat(mobil): atrof — qayta so'rov nazorati`

---

### Task 5: Жонли GPS провайдери

**Файллар:** Яратиш `lib/core/geo/live_location.dart`; тест `test/live_location_test.dart`

**Интерфейслар:**
- `enum LocationFailure { serviceOff, denied, deniedForever, timedOut, error }`
- `class LocationState { Position? position; LocationFailure? failure; String? detail; bool get hasFix; }`
- `liveLocationProvider` → `StreamProvider.autoDispose<LocationState>`

> **`async*` ИШЛАТИЛМАЙДИ** (кўрик хулосаси). Уч сабаб: (а) `await for` да
> тўхтаган генератор таймер callback'идан `yield` қила олмайди — «20 сониядан
> кейин timedOut, лекин оқим тирик» умуман ёзиб бўлмайди; (б) ҳар нosozликда
> `yield ...; return;` оқимни ЁПАДИ, яъни фойдаланувчи GPS ни ёқса ҳам
> тикланмайди; (в) циклдан кейинги `if (!gotFix)` — ўлик код.
>
> **Нosozликлар хато сифатида эмас, МАЪЛУМОТ сифатида чиқарилади.**
> `addError` провайдерни `AsyncValue.error` га туширади ва охирги фиксни
> экрандан йўқотади — харита учун бу нотўғри.

```dart
final liveLocationProvider = StreamProvider.autoDispose<LocationState>((ref) {
  final out = StreamController<LocationState>();
  StreamSubscription<Position>? sub;
  Timer? firstFix;
  var gotFix = false;

  Future<void> start() async {
    if (!await Geolocator.isLocationServiceEnabled()) {
      out.add(const LocationState(failure: LocationFailure.serviceOff));
      return; // оқим ОЧИҚ қолади — тикланиши мумкин
    }
    var perm = await Geolocator.checkPermission();
    if (perm == LocationPermission.denied) perm = await Geolocator.requestPermission();
    if (perm == LocationPermission.denied) {
      out.add(const LocationState(failure: LocationFailure.denied));
      return;
    }
    if (perm == LocationPermission.deniedForever) {
      out.add(const LocationState(failure: LocationFailure.deniedForever));
      return;
    }

    firstFix = Timer(const Duration(seconds: 20), () {
      if (!gotFix && !out.isClosed) {
        out.add(const LocationState(failure: LocationFailure.timedOut));
      }
    });

    sub = Geolocator.getPositionStream(
      locationSettings: const LocationSettings(
        accuracy: LocationAccuracy.high,
        distanceFilter: 12, // юриш учун; capture'да 2 — у ерга ТЕГИЛМАЙДИ
      ),
    ).listen(
      (p) {
        gotFix = true;
        firstFix?.cancel();
        if (!out.isClosed) out.add(LocationState(position: p));
      },
      onError: (Object e) {
        if (!out.isClosed) {
          out.add(LocationState(failure: LocationFailure.error, detail: '$e'));
        }
      },
    );
  }

  start();
  ref.onDispose(() {
    firstFix?.cancel();
    sub?.cancel();
    out.close();
  });
  return out.stream;
});
```

- [ ] **Қадам 1: Тест уланиши (seam) ни қуриш**

`Geolocator.*` статик — тўғридан-тўғри мок қилиб бўлмайди. Мавжуд уланиш:
`GeolocatorPlatform.instance` **ўрнатилади**
(`geolocator_platform_interface-4.2.8/lib/src/geolocator_platform_interface.dart:35`).
Тестда уни бошқариладиган `StreamController` қайтарадиган сохта реализация
билан алмаштир.

- [ ] **Қадам 2: Тест ёзиш** — камида:
  - хизмат ўчиқ → `serviceOff`, **оқим ёпилмайди**
  - рухсат рад → `denied`
  - биринчи фикс кечикса → `timedOut`, **кейин фикс келса `hasFix` га ўтади**
  - оқим хатоси → `error`, охирги позиция йўқолмайди
- [ ] **Қадам 3-4: Йиқилиш → ёзиш → ўтиш**
- [ ] **Қадам 5: `capture/` тегилмаганини исботлаш** (`git diff --stat`)

- [ ] **Қадам 6: 4-вазифадаги контроллерга улаш** ⚠️

> **Режадаги тартиб хатоси — 4-вазифа шу вазифага боғлиқ эди.** 4-вазифа
> контроллери `liveLocationProvider` ни талаб қилади, лекин у ўшанда ҳали
> мавжуд эмас эди. 4-вазифа ижрочиси GPS қабулини
> `NearbyController.reportPosition(lat, lng)` оммавий методига ажратиб,
> файл компиляция бўлишини таъминлаган — тўғри қарор.
>
> **Энди уни улаш ШУ ВАЗИФАНИНГ масъулияти.** `nearby_controller.dart`
> файлидаги кутубхона изоҳида аниқ қайси қатор қўшилиши ёзилган.

`NearbyController.build()` ичига:

```dart
    ref.listen(liveLocationProvider, (prev, next) {
      final pos = next.valueOrNull?.position;
      if (pos != null) reportPosition(pos.latitude, pos.longitude);
    });
```

Уланганини тест билан тасдиқла: сохта GPS оқимига позиция берилса,
контроллер `reportPosition` ни чақиришини (ва дебаунс ишлашини) текшир.
**Улаш бажарилмаса — харита ҳеч қачон маълумот сўрамайди** ва буни ҳеч
қандай мавжуд тест ушламайди.

- [ ] **Қадам 7: Коммит** — `feat(mobil): xarita uchun jonli GPS provayderi va kontrollerga ulash`

---

### Task 6: Харита экрани

**Файллар:** Яратиш `lib/features/map/map_screen.dart`, `point_sheet.dart`;
тест `test/map_screen_test.dart`

**Интерфейс — КРИТИК:**

```dart
/// Умумий харита экрани. Иккала қобиқ ҳам шуни ишлатади.
///
/// `onOpenHouse` — навигация ИНЪЕКЦИЯСИ. `null` бўлса нуқта картасида
/// «Зона детали» тугмаси кўрсатилмайди.
///
/// НЕГА bool эмас: `readOnly: bool` билан бу файл `HouseScreen` ни import
/// қилиши керак бўларди, у эса `capture_screen.dart` ни тортади — натижада
/// камера коди раҳбар дарахтига ТРАНЗИТИВ кириб келарди ва «фақат кўриш»
/// кафолати ёлғон бўлиб қоларди. Callback билан `features/map/` ҳеч қачон
/// `features/house` ёки `features/capture` ни кўрмайди.
class NearbyMapScreen extends ConsumerStatefulWidget {
  const NearbyMapScreen({super.key, this.onOpenHouse});

  final void Function(BuildContext context, String buildingId)? onOpenHouse;
}
```

**`features/map/` дан ТАҚИҚЛАНГАН import'лар:** `features/house`,
`features/capture`, `features/upload`, `features/worklist`.
(Шунинг учун 1-вазифада `zoneStatusColor` кўчирилди.)

**Талаблар:**
- OSM плиткалар — `mapTileProvider` орқали (тестда алмаштириладиган)
- «Мен» тугмаси + follow-me; эркин сурилса follow-me ўчади
- Радиус доираси, қатлам чиплари
- Маркерлар: monitoring → `zoneStatusColor(p.overallStatus)` (**энди `String?` қабул қилади**);
  org → категория иконкаси, `is_social` ажралиб туради; home → кичик нейтрал нуқта
- Зичликда кластер
- Тепада: «Сиз ҳозир **{маҳалла}**да» + `counts`; маҳалла йўқ бўлса — «Маҳалла аниқланмади»
- Маҳалла чегараси — пунктир контур
- `truncated` → «Нуқталар кўп, радиусни кичрайтиринг»
- `stale` → мавжуд намунадаги баннер
- `LocationFailure` бўйича аниқ матн + «Созламаларни очиш»

- [ ] **Қадам 1: Ёзиш**
- [ ] **Қадам 2: `analyze` тоза**
- [ ] **Қадам 3: Import тақиқини тест билан қулфлаш**

```dart
test('features/map house/capture/upload/worklist дан import қилмайди', () {
  final dir = Directory('lib/features/map');
  if (!dir.existsSync()) fail('lib/features/map топилмади');

  final banned = ['features/house', 'features/capture', 'features/upload', 'features/worklist'];
  final offenders = <String>[];

  for (final f in dir.listSync(recursive: true).whereType<File>()) {
    if (!f.path.endsWith('.dart')) continue;
    final src = f.readAsStringSync();
    for (final b in banned) {
      if (src.contains(b)) offenders.add('${f.path} → $b');
    }
  }

  expect(offenders, isEmpty,
      reason: 'харита қатлами қобиқлардан мустақил бўлиши керак');
});
```

- [ ] **Қадам 4: Виджет тести** — `mapTileProvider` ни `NetworkTileProvider()`
  билан алмаштириб, `onOpenHouse: null` да «Зона детали» тугмаси ЙЎҚлигини,
  берилганда БОРлигини текшир
- [ ] **Қадам 5: Эмуляторда қўлда кўриш** — скриншот ҳисоботга
- [ ] **Қадам 6: Коммит** — `feat(mobil): atrof xaritasi`

---

### Task 7: Раҳбар қобиғи + роль бўйича ажратиш

**Файллар:**
- Ўзгартириш: `lib/features/auth/auth_gate.dart`
- Яратиш: `lib/features/rahbar/rahbar_shell.dart`, `settings_tab.dart`
- Тест: `test/rahbar_shell_test.dart`, `test/readonly_guarantee_test.dart`

**Интерфейслар:** `bool isRahbarRole(String? role)`; `RahbarShell`

**4 таб** (дизайн §5.4 — 1-таҳрирда хато билан 3 та эди):
`Атроф` (`NearbyMapScreen()` — `onOpenHouse` БЕРИЛМАЙДИ) · `Маҳалла` · `Туман` · `Созламалар`

> **«Созламалар» таби ШАРТ.** Чиқиш (logout) фақат `dashboard_screen.dart:40`
> ва `worklist_screen.dart:72` да бор — иккаласи ҳам `HomeShell` ичида.
> Усиз раҳбар сессиядан **умуман чиқа олмайди**.

- [ ] **Қадам 1: Тест ёзиш**

```dart
test('раҳбар роллари аниқланади', () {
  expect(isRahbarRole('tuman'), isTrue);
  expect(isRahbarRole('viloyat'), isTrue);
  expect(isRahbarRole('admin'), isTrue);

  expect(isRahbarRole('deputat'), isFalse);
  expect(isRahbarRole('rais'), isFalse);
  expect(isRahbarRole('hokim-yordamchisi'), isFalse);
  expect(isRahbarRole(null), isFalse,
      reason: 'роли номаълум — раҳбар қобиғига тушмайди (fail-closed)');
  expect(isRahbarRole(''), isFalse);
  expect(isRahbarRole('TUMAN'), isFalse,
      reason: 'сервер кичик ҳарфда қайтаради');
});
```

**Read-only кафолати** (`test/readonly_guarantee_test.dart`) — **транзитив**:

```dart
// Бу тест КОД МАТНИНИ текширади. Мақсад: раҳбар дарахтига capture/upload
// коди ҲЕЧ ҚАЧОН, ТРАНЗИТИВ ҲАМ кирмаслиги.
//
// 1-таҳрирда бу тест фақат lib/features/rahbar/ ни текширарди ва
// rahbar_shell → map_screen → worklist_screen → house_screen → capture_screen
// занжирини КЎРМАСДИ. Яъни ўзи ўлчамоқчи бўлган нарсани ўлчамасди.
test('раҳбар дарахти transitive равишда capture/upload га етиб бормайди', () {
  final visited = <String>{};
  final banned = <String>[];

  void walk(String path) {
    if (!visited.add(path)) return;
    final f = File(path);
    if (!f.existsSync()) return;

    if (path.contains('features/capture') || path.contains('features/upload')) {
      banned.add(path);
      return;
    }

    final dir = f.parent.path;
    for (final m in RegExp(r"""import\s+['"]([^'"]+)['"]""").allMatches(f.readAsStringSync())) {
      final target = m.group(1)!;
      if (target.startsWith('package:') || target.startsWith('dart:')) {
        // package:mahalla/... ни ҳам кузат
        if (!target.startsWith('package:mahalla/')) continue;
        walk('lib/${target.substring("package:mahalla/".length)}');
        continue;
      }
      walk(p.normalize(p.join(dir, target)));
    }
  }

  walk('lib/features/rahbar/rahbar_shell.dart');

  expect(banned, isEmpty,
      reason: 'раҳбар фақат кўради — сурат олиш/юклаш коди етиб бормаслиги керак');
});
```

> `package:path` `dev_dependencies` да борлигини текшир; йўқ бўлса қўш.
> Бошланғич файл мавжуд эмаслигида тест `fail()` билан тушунарли хабар берсин.

- [ ] **Қадам 2: Йиқилишини кўриш**
- [ ] **Қадам 3: `RahbarShell` + роль шохи**

```dart
      case AuthStatus.authenticated:
        // Рол бўйича иккита бутунлай бошқа қобиқ. Раҳбар маълумот
        // КИРИТМАЙДИ — унга иш рўйхати ва сурат оқими кўрсатилмайди.
        return isRahbarRole(state.context?.role)
            ? const RahbarShell()
            : const HomeShell();
```

- [ ] **Қадам 4: Тестлар**
- [ ] **Қадам 5: Депутат оқими ўзгармаганини исботлаш**

```
git diff HEAD~1 -- lib/features/home/ lib/features/worklist/ lib/features/house/ lib/features/dashboard/
```
Кутилган: бўш (1-вазифадаги `zoneStatusColor` refactor аллақачон коммит қилинган).

- [ ] **Қадам 6: Коммит** — `feat(mobil): rahbar qobig'i va rol bo'yicha ajratish`

---

### Task 8: Раҳбар — «Маҳалла» паспорти

> ⛔ **Backend 2–4 вазифалари тугагунча бошланмайди.**

**Файллар:** `lib/features/rahbar/executive_models.dart`, `executive_repository.dart`,
`mahalla_tab.dart`; тест `test/executive_models_test.dart`

**Эндпойнтлар:** `executive/mahallas/{id}`, `.../obod`, `.../projects`

**Маҳалла:** `nearbyControllerProvider` даги `currentMahalla.id`; null бўлса танлагич.

> **Ҳалоллик талаби — тест билан қулфлансин.** Шовотда `employment_rate`
> 52 маҳалладан **0 тасида** бор, `population` 68/104 қаторда. Маълумот йўқ
> бўлса **«маълумот йўқ» (—)**, «0» ЭМАС. Ноль ва маълумотсизлик аралашса
> раҳбар нотўғри қарор қабул қилади.

- [ ] **Қадам 1: `null` vs `0` тести (йиқилади)**
- [ ] **Қадам 2-4: Йиқилиш → ёзиш → ўтиш**
- [ ] **Қадам 5: Коммит** — `feat(mobil): rahbar — mahalla pasporti`

---

### Task 9: Раҳбар — «Туман» рейтинги

> ⛔ **Backend 2–4 вазифалари тугагунча бошланмайди.**

**Файллар:** `lib/features/rahbar/district_tab.dart` + репозиторий/моделлар кенгайиши

**Эндпойнтлар:** `executive/districts/{id?}`, `executive/scoring/{id?}`,
`executive/district-list`

> **`district-list` ҳозир ҲАММА 13 туманни қайтаради** — `DistrictListController`
> фойдаланувчини умуман кўрмайди. Backend 3-вазифаси буни туман бўйича
> фильтрлайди. Шу тушгунча мобил томон `role == 'tuman'` бўлса танлагични
> **кўрсатмасин**.
>
> `tuman` бошқа туманни сўраса сервер **403** қайтаради (backend 3-вазифасидан
> кейин). UI буни «қамровингизда эмас» деб кўрсатсин, портламасин.

- [ ] **Қадам 1: Тест (403 ҳолати, рейтинг тартиби)**
- [ ] **Қадам 2-4: Йиқилиш → ёзиш → ўтиш**
- [ ] **Қадам 5: Коммит** — `feat(mobil): rahbar — tuman reytingi va skoring`

---

### Task 10: Депутат «Атроф» таби + якуний текширув

**Файллар:** Ўзгартириш `lib/features/home/home_shell.dart`; тест `test/home_shell_test.dart`

- [ ] **Қадам 1: Тест** — 3 та destination, учинчиси «Атроф»
- [ ] **Қадам 2: Қўшиш**

```dart
NearbyMapScreen(
  onOpenHouse: (context, buildingId) => Navigator.of(context).push(
    MaterialPageRoute(builder: (_) => HouseScreen(buildingId: buildingId)),
  ),
),
```

> `HouseScreen` нинг ҳақиқий конструктор имзосини текшир.
> Бу import **`home_shell.dart` да** бўлади — `features/map/` да ЭМАС.

- [ ] **Қадам 3: Барча тестлар + analyze**
- [ ] **Қадам 4: APK** — `C:\flutter\bin\flutter.bat build apk --release`
- [ ] **Қадам 5: Коммит** — `feat(mobil): deputat uchun «Атроф» tabi`
