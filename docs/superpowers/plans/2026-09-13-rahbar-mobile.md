# Раҳбар режими — мобил амалга ошириш режаси

> **Агент ишчилар учун:** МАЖБУРИЙ КИЧИК КЎНИКМА: `superpowers:subagent-driven-development`.
> Қадамлар `- [ ]` белгиси билан кузатилади.

**Мақсад:** `mahalla_mobile` иловасига битта умумий харита қатламини қуриш, устига
раҳбар (фақат кўриш) қобиғини қўйиш.

**Архитектура:** Харита ядроси БИР МАРТА қурилади (`lib/features/map/`), икки жойдан
ишлатилади: раҳбар қобиғида ва (охирида) депутат қобиғида. Роль бўйича ажратиш
`auth_gate.dart` да — битта `switch` шохи.

**Технология:** Flutter 3.44 / Dart 3.12 (`C:\flutter\bin\flutter.bat` — PATH да ЙЎҚ),
Riverpod 3.3.2, Dio, `flutter_map` + FMTC + `flutter_map_marker_cluster` + `latlong2`.

**Дизайн ҳужжатлари:**
- `docs/superpowers/specs/2026-09-13-rahbar-rejimi-design.md` (раҳбар режими)
- `docs/superpowers/specs/2026-09-13-mahalla-mobile-nearby-map-design.md` §4 (харита)

---

## Глобал чекловлар

Булар ҲАР БИР вазифага тегишли:

1. **Flutter PATH да йўқ.** Ҳамма буйруқда тўлиқ йўл: `C:\flutter\bin\flutter.bat`.
   Репо: `D:\kadr\mahalla_mobile` (базавий коммит `750b646`, тармоқ `main`).
2. **`lib/features/capture/` ва `lib/features/upload/` га ТЕГИЛМАЙДИ.**
   Бу алдовга қарши йўл: жонли камера, геофенс, сурат сифати, зонага 3 ракурс
   минимуми. Улардан **import ҳам қилинмайди** раҳбар дарахтида.
   Дизайн ҳужжати §4.4 `capture_screen.dart` дан GPS кодини ажратишни таклиф
   қилади — **БУ РЕЖА УНДАН АТАЙЛАБ ЧЕКИНАДИ.** Сабаб: у ердаги GPS коди виджет
   ҳолатига (`setState`, `mounted`, 5 та UI байроғи) чуқур боғланган, кўчириш
   механик эмас. Харита ўз GPS провайдерини олади.
3. **`HomeShell` ва депутат оқими 8-вазифагача ЎЗГАРМАЙДИ.**
4. **Ҳар бир вазифадан кейин:** `C:\flutter\bin\flutter.bat analyze` — 0 хато, ва
   `C:\flutter\bin\flutter.bat test` — мавжуд тестлар яшил.
5. Коммит хабарлари ўзбекча, `feat(mobil):` / `test(mobil):` / `chore(mobil):`.
   **Аттрибуция қаторлари қўшилмайди.**
6. Матнлар **кирилл ўзбекчада** (мавжуд UI билан бир хил): «Атроф», «Маҳалла»,
   «Туман», «Мен», «Яқинлаштириш».
7. Ранглар — фақат `AppColors` (`lib/ui/theme.dart`). Янги ранг ихтиро қилинмайди.

## Ҳозирги тузилиш (ўзгартирилмайдиган нуқталар)

- `lib/features/auth/auth_gate.dart:18-25` — `AuthStatus` бўйича `switch`; 6-вазифада
  шу ерга роль шохи қўшилади.
- `lib/features/auth/models.dart` — `MahallaContext.role`, `.scope.district`,
  `.permissions`. Роль `GET /api/mahalla/context` дан келади.
- `lib/core/api/dio_client.dart` — Bearer + 401 интерцептор. Ўзгармайди.
- `lib/core/local_cache.dart` — JSON диск кеши (`saveJson`/`loadJson`).
- `lib/ui/widgets/pill.dart`, `lib/core/zones.dart`, `worklist_screen.dart:538`
  `zoneStatusColor` — қайта ишлатилади.

## Файл структураси (янги)

