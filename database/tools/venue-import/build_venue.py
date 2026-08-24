"""ETL: labels.json (libredwg MTEXT ins_pt) -> to'liq venue dataset.

Geometriya YARATILMAYDI — DWG yorliqlaridan chiqariladi. Yarim zal yorliqlangan,
qolgan yarmi mirror qilinadi (standart o'q y=0).

Foydalanish:
  python build_venue.py <labels_json> <out_venue_json> \
      --slug=<slug> --name=<name> [--mirror-axis=y:0] [--source-file=avesto.dwg]

Chiqish: <out_venue_json> = { venue, row_clusters[], seats[] }.
Klasterlash / mirror / burchak logikasi asl skript bilan AYNAN bir xil.
"""
import argparse
import json
import math
import os
from collections import Counter, deque


def parse_args():
    ap = argparse.ArgumentParser(description='DWG yorliqlaridan venue dataset quradi')
    ap.add_argument('labels_json', help='kirish yorliqlar JSON fayli')
    ap.add_argument('out_venue_json', help='chiqish venue JSON fayli')
    ap.add_argument('--slug', required=True, help='obyekt slug')
    ap.add_argument('--name', required=True, help='obyekt nomi')
    ap.add_argument('--mirror-axis', dest='mirror_axis', default='y:0',
                    help="mirror o'qi 'axis:value' ko'rinishida (masalan y:0 yoki x:0)")
    ap.add_argument('--source-file', dest='source_file', default='',
                    help='manba DWG fayl nomi (metadata uchun)')
    return ap.parse_args()


