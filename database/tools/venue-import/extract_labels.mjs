// DWG -> o'rindiq yorliqlari (MTEXT) chiqaruvchi ETL.
// Geometriya YARATILMAYDI — DWG dagi MTEXT ins_pt (x,y) koordinatalaridan olinadi.
//
// Foydalanish:
//   node extract_labels.mjs <dwg_path> <out_labels_json> [--label-regex=...]
//
// --label-regex — ixtiyoriy. 2 ta capture-guruh talab qiladi:
//   guruh 1 = seat_group tokeni (masalan K/O/Y), guruh 2 = raqamli seq.
//   Standart: ^([KOY])\s*(\d{1,3})$  (regbardosh — 'i' bayroq bilan).
//
// Chiqish: JSON massiv [{code, group, seq, x, y, z, color, layer}].
import { readFileSync, writeFileSync } from 'fs';
import * as LW from '@mlightcad/libredwg-web';

const argv = process.argv.slice(2);
const positional = argv.filter((a) => !a.startsWith('--'));
const opts = Object.fromEntries(
  argv
    .filter((a) => a.startsWith('--'))
    .map((a) => {
      const i = a.indexOf('=');
      return i === -1 ? [a.slice(2), true] : [a.slice(2, i), a.slice(i + 1)];
    }),
);

const dwgPath = positional[0];
const outPath = positional[1];
if (!dwgPath || !outPath) {
  console.error('usage: node extract_labels.mjs <dwg_path> <out_labels_json> [--label-regex=...]');
  process.exit(2);
}

// Standart regex — K/O/Y guruh + 1..3 xonali raqam. --label-regex bilan almashtiriladi.
const labelRe = new RegExp(
  typeof opts['label-regex'] === 'string' ? opts['label-regex'] : '^([KOY])\\s*(\\d{1,3})$',
  'i',
);

const buf = readFileSync(dwgPath);
const ab = buf.buffer.slice(buf.byteOffset, buf.byteOffset + buf.byteLength);
const l = await LW.LibreDwg.create();
const db = l.convert(l.dwg_read_data(ab, 0));
const ents = db.entities ?? [];

// MTEXT seat labels: {\f...;\C<color>;<G><N>}  G in K/O/Y, N number
const mt = ents.filter((e) => e.type === 'MTEXT');
const labels = [];
for (const t of mt) {
  const raw = (t.text ?? '').toString();
  // formatlashni tozalash, oxirgi tokenni qoldirish
  const colM = raw.match(/\\C(\d+)/);
  const s = raw
    .replace(/\\f[^;]*;/g, '')
    .replace(/\\C\d+;?/g, '')
    .replace(/\\[A-Za-z][^;]*;?/g, '')
    .replace(/[{}]/g, '')
    .trim();
  const m = s.match(labelRe);
  if (!m) continue;
  const p = t.insertionPoint ?? {};
  labels.push({
    code: m[1].toUpperCase() + m[2],
    group: m[1].toUpperCase(),
    seq: Number(m[2]),
    x: Math.round((p.x ?? 0) * 100) / 100,
    y: Math.round((p.y ?? 0) * 100) / 100,
    z: Math.round((p.z ?? 0) * 100) / 100,
    color: colM ? Number(colM[1]) : null,
    layer: t.layer,
  });
}
writeFileSync(outPath, JSON.stringify(labels));
console.log('extracted labels:', labels.length);
const by = {};
for (const x of labels) by[x.group] = (by[x.group] ?? 0) + 1;
console.log('by group:', JSON.stringify(by));
console.log('layers seen:', [...new Set(labels.map((x) => x.layer))].join(', '));
console.log('sample:', JSON.stringify(labels.slice(0, 3)));