| Файл | Масъулияти |
|---|---|
| `lib/core/geo/live_location.dart` | Жонли GPS оқими (Riverpod), харита учун |
| `lib/features/map/models.dart` | `NearbyPoint`, `NearbyData`, `MahallaRef`, `MapLayer` |
| `lib/features/map/nearby_repository.dart` | `GET /nearby`, `GET /boundary` + кеш |
| `lib/features/map/map_prefs.dart` | Радиус ва қатламлар (дискда сақланади) |
| `lib/features/map/nearby_controller.dart` | GPS × радиус × қатлам → қайта сўров |
| `lib/features/map/map_screen.dart` | `flutter_map` экрани (умумий) |
| `lib/features/map/point_sheet.dart` | Нуқта босилганда пастки карта |
| `lib/features/rahbar/rahbar_shell.dart` | Раҳбар қобиғи (3 таб) |
| `lib/features/rahbar/mahalla_tab.dart` | Маҳалла паспорти |
| `lib/features/rahbar/district_tab.dart` | Туман рейтинги/скоринги |
| `lib/features/rahbar/executive_repository.dart` | `executive/*` эндпойнтлари |
| `lib/features/rahbar/executive_models.dart` | Паспорт/рейтинг моделлари |

---

### Task 1: Пакетлар + жонли GPS провайдери + созламалар

**Файллар:**
- Ўзгартириш: `pubspec.yaml`
- Яратиш: `lib/core/geo/live_location.dart`
- Яратиш: `lib/features/map/map_prefs.dart`
- Тест: `test/live_location_test.dart`, `test/map_prefs_test.dart`

**Интерфейслар:**
- Беради (кейинги вазифалар шуларга таянади):
  - `liveLocationProvider` → `StreamProvider<LocationState>`
  - `class LocationState { Position? position; LocationFailure? failure; }`
  - `enum LocationFailure { serviceOff, denied, deniedForever, timedOut, error }`
  - `mapPrefsProvider` → `Notifier<MapPrefs>`; `class MapPrefs { int radiusM; Set<MapLayer> layers; }`
  - `MapPrefs.radiusOptions = [1000, 2000, 3000, 4000, 5000]`, дефолт `3000`

- [ ] **Қадам 1: Пакетларни қўшиш**

```
cd D:\kadr\mahalla_mobile
C:\flutter\bin\flutter.bat pub add flutter_map latlong2 flutter_map_tile_caching flutter_map_marker_cluster
```

Кутилган: тоза ечим (олдиндан `--dry-run` билан текширилган: 21 боғлиқлик
ўзгаради, зиддият йўқ). Версиялар `pubspec.yaml` да қотирилади (caret қолдирилади).

Сўнг:
```
C:\flutter\bin\flutter.bat pub get
C:\flutter\bin\flutter.bat analyze
```

- [ ] **Қадам 2: Йиқиладиган тестни ёзиш**

`test/map_prefs_test.dart`:

```dart
import 'package:flutter_test/flutter_test.dart';
import 'package:mahalla/features/map/map_prefs.dart';

void main() {
  test('дефолт радиус 3 км ва икки қатлам ёқилган', () {
    const p = MapPrefs.initial;

    expect(p.radiusM, 3000);
    expect(p.layers, contains(MapLayer.monitoring));
    expect(p.layers, contains(MapLayer.org));
    expect(p.layers.contains(MapLayer.home), isFalse,
        reson: 'уйлар қатлами дефолтда ўчиқ — 36 минг нуқта харитани кўмиб ташлайди');
  });

  test('радиус фақат рухсат этилган қийматлардан танланади', () {
    expect(MapPrefs.radiusOptions, [1000, 2000, 3000, 4000, 5000]);
    expect(MapPrefs.radiusOptions.contains(MapPrefs.initial.radiusM), isTrue);
  });

  test('қатламни ўчириб/ёқиб бўлади ва нусха қайтаради', () {
    const p = MapPrefs.initial;
    final off = p.toggleLayer(MapLayer.org);

    expect(off.layers.contains(MapLayer.org), isFalse);
    expect(p.layers.contains(MapLayer.org), isTrue, reason: 'асл объект ўзгармайди');
  });

  test('серверга юбориладиган layers сатри вергул билан ажратилади', () {
    const p = MapPrefs.initial;
    expect(p.layersParam.split(',').toSet(), {'monitoring', 'org'});
  });

  test('JSON га ёзиб-ўқиш айланма', () {
    final p = MapPrefs.initial.toggleLayer(MapLayer.home).withRadius(5000);
    expect(MapPrefs.fromJson(p.toJson()), equals(p));
  });
}
```

> **Диққат:** юқоридаги биринчи тестда `reson:` — АТАЙЛАБ эмас, хато. Уни
> `reason:` га тузат. (Бу режани кўр-кўрона кўчирмаслик учун қўйилган.)

