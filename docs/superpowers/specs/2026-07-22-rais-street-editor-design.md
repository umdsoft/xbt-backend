# Rais Ko'cha Muharriri (Street Editor) — Dizayn

**Maqsad:** Mahalla raisi o'z mahallasidagi ko'chalarni tuzatsin (nom/birlashtir/o'chir/qo'sh) va uylarni to'g'ri ko'chaga biriktirsin — xarita (koordinata) asosida, chunki avtomatika real/soxta ko'chani to'liq ajratolmaydi.

**Arxitektura:** Bitta sahifa `RaisStreets.vue` — chapda ko'cha ro'yxati (CRUD), o'ngda Leaflet xarita (uylar ko'cha bo'yicha rangli). Backend: `Rais/StreetController` + `StreetEditor` servis, rais mahallasi bilan cheklangan (mavjud `CadastreController`/`MahallaAccess` namunasi). Barcha yozuvlar `mahalla.street_edits` audit jadvaliga tushadi.

**Tech Stack:** Laravel 11 (PG multi-schema), Vue 3 + TS + Pinia + Leaflet 1.9 (to'g'ridan-to'g'ri, leaflet-draw YO'Q → to'rtburchak-drag qo'lda).

## Global cheklovlar
- Rais FAQAT o'z mahallasi (`MahallaAccess::scopeFor`) — so'rovda `{mahalla}` yo'q. Ko'cha/bino boshqa mahalladaniki bo'lsa → 404 (mavjud `classify` kabi, oshkor qilmaslik uchun).
- `buildings.street_id` VA `buildings.street` (matn) doim birga yangilanadi (nom bilan id mos).
- Merge/delete/assign — bitta tranzaksiyada; `master.streets` + `mahalla.houses.street_id` ham yangilanadi.
- Faqat residential binolar xaritada biriktirish uchun ko'rsatiladi (monitoring birligi).

## Ma'lumot modeli
Yangi jadval `mahalla.street_edits` (audit):
- `id uuid pk`, `mahalla_id uuid`, `action varchar` (assign|rename|merge|delete|create),
- `street_id uuid null`, `building_id uuid null`, `detail jsonb null` ({from,to,name,source_id,count}),
- `performed_by uuid`, `created_at timestamptz`.

Mavjud: `master.streets(id, mahalla_id, name, sort_order, is_active)`, `master.buildings(id, mahalla_id, type, street, street_id, lat, lng, address)`.

## Backend API (barcha `/api/mahalla/rais/*`, `mahalla.rais` middleware)
- `GET  /rais/streets` → `{ streets: [{id,name,houses,is_active}], colors: {street_id: '#hex'} }` (uy soni bilan, alfavit).
- `GET  /rais/map` → `{ buildings: [{id, lat, lng, street_id}], boundary: GeoJSON }` (residential, koordinatali).
- `POST /rais/streets` {name} → yangi ko'cha (nom mahallada unikal).
- `PATCH /rais/streets/{street}` {name} → nom o'zgartirish (+ buildings.street matnini sync).
- `POST /rais/streets/{street}/merge` {target_id} → source uylari target'ga, source o'chadi.
- `DELETE /rais/streets/{street}` → faqat bo'sh bo'lsa (uy/uy-yozuv yo'q); aks holda 409 "avval ko'chiring".
- `POST /rais/buildings/assign` {building_ids:[], street_id} → bino(lar)ni ko'chaga biriktir (+ street matn sync).

Har biri: mahalla tekshiruvi, tranzaksiya, `street_edits` log, `422` validatsiya / `404` topilmadi / `409` konflikt.

`StreetEditor` servis (biznes-mantiq): `streets()`, `mapData()`, `create()`, `rename()`, `merge()`, `deleteStreet()`, `assign()`. Rang palitrasi (≈20 rang) determinatik: `street_id` bo'yicha barqaror rang.

## Frontend `RaisStreets.vue`
- Layout: 2 ustun (mobil: ustma-ust). Chap ~320px ko'cha paneli, o'ng — xarita (h-full).
- **Ko'cha paneli:** yuqorida "＋ Янги кўча". Har qator: rang nuqtasi + nom + uy soni + amallar (✎ nom, ⇄ merge, 🗑 o'chir). Qator bosilsa → xaritada shu ko'cha uylari yonadi (qolgani xira).
- **Xarita:** Leaflet, mahalla chegarasi + residential uylar `circleMarker` (canvas renderer, ~1000 nuqta silliq), ko'cha rangi bo'yicha. 2 rejim:
  - *Bosish rejimi* (default): uy bosilsa → tanlanganlarga qo'shiladi (yoki panel ochilib ko'cha tanlanadi).
  - *Hudud rejimi*: "Ҳудуд танлаш" tugmasi → map drag = to'rtburchak; ичидаги uylar tanlanadi.
- **Biriktirish paneli** (tanlov bo'lsa pastda paydo bo'ladi): "N уй танланди" + ko'cha dropdown + "Бириктириш" + "Бекор".
- Dialoglar: rename (input), merge (target dropdown), create (input), delete (tasdiq).
- Store `stores/raisStreets.ts`: `map`, `streets`, `colors`, `loading`; actions `load()`, `assign()`, `create()`, `rename()`, `merge()`, `remove()`.
- Route `/rais/streets` (`raisOnly`), RaisDashboard'da havola.

## Xatolik va chekka holatlar
- Assign: barcha `building_ids` shu mahallada bo'lishi shart, aks holda butun so'rov 404.
- Merge: source≠target, ikkalasi shu mahallada; target yo'q bo'lsa 404.
- Delete: uyли ko'cha → 409 (UI merge'ga yo'naltiradi).
- Bo'sh koordinatali bino xaritada ko'rsatilmaydi (lat/lng null) — ro'yxatда soni to'g'ri qoladi.
- Rang: >20 ko'cha bo'lsa palitra aylanadi (takrorlanadi) — qabul qilinadi, chunki qo'shni ko'chalar kamdan-kam bir xil rangга tushadi.

## Test
- Backend: `StreetEditor` uchun tinker/feature sinov — create/rename/merge/delete/assign, mahalla tashqarisi 404, delete-non-empty 409.
- Frontend: qo'lda local (localhost:5173 rais login) — xarita yuklanishi, bosish/hudud tanlash, biriktirish, CRUD.
