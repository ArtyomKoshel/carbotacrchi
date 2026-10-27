/* The body and the insurance claims as Encar's report shows them, from lot_inspections:
   details.outer_parts — panels the performance inspection marked (replaced, welded, …), drawn on
   a car seen from above; details.accidents — the insurance claims: types 1 and 2 are repairs of
   this car (myAccidentCost), type 3 the damage it did to another car (otherAccidentCost). */
const Damage = (() => {
  // Encar's marks: X replaced, W sheet metal / welding, …; level colours the panel
  const STATUS = {
    '교환(교체)': { code: 'X', ru: 'замена', level: 'bad' },
    '판금/용접': { code: 'W', ru: 'рихтовка/сварка', level: 'warn' },
    '용접,절단': { code: 'W', ru: 'сварка/резка', level: 'bad' },
    '손상': { code: 'T', ru: 'повреждение', level: 'warn' },
    '요철': { code: 'U', ru: 'вмятина', level: 'minor' },
    '흠집': { code: 'A', ru: 'царапина', level: 'minor' },
    '부식': { code: 'C', ru: 'коррозия', level: 'minor' },
  };
  const LEVEL = { bad: 3, warn: 2, minor: 1 };

  // Encar part → [zone on the drawing, Russian name, kind]: outer — bolted panels; welded —
  // panels welded to the body (Korea counts a car with them replaced as an accident car);
  // frame — the load-bearing structure
  const PARTS = {
    '후드': ['hood', 'Капот', 'outer'],
    '프론트 휀더(좌)': ['fender-l', 'Переднее левое крыло', 'outer'],
    '프론트 휀더(우)': ['fender-r', 'Переднее правое крыло', 'outer'],
    '프론트 도어(좌)': ['door-fl', 'Передняя левая дверь', 'outer'],
    '프론트 도어(우)': ['door-fr', 'Передняя правая дверь', 'outer'],
    '리어 도어(좌)': ['door-rl', 'Задняя левая дверь', 'outer'],
    '리어 도어(우)': ['door-rr', 'Задняя правая дверь', 'outer'],
    '트렁크 리드': ['trunk', 'Крышка багажника', 'outer'],
    '라디에이터 서포트(볼트체결부품)': ['radiator', 'Рамка радиатора', 'outer'],
    '쿼터 패널(좌)': ['quarter-l', 'Заднее левое крыло', 'welded'],
    '쿼터 패널(우)': ['quarter-r', 'Заднее правое крыло', 'welded'],
    '루프 패널': ['roof', 'Крыша', 'welded'],
    '사이드실 패널(좌)': ['sill-l', 'Левый порог', 'welded'],
    '사이드실 패널(우)': ['sill-r', 'Правый порог', 'welded'],
    '프론트 패널': ['front-panel', 'Передняя панель', 'frame'],
    '크로스 멤버': ['cross', 'Поперечина', 'frame'],
    '인사이드 패널(좌)': ['inside-l', 'Левый брызговик моторного отсека', 'frame'],
    '인사이드 패널(우)': ['inside-r', 'Правый брызговик моторного отсека', 'frame'],
    '프론트 사이드 멤버(좌)': ['fmember-l', 'Передний левый лонжерон', 'frame'],
    '프론트 사이드 멤버(우)': ['fmember-r', 'Передний правый лонжерон', 'frame'],
    '프론트 휠하우스(좌)': ['fwheel-l', 'Передняя левая колёсная арка', 'frame'],
    '프론트 휠하우스(우)': ['fwheel-r', 'Передняя правая колёсная арка', 'frame'],
    '대쉬 패널': ['dash', 'Моторный щит', 'frame'],
    '필러 패널(앞)(좌)': ['pillar-al', 'Левая стойка A', 'frame'],
    '필러 패널(앞)(우)': ['pillar-ar', 'Правая стойка A', 'frame'],
    '필러 패널(중간)(좌)': ['pillar-bl', 'Левая стойка B', 'frame'],
    '필러 패널(중간)(우)': ['pillar-br', 'Правая стойка B', 'frame'],
    '필러 패널(뒤)(좌)': ['pillar-cl', 'Левая стойка C', 'frame'],
    '필러 패널(뒤)(우)': ['pillar-cr', 'Правая стойка C', 'frame'],
    '플로어 패널': ['floor', 'Пол салона', 'frame'],
    '패키지 트레이': ['package', 'Полка багажника', 'frame'],
    '리어 휠하우스(좌)': ['rwheel-l', 'Задняя левая колёсная арка', 'frame'],
    '리어 휠하우스(우)': ['rwheel-r', 'Задняя правая колёсная арка', 'frame'],
    '리어 사이드 멤버(좌)': ['rmember-l', 'Задний левый лонжерон', 'frame'],
    '리어 사이드 멤버(우)': ['rmember-r', 'Задний правый лонжерон', 'frame'],
    '트렁크 플로어': ['trunk-floor', 'Пол багажника', 'frame'],
    '리어 패널': ['rear-panel', 'Задняя панель', 'frame'],
  };
  const KIND = { outer: 'Навесные детали', welded: 'Приварные детали', frame: 'Силовой каркас' };

  // the car from above, nose up; the left side is the driver's (Korea drives on the right).
  // Panels are always drawn; frame zones only when damaged, dashed, over the body.
  const BODY = {
    'radiator': [62, 30, 96, 8], 'hood': [58, 40, 104, 80],
    'fender-l': [30, 36, 26, 88], 'fender-r': [164, 36, 26, 88],
    'door-fl': [30, 126, 26, 74], 'door-fr': [164, 126, 26, 74],
    'door-rl': [30, 202, 26, 72], 'door-rr': [164, 202, 26, 72],
    'sill-l': [20, 126, 8, 148], 'sill-r': [192, 126, 8, 148],
    'roof': [62, 150, 96, 108],
    'quarter-l': [30, 276, 26, 80], 'quarter-r': [164, 276, 26, 80],
    'trunk': [58, 288, 104, 62],
  };
  const FRAME = {
    'front-panel': [58, 18, 104, 10], 'cross': [70, 30, 80, 8],
    'inside-l': [60, 44, 14, 70], 'inside-r': [146, 44, 14, 70],
    'fmember-l': [78, 44, 14, 70], 'fmember-r': [128, 44, 14, 70],
    'fwheel-l': [32, 56, 22, 36], 'fwheel-r': [166, 56, 22, 36],
    'dash': [62, 118, 96, 6],
    'pillar-al': [56, 122, 6, 28], 'pillar-ar': [158, 122, 6, 28],
    'pillar-bl': [56, 196, 6, 12], 'pillar-br': [158, 196, 6, 12],
    'pillar-cl': [56, 256, 6, 30], 'pillar-cr': [158, 256, 6, 30],
    'floor': [70, 160, 80, 90], 'package': [66, 278, 88, 8],
    'rwheel-l': [32, 296, 22, 36], 'rwheel-r': [166, 296, 22, 36],
    'rmember-l': [78, 292, 14, 56], 'rmember-r': [128, 292, 14, 56],
    'trunk-floor': [96, 296, 28, 52], 'rear-panel': [58, 354, 104, 10],
  };

  const esc = s => Fmt.esc(String(s ?? ''));

  /** [{ zone, name, kind, marks: [{code, ru, level}], level }] — worst first, unknown parts kept by name. */
  function parts(inspection) {
    const list = inspection?.details?.outer_parts;
    if (!Array.isArray(list)) return [];
    return list.filter(p => p && p.part).map(p => {
      const [zone, name, kind] = PARTS[p.part] || [null, p.part, 'outer'];
      const marks = (Array.isArray(p.status) ? p.status : []).map(s => STATUS[s] || { code: '?', ru: s, level: 'warn' });
      const level = marks.reduce((m, s) => Math.max(m, LEVEL[s.level] || 0), 0);
      return { zone, name, kind, marks, level };
    }).sort((a, b) => b.level - a.level);
  }

  function drawing(list) {
    const at = new Map(list.filter(p => p.zone).map(p => [p.zone, p]));
    const levelName = p => Object.keys(LEVEL).find(k => LEVEL[k] === p.level) || 'warn';
    const zone = ([x, y, w, h], id, frame) => {
      const p = at.get(id);
      if (frame && !p) return '';
      const cls = `dmg__zone${frame ? ' dmg__zone--frame' : ''}${p ? ' dmg--' + levelName(p) : ''}`;
      const code = p ? [...new Set(p.marks.map(m => m.code))].join('') : '';
      return `<g class="${cls}"><rect x="${x}" y="${y}" width="${w}" height="${h}" rx="${Math.min(6, w / 3, h / 3)}"><title>${esc(p ? `${p.name}: ${p.marks.map(m => m.ru).join(', ')}` : '')}</title></rect>${code
        ? `<text x="${x + w / 2}" y="${y + h / 2}">${esc(code)}</text>` : ''}</g>`;
    };
    return `<svg class="dmg__car" viewBox="0 0 220 372" role="img" aria-label="Схема кузова: отмеченные детали">
      <path class="dmg__shell" d="M60 14 Q110 4 160 14 L192 34 Q200 40 200 60 L200 330 Q200 356 182 362 Q110 372 38 362 Q20 356 20 330 L20 60 Q20 40 28 34 Z"/>
      <rect class="dmg__glass" x="62" y="124" width="96" height="24" rx="6"/>
      <rect class="dmg__glass" x="62" y="260" width="96" height="24" rx="6"/>
      ${Object.entries(BODY).map(([id, r]) => zone(r, id, false)).join('')}
      ${Object.entries(FRAME).map(([id, r]) => zone(r, id, true)).join('')}
      <text class="dmg__dir" x="110" y="10">перед</text>
    </svg>`;
  }

  /** The «Кузов по осмотру» section, or '' when the inspection marked nothing (or there was none). */
  function bodyBlock(inspection) {
    const list = parts(inspection);
    if (!list.length) return '';
    const frame = list.some(p => p.kind === 'frame');
    const welded = list.some(p => p.kind === 'welded' && p.marks.some(m => m.code === 'X' || m.code === 'W'));
    const groups = ['frame', 'welded', 'outer'].filter(k => list.some(p => p.kind === k));
    const legend = [['bad', 'X — замена'], ['warn', 'W — рихтовка/сварка'], ['minor', 'U, A, C — вмятина, царапина, коррозия']];
    return `<section class="sect"><h3 class="sect__title"><span>Кузов по осмотру</span><span class="num">${list.length}</span></h3>
      <div class="card dmg">
        ${frame ? '<div class="dmg__alert dmg__alert--bad">Затронут силовой каркас</div>'
          : welded ? '<div class="dmg__alert">Менялись или варились приварные детали — по корейскому стандарту осмотра машина считается аварийной</div>' : ''}
        <div class="dmg__body">
          ${drawing(list)}
          <div class="dmg__list">${groups.map(k => `<h4>${KIND[k]}</h4><ul>${list.filter(p => p.kind === k).map(p =>
            `<li><i class="dmg__dot dmg--${Object.keys(LEVEL).find(l => LEVEL[l] === p.level) || 'warn'}"></i><span>${esc(p.name)}</span><b>${esc(p.marks.map(m => m.ru).join(', '))}</b></li>`).join('')}</ul>`).join('')}
          </div>
        </div>
        <div class="dmg__legend">${legend.map(([l, t]) => `<span><i class="dmg__dot dmg--${l}"></i>${t}</span>`).join('')}<span><i class="dmg__dot dmg__dot--frame"></i>каркас — пунктиром</span></div>
      </div></section>`;
  }

  /** Claims: [{date, own, cost, part, labor, paint}] newest first. */
  function claims(inspection) {
    const list = inspection?.details?.accidents;
    if (!Array.isArray(list)) return [];
    return list.filter(a => a && typeof a === 'object').map(a => {
      const n = k => Number(a[k]) || 0;
      const sum = n('partCost') + n('laborCost') + n('paintingCost');
      return { date: a.date || '', own: String(a.type) !== '3', cost: sum || n('insuranceBenefit'), part: n('partCost'), labor: n('laborCost'), paint: n('paintingCost') };
    }).sort((a, b) => String(b.date).localeCompare(String(a.date)));
  }

  /** The «Страховые случаи» section, or ''. */
  function claimsBlock(inspection) {
    const list = claims(inspection);
    if (!list.length) return '';
    const total = own => list.filter(c => c.own === own).reduce((s, c) => s + c.cost, 0);
    const n = own => list.filter(c => c.own === own).length;
    const parts = c => [c.part && `детали ${Fmt.won(c.part)}`, c.labor && `работа ${Fmt.won(c.labor)}`, c.paint && `покраска ${Fmt.won(c.paint)}`].filter(Boolean).join(' · ');
    return `<section class="sect"><h3 class="sect__title"><span>Страховые случаи</span><span class="num">${list.length}</span></h3>
      <div class="card claims">
        <div class="claims__sum">
          ${n(true) ? `<div><span>Ремонт этой машины${n(true) > 1 ? ` · ${n(true)}` : ''}</span><b class="bad">${Fmt.won(total(true))}</b></div>` : ''}
          ${n(false) ? `<div><span>Ущерб другим машинам${n(false) > 1 ? ` · ${n(false)}` : ''}</span><b>${Fmt.won(total(false))}</b></div>` : ''}
        </div>
        <ul class="claims__list">${list.map(c => `<li>
          <span class="claims__date num">${esc(c.date ? Fmt.day(c.date) : '—')}</span>
          <span class="claims__kind${c.own ? ' bad' : ''}">${c.own ? 'Ремонт этой машины' : 'Ущерб другой машине'}</span>
          <b class="num">${Fmt.won(c.cost)}</b>
          ${parts(c) ? `<small>${parts(c)}</small>` : ''}
        </li>`).join('')}</ul>
      </div></section>`;
  }

  /** «N деталей: 2 замены, 1 рихтовка» for the history row; null when there is no inspection list. */
  function summary(inspection) {
    const list = parts(inspection);
    if (!list.length) return null;
    const count = code => list.filter(p => p.marks.some(m => m.code === code)).length;
    const bits = [count('X') && `замена ${count('X')}`, count('W') && `рихтовка/сварка ${count('W')}`].filter(Boolean);
    return `${list.length} ${Fmt.plural(list.length, 'деталь', 'детали', 'деталей')}${bits.length ? ': ' + bits.join(', ') : ''}`;
  }

  /** This car's repairs paid by insurance, ₩: the inspection's own figure, else what the lot keeps. */
  function ownRepairCost(lot, inspection) {
    const own = inspection?.details?.my_accident_cost;
    return own != null && own !== '' ? Number(own) || 0 : lot.repairCost || 0;
  }

  return { bodyBlock, claimsBlock, summary, ownRepairCost, parts, claims };
})();