- [ ] **Қадам 3: Тестни ишга тушириб, ЙИҚИЛИШИНИ кўриш**

```
C:\flutter\bin\flutter.bat test test/map_prefs_test.dart
```
Кутилган: компиляция хатоси — `map_prefs.dart` мавжуд эмас.

- [ ] **Қадам 4: `map_prefs.dart` ни ёзиш**

```dart
import 'dart:convert';

import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/local_cache.dart';

/// Xaritada ko'rsatiladigan nuqta qatlamlari.
///
/// Server `layers` so'rov parametrida aynan shu kodlarni kutadi
/// (`NearbyController`: monitoring | org | home).
enum MapLayer {
  monitoring('monitoring'),
  org('org'),
  home('home');

  const MapLayer(this.code);

  final String code;
}

/// Xarita sozlamalari — radius va yoqilgan qatlamlar. Diskda saqlanadi.
class MapPrefs {
  const MapPrefs({required this.radiusM, required this.layers});

  /// Boshlang'ich holat: 3 km, monitoring + tashkilotlar.
  ///
  /// `home` ATAYLAB o'chiq: Shovot tumanida 36 268 bino bor va ularning
  /// 34 651 tasi tasniflanmagan turar-joy. Ularni birdan yoqish xaritani
  /// o'qib bo'lmas holga keltiradi — foydalanuvchi kerak bo'lsa o'zi yoqadi.
  static const MapPrefs initial = MapPrefs(
    radiusM: 3000,
    layers: {MapLayer.monitoring, MapLayer.org},
  );

  /// Radius tanlovlari (metrda). Slider emas, aniq qadamlar — dala sharoitida
  /// aniq qiymat tanlash osonroq va so'rov keshi ham barqaror bo'ladi.
  static const List<int> radiusOptions = [1000, 2000, 3000, 4000, 5000];

  final int radiusM;
  final Set<MapLayer> layers;

  String get layersParam => layers.map((l) => l.code).join(',');

  MapPrefs withRadius(int m) => MapPrefs(radiusM: m, layers: layers);

  MapPrefs toggleLayer(MapLayer l) {
    final next = Set<MapLayer>.from(layers);
    next.contains(l) ? next.remove(l) : next.add(l);
    return MapPrefs(radiusM: radiusM, layers: next);
  }

  Map<String, dynamic> toJson() => {
    'radius_m': radiusM,
    'layers': layers.map((l) => l.code).toList(),
  };

  factory MapPrefs.fromJson(Map<String, dynamic> json) {
    final codes = ((json['layers'] as List?) ?? const [])
        .map((e) => e.toString())
        .toSet();
    final layers = MapLayer.values.where((l) => codes.contains(l.code)).toSet();
    final radius = (json['radius_m'] as num?)?.toInt() ?? initial.radiusM;

    return MapPrefs(
      // Noto'g'ri saqlangan qiymat bo'lsa — defaultga qaytamiz, portlamaymiz.
      radiusM: radiusOptions.contains(radius) ? radius : initial.radiusM,
      layers: layers.isEmpty ? initial.layers : layers,
    );
  }

  @override
  bool operator ==(Object other) =>
      other is MapPrefs &&
      other.radiusM == radiusM &&
      other.layers.length == layers.length &&
      other.layers.containsAll(layers);

  @override
  int get hashCode => Object.hash(radiusM, Object.hashAllUnordered(layers));
}

const String _prefsCacheKey = 'map_prefs';

class MapPrefsNotifier extends Notifier<MapPrefs> {
  @override
  MapPrefs build() {
    _restore();
    return MapPrefs.initial;
  }

  Future<void> _restore() async {
    final json = await ref.read(localCacheProvider).loadJson(_prefsCacheKey);
    if (json != null) state = MapPrefs.fromJson(json);
  }

  Future<void> setRadius(int m) async {
    state = state.withRadius(m);
    await _persist();
  }

  Future<void> toggleLayer(MapLayer l) async {
    state = state.toggleLayer(l);
    await _persist();
  }

  Future<void> _persist() =>
      ref.read(localCacheProvider).saveJson(_prefsCacheKey, state.toJson());
}

final mapPrefsProvider = NotifierProvider<MapPrefsNotifier, MapPrefs>(
  MapPrefsNotifier.new,
);
```

> `localCacheProvider` нинг ҳақиқий номи ва `saveJson`/`loadJson` имзоларини
> `lib/core/local_cache.dart` дан ТЕКШИР — юқоридагиси тахмин. Мос келмаса
> кодни мослаштир, тестни эмас.