def main():
    args = parse_args()

    with open(args.labels_json, encoding='utf-8') as f:
        L = json.load(f)

    # --- mirror o'qi: 'axis:value' ---
    axis_name, _, axis_raw = args.mirror_axis.partition(':')
    axis_name = (axis_name.strip().lower() or 'y')
    axis_val = float(axis_raw) if axis_raw.strip() != '' else 0.0
    MIRROR_AXIS = {'axis': axis_name, 'value': axis_val}

    # --- to'liq zal = yorliqlangan yarim (A) + mirror (B) ---
    seats = []
    for p in L:
        seats.append({'code': p['code'], 'group': p['group'], 'seq': p['seq'],
                      'x': p['x'], 'y': p['y'], 'is_mirrored': False, 'half': 'A'})
    for p in L:
        if axis_name == 'x':
            mx, my = 2 * axis_val - p['x'], p['y']
        else:  # 'y'
            mx, my = p['x'], 2 * axis_val - p['y']
        seats.append({'code': p['code'] + 'm', 'group': p['group'], 'seq': p['seq'],
                      'x': mx, 'y': my, 'is_mirrored': True, 'half': 'B'})

    n = len(seats)

    # kodlarni unikal qilish (manbada bir nechta dublikat yorliq bo'lishi mumkin)
    _seen = {}
    for s in seats:
        c = s['code']
        if c in _seen:
            _seen[c] += 1
            s['code'] = f'{c}_{_seen[c]}'
        else:
            _seen[c] = 1

    # --- row clusters: connected components @600mm on FULL hall ---
    C = 700.0
    grid = {}
    for i, s in enumerate(seats):
        grid.setdefault((int(s['x'] // C), int(s['y'] // C)), []).append(i)

    def near(i, R):
        s = seats[i]
        cx, cy = int(s['x'] // C), int(s['y'] // C)
        out = []
        rc = int(R // C) + 1
        for gx in range(cx - rc, cx + rc + 1):
            for gy in range(cy - rc, cy + rc + 1):
                for j in grid.get((gx, gy), []):
                    if j != i and math.hypot(seats[j]['x'] - s['x'], seats[j]['y'] - s['y']) <= R:
                        out.append(j)
        return out

    THR = 600.0
    seen = [False] * n
    comps = []
    for i in range(n):
        if seen[i]:
            continue
        q = deque([i])
        seen[i] = True
        c = [i]
        while q:
            k = q.popleft()
            for j in near(k, THR):
                if not seen[j]:
                    seen[j] = True
                    q.append(j)
                    c.append(j)
        comps.append(c)

    def cluster_angle(cluster):
        if len(cluster) < 2:
            return 90.0
        mx = sum(seats[i]['x'] for i in cluster) / len(cluster)
        my = sum(seats[i]['y'] for i in cluster) / len(cluster)
        sxx = syy = sxy = 0.0
        for i in cluster:
            dx = seats[i]['x'] - mx
            dy = seats[i]['y'] - my
            sxx += dx * dx
            syy += dy * dy
            sxy += dx * dy
        a = math.degrees(0.5 * math.atan2(2 * sxy, sxx - syy)) % 180
        return float(round(a / 45) * 45 % 180)  # snap 0/45/90/135

    # order clusters: by centroid (stage-left => small x first, then y)
    comp_info = []
    for c in comps:
        cx = sum(seats[i]['x'] for i in c) / len(c)
        cy = sum(seats[i]['y'] for i in c) / len(c)
        comp_info.append((c, cx, cy, cluster_angle(c)))
    comp_info.sort(key=lambda t: (round(t[1] / 1000), t[2]))

    row_clusters = []
    for ci, (c, cx, cy, ang) in enumerate(comp_info, 1):
        cid = f'RC{ci}'
        row_clusters.append({'id': cid, 'angle': ang, 'seat_count': len(c),
                             'centroid_x': round(cx, 1), 'centroid_y': round(cy, 1)})
        for i in c:
            seats[i]['row_cluster_id'] = cid
            seats[i]['rotation'] = ang

    # --- venue bbox (full) ---
    xs = [s['x'] for s in seats]
    ys = [s['y'] for s in seats]
    bbox = {'min_x': round(min(xs), 1), 'min_y': round(min(ys), 1),
            'max_x': round(max(xs), 1), 'max_y': round(max(ys), 1)}

    # --- stage rect: chapda (x<min_x), y=0 markazda ---
    sw = 1500.0
    sh = 8000.0
    stage = {'x': round(bbox['min_x'] - 2500, 1), 'y': round(-sh / 2, 1),
             'width': sw, 'height': sh, 'label': 'САҲНА'}

    out = {
        'venue': {'name': args.name, 'slug': args.slug,
                  'unit': 'mm', 'source_file': args.source_file or 'unknown', 'capacity': n,
                  'bbox_json': bbox, 'mirror_axis_json': MIRROR_AXIS, 'stage_json': stage},
        'row_clusters': row_clusters,
        'seats': [{'code': s['code'], 'seat_group': s['group'], 'seq': s['seq'],
                   'x': round(s['x'], 2), 'y': round(s['y'], 2),
                   'rotation': s['rotation'], 'row_cluster_id': s['row_cluster_id'],
                   'is_mirrored': s['is_mirrored'], 'half': s['half']} for s in seats],
    }
    with open(args.out_venue_json, 'w', encoding='utf-8') as f:
        json.dump(out, f, ensure_ascii=False)

    print(f'seats={n}  row_clusters={len(row_clusters)}')
    print(f'bbox x[{bbox["min_x"]:.0f},{bbox["max_x"]:.0f}] y[{bbox["min_y"]:.0f},{bbox["max_y"]:.0f}]  '
          f'gabarit {bbox["max_x"] - bbox["min_x"]:.0f} x {bbox["max_y"] - bbox["min_y"]:.0f} mm')
    print('angle histogram:', dict(Counter(rc['angle'] for rc in row_clusters)))
    print('group histogram:', dict(Counter(s['seat_group'] for s in out['seats'])))
    print('cluster sizes:', sorted([rc['seat_count'] for rc in row_clusters], reverse=True)[:10], '...')
    print('json KB:', round(os.path.getsize(args.out_venue_json) / 1024))


if __name__ == '__main__':
    main()