- [ ] **Қадам 5: Тестни ўтказиш**

```
C:\flutter\bin\flutter.bat test test/map_prefs_test.dart
```
Кутилган: 5/5 ўтади (`reson:` тузатилгандан кейин).

- [ ] **Қадам 6: `live_location.dart` ни ёзиш**

```dart
import 'dart:async';

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:geolocator/geolocator.dart';

/// GPS oqimidagi nosozlik turlari — UI shu asosda nima yozishni hal qiladi.
enum LocationFailure { serviceOff, denied, deniedForever, timedOut, error }

/// Jonli lokatsiya holati: fix bor, yoki nosozlik bor.
class LocationState {
  const LocationState({this.position, this.failure, this.detail});

  final Position? position;
  final LocationFailure? failure;
  final String? detail;

  bool get hasFix => position != null;
}

/// Xarita uchun jonli GPS oqimi.
///
/// NIMA UCHUN capture_screen.dart dan AJRATILMADI: u yerdagi geolocator
/// ketma-ketligi vidjet holatiga (setState, mounted va 5 ta UI bayrog'i)
/// chuqur bog'langan; ko'chirish mexanik emas edi. capture — aldovga qarshi
/// yo'l (jonli kamera, geofens, zonaga 3 rakurs), uni qayta qurish xavfi
/// ~20 qator geolocator takroridan qimmatroq. Shuning uchun xarita o'z
/// provayderini oladi va capture_screen.dart umuman tegilmaydi.
///
/// Farqi ham bor va u ataylab: bu yerda `distanceFilter: 12` (yurish uchun,
/// batareya tejaydi), capture'da esa `2` (geofens aniqligi uchun).
final liveLocationProvider = StreamProvider.autoDispose<LocationState>((
  ref,
) async* {
  // 1) Xizmat yoqilganmi
  if (!await Geolocator.isLocationServiceEnabled()) {
    yield const LocationState(failure: LocationFailure.serviceOff);
    return;
  }

  // 2) Ruxsat
  var perm = await Geolocator.checkPermission();
  if (perm == LocationPermission.denied) {
    perm = await Geolocator.requestPermission();
  }
  if (perm == LocationPermission.denied) {
    yield const LocationState(failure: LocationFailure.denied);
    return;
  }
  if (perm == LocationPermission.deniedForever) {
    yield const LocationState(failure: LocationFailure.deniedForever);
    return;
  }

  // 3) Oqim. Tab yopilganda autoDispose oqimni bekor qiladi.
  final stream = Geolocator.getPositionStream(
    locationSettings: const LocationSettings(
      accuracy: LocationAccuracy.high,
      distanceFilter: 12,
    ),
  );

  var gotFix = false;
  // Birinchi fix uzoq kelmasa — foydalanuvchiga aytamiz, lekin oqimni
  // to'xtatmaymiz (fix keyinroq kelishi mumkin).
  final timeout = Timer(const Duration(seconds: 20), () {});

  try {
    await for (final p in stream.handleError((Object e) {
      throw e;
    })) {
      gotFix = true;
      yield LocationState(position: p);
    }
  } catch (e) {
    yield LocationState(failure: LocationFailure.error, detail: e.toString());
  } finally {
    timeout.cancel();
  }

  if (!gotFix) {
    yield const LocationState(failure: LocationFailure.timedOut);
  }
});
```

> Юқоридаги timeout мантиғи чала — `Timer` ҳеч нарса қилмайди. Уни ишлайдиган
> қилиб қайта ёз: биринчи fix 20 сонияда келмаса `LocationFailure.timedOut`
> чиқарилсин, лекин оқим давом этсин. `Stream.timeout` ёки `StreamController`
> ишлат. Тест ёз: **биринчи fix кечикса `timedOut` чиқади, кейин fix келса
> ҳолат `hasFix` га ўтади.**

- [ ] **Қадам 7: `capture_screen.dart` ўзгармаганини ИСБОТЛАШ**

```
cd D:\kadr\mahalla_mobile
git diff --stat HEAD -- lib/features/capture/ lib/features/upload/
```
Кутилган: **бўш чиқиш** (бирор қатор ҳам ўзгармаган).

```
git diff --stat HEAD
```
Кутилган: фақат `pubspec.yaml`, `pubspec.lock` ва янги файллар.

- [ ] **Қадам 8: Коммит**

```bash
C:\flutter\bin\flutter.bat analyze
C:\flutter\bin\flutter.bat test
git add -A
git commit -m "feat(mobil): xarita poydevori — paketlar, jonli GPS provayderi, sozlamalar"
```

---

### Task 2: Атроф моделлари ва репозиторий

**Файллар:**
- Яратиш: `lib/features/map/models.dart`
- Яратиш: `lib/features/map/nearby_repository.dart`
- Тест: `test/nearby_models_test.dart`

**Интерфейслар:**
- Ишлатади: `MapLayer`, `MapPrefs` (Task 1), `dioProvider`, `localCacheProvider`, `mapDioError`
- Беради:
  - `NearbyPoint` — `id, lat, lng, distanceM, kind, type, isSocial, category, categoryLabel, address, kadastr, houseNumber, street, mahalla, monitored, overallStatus, mine`
  - `NearbyData` — `center, radiusM, currentMahalla, counts, points, isStale`
  - `MahallaRef` — `id, name`
  - `nearbyRepositoryProvider` → `getNearby({lat, lng, radiusM, layers})`, `getBoundary(mahallaId)`

**Сервер контракти (ҲАҚИҚИЙ, `NearbyController` дан олинган):**

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

`current_mahalla` **null бўлиши мумкин** (нуқта ҳеч бир маҳалла чегарасида
бўлмаса). `overall_status` ҳам null бўлиши мумкин. Иккаласини ҳам моделда
nullable қил ва тест билан қулфла.

- [ ] **Қадам 1: Йиқиладиган тестни ёзиш**

`test/nearby_models_test.dart` — камида шу ҳолатлар:

```dart
test('тўлиқ жавоб парс қилинади', () { /* юқоридаги JSON */ });

test('current_mahalla null бўлса йиқилмайди', () {
  final d = NearbyData.fromJson({
    'center': {'lat': 41.0, 'lng': 60.0},
    'radius_m': 3000,
    'current_mahalla': null,
    'counts': {'returned': 0, 'truncated': false},
    'points': [],
  });
  expect(d.currentMahalla, isNull);
  expect(d.points, isEmpty);
});

test('overall_status null бўлган нуқта парс қилинади', () { /* ... */ });

test('truncated true бўлса байроқ кўтарилади', () { /* ... */ });

test('нуқталар масофа бўйича тартибда қолади', () {
  // сервер ORDER BY distance_m qaytaradi — клиент қайта тартибламайди
});

test('asStale() нусха қайтаради ва isStale=true бўлади', () { /* ... */ });
```

- [ ] **Қадам 2: Йиқилишини кўриш** — `C:\flutter\bin\flutter.bat test test/nearby_models_test.dart`

- [ ] **Қадам 3: `models.dart` ва `nearby_repository.dart` ни ёзиш**

Репозиторий намунаси **`worklist_repository.dart` билан бир хил** бўлсин:
`ref.read(dioProvider).get(...)` → `Map<String, dynamic>.from(res.data as Map)` →
`fromJson` → муваффақиятда `LocalCache.saveJson` → тармоқ хатосида `loadJson`
+ `.asStale()` → акс ҳолда `throw mapDioError(e)`.

Кеш калити **марказга боғлиқ бўлмасин** — акс ҳолда ҳар қадамда янги калит
пайдо бўлиб, кеш чексиз ўсади. Битта калит: `nearby_last`. Чегара учун
маҳалла бўйича: `boundary_{mahallaId}`.

`getNearby` сўров параметрлари: `lat`, `lng`, `radius_m`, `layers`, `limit`.
`limit` — 600 (сервер дефолти); каттароқ сўраш кераги йўқ.

- [ ] **Қадам 4: Тестларни ўтказиш**

- [ ] **Қадам 5: Коммит**
```bash
git commit -m "feat(mobil): atrof — modellar va repozitoriy (kesh bilan)"
```

---

### Task 3: `nearby_controller` — GPS × радиус × қатлам

**Файллар:**
- Яратиш: `lib/features/map/nearby_controller.dart`
- Тест: `test/nearby_controller_test.dart`

**Интерфейслар:**
- Ишлатади: `liveLocationProvider`, `mapPrefsProvider`, `nearbyRepositoryProvider`
- Беради: `nearbyControllerProvider` → `AsyncNotifier<NearbyData?>`;
  `shouldRefetch(LatLng? last, LatLng now, int radiusM)` — **соф функция, алоҳида тест қилинади**

**Қайта сўров сиёсати (дизайн §4.5):**
- Фойдаланувчи охирги сўров марказидан **радиус × 0.25** дан узоқлашса → янги сўров
- Радиус ёки қатлам ўзгарса → дарҳол сўров
- Дебаунс 2.5 сония
- Чегара (`boundary`) ФАҚАТ `current_mahalla.id` ўзгарганда сўралади

- [ ] **Қадам 1: Соф функцияга йиқиладиган тест ёзиш**

```dart
test('радиуснинг чорагидан кам силжиш — қайта сўров ЙЎҚ', () {
  // 3000 м радиус → чегара 750 м
  expect(shouldRefetch(const LatLng(41.6, 60.3), const LatLng(41.6, 60.3), 3000), isFalse);
});

test('радиуснинг чорагидан кўп силжиш — қайта сўров БОР', () {
  // ~1 км шимолга: 1 daraja lat ≈ 111 320 m → 0.009 ≈ 1002 m
  expect(shouldRefetch(const LatLng(41.6, 60.3), const LatLng(41.609, 60.3), 3000), isTrue);
});

test('олдинги марказ йўқ бўлса — албатта сўров', () {
  expect(shouldRefetch(null, const LatLng(41.6, 60.3), 3000), isTrue);
});

test('кичик радиусда чегара ҳам кичик бўлади', () {
  // 1000 м радиус → чегара 250 м; 300 м силжиш сўров келтиради
  expect(shouldRefetch(const LatLng(41.6, 60.3), const LatLng(41.6027, 60.3), 1000), isTrue);
  // лекин 3000 м радиусда ўша 300 м етарли эмас
  expect(shouldRefetch(const LatLng(41.6, 60.3), const LatLng(41.6027, 60.3), 3000), isFalse);
});
```

> Охирги тест ЭНГ МУҲИМИ: у чегара радиусга **боғлиқ** эканини исботлайди.
> Агар кимдир 750 м ни қотириб қўйса, шу тест йиқилади.

Масофа учун мавжуд `lib/core/geo/haversine.dart` ни ишлат — янги формула ёзма.

- [ ] **Қадам 2: Йиқилишини кўриш**

- [ ] **Қадам 3: Контроллерни ёзиш**

- [ ] **Қадам 4: Тестларни ўтказиш**

- [ ] **Қадам 5: Мутация текшируви**

`shouldRefetch` ичидаги `radiusM * 0.25` ни `750` га қотириб қўй ва тестни
ишга тушир — **охирги тест йиқилиши ШАРТ**. Кейин қайтар. Натижани ҳисоботда ёз.

- [ ] **Қадам 6: Коммит**
```bash
git commit -m "feat(mobil): atrof — GPS/radius/qatlam bo'yicha qayta so'rov nazorati"
```

---

### Task 4: Харита экрани

**Файллар:**
- Яратиш: `lib/features/map/map_screen.dart`
- Яратиш: `lib/features/map/point_sheet.dart`

**Интерфейслар:**
- Ишлатади: барча олдингилар
- Беради: `class NearbyMapScreen extends ConsumerStatefulWidget` — **`readOnly` параметри билан**:
  ```dart
  const NearbyMapScreen({super.key, this.readOnly = false});
  ```
  `readOnly: true` (раҳбар) — нуқта картасида «Зона детали / Сурат олиш»
  тугмаси **кўрсатилмайди**. `readOnly: false` (депутат) — кўрсатилади.

  > Бу битта экранни икки жойда ишлатишнинг ягона фарқи. Раҳбар қобиғи
  > `HouseScreen` ни ҳам import қилмайди — 6-вазифада тест билан қулфланади.

**Талаблар (дизайн §4.6):**
- OSM плиткалар + FMTC кеши
- «Мен» тугмаси (recenter) + follow-me; эркин сурилса follow-me ўчади
- Радиус доираси
- Қатлам чиплари (тепада)
- Маркер ранглари: monitoring → `zoneStatusColor(overall_status)`;
  org → категория иконкаси, `is_social` ажралиб туради; home → кичик нейтрал нуқта
- Зичликда кластер
- Тепада баннер: «Сиз ҳозир **{маҳалла}**да» + `counts`
- Маҳалла чегараси — пунктир контур
- `truncated: true` бўлса — «Нуқталар кўп, радиусни кичрайтиринг» огоҳлантириши
- Тармоқ йўқ бўлса — `isStale` баннер (мавжуд намуна)
- GPS нosozлиги — `LocationFailure` бўйича аниқ матн + «Созламаларни очиш»

- [ ] **Қадам 1: Экранни ёзиш** (тест — 5-қадамда, виджет даражасида)
- [ ] **Қадам 2: `analyze` тоза**
- [ ] **Қадам 3: Эмулятор/қурилмада қўлда кўриш** — скриншот ҳисоботга
- [ ] **Қадам 4: `readOnly` фарқини виджет тести билан қулфлаш**

```dart
testWidgets('readOnly=true bo\'lganda «Сурат олиш» tugmasi yo\'q', (t) async { ... });
testWidgets('readOnly=false bo\'lganda tugma bor', (t) async { ... });
```

- [ ] **Қадам 5: Коммит**
```bash
git commit -m "feat(mobil): atrof xaritasi — qatlamlar, klaster, chegara, nuqta kartasi"
```

---

### Task 5: Раҳбар қобиғи + роль бўйича ажратиш

**Файллар:**
- Ўзгартириш: `lib/features/auth/auth_gate.dart` (**ягона мавжуд файл ўзгариши**)
- Яратиш: `lib/features/rahbar/rahbar_shell.dart`
- Тест: `test/rahbar_shell_test.dart`, `test/readonly_guarantee_test.dart`

**Интерфейслар:**
- Ишлатади: `MahallaContext.role`, `NearbyMapScreen(readOnly: true)`
- Беради: `RahbarShell`; `bool isRahbarRole(String? role)`

- [ ] **Қадам 1: Йиқиладиган тестни ёзиш**

```dart
test('раҳбар роллари аниқланади', () {
  expect(isRahbarRole('tuman'), isTrue);
  expect(isRahbarRole('viloyat'), isTrue);
  expect(isRahbarRole('admin'), isTrue);

  expect(isRahbarRole('deputat'), isFalse);
  expect(isRahbarRole('rais'), isFalse);
  expect(isRahbarRole('hokim-yordamchisi'), isFalse);
  expect(isRahbarRole(null), isFalse,
      reason: 'роли номаълум фойдаланувчи раҳбар қобиғига тушмайди — fail-closed');
  expect(isRahbarRole(''), isFalse);
  expect(isRahbarRole('TUMAN'), isFalse,
      reason: 'сервер кичик ҳарфда қайтаради; катта ҳарф — кутилмаган қиймат');
});
```

**ЭНГ МУҲИМ ТЕСТ — read-only кафолати** (`test/readonly_guarantee_test.dart`):

```dart
// Bu test KOD MATNINI tekshiradi, xulq-atvorini emas — ataylab.
// Maqsad: rahbar daraxtiga capture/upload kodi HECH QACHON kirmasligi.
// "Tugmani yashirdik" yetarli emas: import bo'lsa, kamera ruxsati so'ralishi
// va aldovga qarshi yo'l rahbar iловasiga sizib kirishi mumkin.
test('раҳбар дарахти capture/upload дан import қилмайди', () {
  final dir = Directory('lib/features/rahbar');
  final offenders = <String>[];

  for (final f in dir.listSync(recursive: true).whereType<File>()) {
    if (!f.path.endsWith('.dart')) continue;
    final src = f.readAsStringSync();
    if (src.contains('features/capture') || src.contains('features/upload')) {
      offenders.add(f.path);
    }
  }

  expect(offenders, isEmpty,
      reason: 'раҳбар фақат кўради — сурат олиш/юклаш коди у ерга кирмайди');
});
```

- [ ] **Қадам 2: Йиқилишини кўриш**

- [ ] **Қадам 3: `RahbarShell` ва роль шохини ёзиш**

`auth_gate.dart` да:

```dart
      case AuthStatus.authenticated:
        // Rol bo'yicha ikki butunlay boshqa qobiq. Rahbar ma'lumot
        // KIRITMAYDI — u faqat ko'radi, shuning uchun unga ish ro'yxati
        // va surat olish oqimi umuman ko'rsatilmaydi.
        return isRahbarRole(state.context?.role)
            ? const RahbarShell()
            : const HomeShell();
```

`RahbarShell` — `IndexedStack` + `NavigationBar`, 3 таб:
`Атроф` (`NearbyMapScreen(readOnly: true)`) · `Маҳалла` · `Туман`.
6- ва 7-вазифагача охирги иккитаси «Тайёрланмоқда» placeholder бўлади.

- [ ] **Қадам 4: Тестларни ўтказиш**
- [ ] **Қадам 5: `HomeShell` ўзгармаганини исботлаш**

```
git diff HEAD -- lib/features/home/ lib/features/worklist/ lib/features/house/ lib/features/dashboard/
```
Кутилган: бўш.

- [ ] **Қадам 6: Коммит**
```bash
git commit -m "feat(mobil): rahbar qobig'i va rol bo'yicha ajratish"
```

---

### Task 6: Раҳбар — «Маҳалла» паспорти таби

**Файллар:**
- Яратиш: `lib/features/rahbar/executive_models.dart`
- Яратиш: `lib/features/rahbar/executive_repository.dart`
- Яратиш: `lib/features/rahbar/mahalla_tab.dart`
- Тест: `test/executive_models_test.dart`

**Эндпойнтлар (ҳаммаси production'да ЖОНЛИ):**
- `GET /api/mahalla/executive/mahallas/{id}` — паспорт
- `GET /api/mahalla/executive/mahallas/{id}/obod` — обододнлаштириш матрицаси
- `GET /api/mahalla/executive/mahallas/{id}/projects` — микро-лойиҳалар

**Маҳалла қаердан олинади:** `nearbyControllerProvider` даги
`currentMahalla.id` — яъни раҳбар турган маҳалла. У null бўлса ёки
фойдаланувчи бошқасини танласа — маҳалла танлагичи.

**Кўрсатиладиган маълумот** (сервер жавобидан):
`households`, `indicators` (аҳоли, хонадон, оила, камбағаллик %, ижтимоий
реестр), `social_objects`, `zone_status`, `dynamics`, `recent_changes`,
`staff`, `micro_projects`.

> **Диққат — ҳалоллик талаби:** Шовотда `employment_rate` 52 маҳалладан
> **0 тасида** бор, `population` эса 68/104 қаторда. Маълумот йўқ бўлса
> «0» ЭМАС, **«маълумот йўқ»** деб кўрсатилсин. Ноль ва маълумотсизлик
> аралашса раҳбар нотўғри қарор қабул қилади. Буни тест билан қулфла:
> `null` индикатор «—» сифатида рендер бўлсин, «0» эмас.

- [ ] **Қадам 1: `null` vs `0` тестини ёзиш (йиқилади)**
- [ ] **Қадам 2: Йиқилишини кўриш**
- [ ] **Қадам 3: Моделлар + репозиторий + таб**
- [ ] **Қадам 4: Тестларни ўтказиш**
- [ ] **Қадам 5: Коммит** — `feat(mobil): rahbar — mahalla pasporti tabi`

---

### Task 7: Раҳбар — «Туман» рейтинги таби

**Файллар:**
- Яратиш: `lib/features/rahbar/district_tab.dart`
- Ўзгартириш: `lib/features/rahbar/executive_repository.dart`, `executive_models.dart`
- Тест: `test/district_models_test.dart`

**Эндпойнтлар:**
- `GET /executive/districts/{id?}` — маҳаллалар жадвали, жами, ҳафталик рейтинг
- `GET /executive/scoring/{id?}` — КОИ/ИПИ, A/B/C/D квадрантлар, устуворлик
- `GET /executive/district-list` — туман танлагичи (`tuman` роли учун битта элемент қайтаради)

**Кўрсатиш:** маҳаллалар рейтинги (устуворлик бўйича), квадрант белгиси,
«оғир» маҳаллалар ажратилган, камбағаллик % бўйича саралаш.

> `tuman` роли бошқа туманни сўраса сервер **403** қайтаради. UI буни
> «қамровингизда эмас» деб кўрсатсин, портламасин. Тест билан қулфла.

- [ ] **Қадам 1: Тест (403 ҳолати ва рейтинг тартиби)**
- [ ] **Қадам 2-4: Йиқилиш → ёзиш → ўтиш**
- [ ] **Қадам 5: Коммит** — `feat(mobil): rahbar — tuman reytingi va skoring tabi`

---

### Task 8: Депутат «Атроф» таби + якуний текширув

**Файллар:**
- Ўзгартириш: `lib/features/home/home_shell.dart` (3-таб қўшилади)
- Тест: `test/home_shell_test.dart`

- [ ] **Қадам 1: Тест** — `HomeShell` да 3 та destination, учинчиси «Атроф»
- [ ] **Қадам 2: `NearbyMapScreen(readOnly: false)` ни 3-таб қилиб қўшиш**
- [ ] **Қадам 3: Барча тестлар + analyze**
```
C:\flutter\bin\flutter.bat analyze
C:\flutter\bin\flutter.bat test
```
- [ ] **Қадам 4: APK йиғиш**
```
C:\flutter\bin\flutter.bat build apk --release
```
- [ ] **Қадам 5: Коммит** — `feat(mobil): deputat uchun «Атроф» tabi`
