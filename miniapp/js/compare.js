/**
 * Compare up to four cars. The list is the picker's own and lives on this device
 * (localStorage, a snapshot of each lot), like a shortlist on paper.
 */
const Compare = (() => {
  const KEY = 'carbot_compare_v1';
  const MAX = 4;
  let items = load();
  const listeners = new Set();

  function load() {
    try {
      const v = JSON.parse(localStorage.getItem(KEY) || '[]');
      return Array.isArray(v) ? v.slice(0, MAX) : [];
    } catch (_) { return []; }
  }
  function save() {
    try { localStorage.setItem(KEY, JSON.stringify(items)); } catch (_) {}
    listeners.forEach(fn => fn(items));
  }

  const has = id => items.some(l => l.id === id);
  const count = () => items.length;
  const onChange = fn => listeners.add(fn);

  /** @return {'added'|'removed'|'full'} */
  function toggle(lot) {
    if (has(lot.id)) { items = items.filter(l => l.id !== lot.id); save(); return 'removed'; }
    if (items.length >= MAX) return 'full';
    items = [...items, lot];
    save();
    return 'added';
  }
  function remove(id) { items = items.filter(l => l.id !== id); save(); }
  function clearAll() { items = []; save(); }

  /** Rows: [label, (lot) => text, best: 'min'|'max'|null, (lot) => number for "best"] */
  const ROWS = [
    ['Цена', l => Fmt.won(l.price), 'min', l => l.price],
    ['Пробег', l => Fmt.km(l.mileage), 'min', l => l.mileage],
    ['Год', l => l.year || '—', 'max', l => l.year],
    ['Регистрация', l => Fmt.month(l.firstRegDate), null],
    ['Двигатель', l => [Fmt.engine(l), Fmt.lower(Fmt.label('fuel', l.fuel))].filter(Boolean).join(' ') || '—', null],
    ['КПП и привод', l => [Fmt.label('transmission', l.transmission), Fmt.lower(Fmt.label('drive_type', l.driveType))].filter(Boolean).join(' · ') || '—', null],
    ['Кузов', l => Fmt.label('body_type', l.bodyType) || '—', null],
    ['Цвет', l => Fmt.label('color', l.color) || '—', null],
    ['ДТП', l => l.hasAccident === true ? 'было' : l.hasAccident === false ? 'нет' : '—', null],
    ['Смен владельца', l => l.ownersCount ?? '—', 'min', l => l.ownersCount],
    ['Страховые случаи', l => l.insuranceCount ?? '—', 'min', l => l.insuranceCount],
    ['Ремонт по страховым', l => l.repairCost ? Fmt.won(l.repairCost) : '—', 'min', l => l.repairCost || 0],
    ['Затопление, тотал', l => (l.floodHistory || l.totalLossHistory) ? 'было' : (l.floodHistory === false || l.totalLossHistory === false ? 'нет' : '—'), null],
    ['Новая стоила', l => l.retailValue ? Fmt.won(l.retailValue) : '—', null],
    ['Опций', l => Array.isArray(l.options) ? l.options.length : '—', 'max', l => Array.isArray(l.options) ? l.options.length : null],
    ['Город', l => Fmt.city(l) || '—', null],
    ['Выставлен', l => Fmt.day(l.listedAt), null],
  ];

  let onlyDiff = false;

  /** Each row: its cells, whether the cars differ, and which cells hold the best value. */
  function rows() {
    return ROWS.map(([label, text, best, value]) => {
      const cells = items.map(text).map(String);
      const differs = items.length > 1 && cells.some(c => c !== cells[0]);
      const bestIdx = new Set();
      if (best && differs) {
        const vals = items.map(value).map(v => (v === null || v === undefined || v === '' ? null : Number(v)));
        const known = vals.filter(v => v !== null && !isNaN(v));
        if (known.length > 1 && new Set(known).size > 1) {
          const target = best === 'min' ? Math.min(...known) : Math.max(...known);
          vals.forEach((v, i) => v === target && bestIdx.add(i));
        }
      }
      return { label, cells, differs, bestIdx };
    });
  }

  function carHead(l) {
    return `<div class="cmp-car">
      <img src="${Fmt.esc(Fmt.cover(l))}" alt="" loading="lazy" onerror="this.onerror=null;this.src='${Fmt.PLACEHOLDER}'">
      <span class="cmp-car__name">${Fmt.esc(Fmt.title(l))}</span>
      <span class="cmp-car__acts">
        <button type="button" class="cmp-act" data-open="${Fmt.esc(l.id)}">Открыть</button>
        <button type="button" class="cmp-act cmp-act--muted" data-remove="${Fmt.esc(l.id)}">Убрать</button>
      </span>
    </div>`;
  }

  function render(mount, { onOpen } = {}) {
    if (!items.length) {
      mount.innerHTML = `<div class="empty">${Fmt.icon.compare}<b>Здесь пока пусто</b>
        <span>Отметьте до ${MAX} машин кнопкой «Сравнить» в лоте или галочкой в таблице.</span></div>`;
      return;
    }
    const all = rows();
    const shown = onlyDiff ? all.filter(r => r.differs) : all;
    const n = items.length;
    const cell = (r, c, i) => `<span class="cmp-v${r.bestIdx.has(i) ? ' cmp-v--best' : ''}">${Fmt.esc(c)}</span>`;
    const wide = document.body.classList.contains('wide');
    const body = wide
      // desktop: a table, labels on the left
      ? `<div class="cmp-wrap"><table class="cmp"><thead><tr><th></th>${items.map(l => `<th scope="col">${carHead(l)}</th>`).join('')}</tr></thead>
          <tbody>${shown.map(r => `<tr class="${r.differs ? 'differs' : 'same'}"><th scope="row">${r.label}</th>${r.cells.map((c, i) => `<td>${cell(r, c, i)}</td>`).join('')}</tr>`).join('')}</tbody></table></div>`
      // phone: cars in even columns, each label above its values; the block scrolls sideways as a whole
      : `<div class="cmpg" style="--n:${n}"><div class="cmpg__inner">
          <div class="cmpg__cars">${items.map(carHead).join('')}</div>
          ${shown.map(r => `<div class="cmpg__row ${r.differs ? 'differs' : 'same'}">
            <div class="cmpg__label">${r.label}</div>
            <div class="cmpg__vals">${r.cells.map((c, i) => cell(r, c, i)).join('')}</div>
          </div>`).join('')}
        </div></div>`;
    const diffCount = all.filter(r => r.differs).length;
    mount.innerHTML = `
      <div class="cmp-bar">
        <span class="cmp-bar__n">${Fmt.plural(n, 'машина', 'машины', 'машин').replace(/^/, n + ' ')} · ${diffCount} ${Fmt.plural(diffCount, 'отличие', 'отличия', 'отличий')}</span>
        ${n > 1 ? `<button type="button" class="chip${onlyDiff ? ' chip--applied' : ''}" data-only-diff aria-pressed="${onlyDiff}">Только различия</button>` : ''}
      </div>
      ${body}
      <p class="cmp-hint"><span class="cmp-v--best">Зелёным</span> — лучшее значение. Строки, где машины различаются, выделены.
        <button class="link-btn" type="button" data-clear>Очистить сравнение</button></p>`;
    const rerender = () => render(mount, { onOpen });
    mount.querySelector('[data-only-diff]')?.addEventListener('click', () => { onlyDiff = !onlyDiff; rerender(); });
    mount.querySelectorAll('[data-remove]').forEach(b => b.addEventListener('click', () => { remove(b.dataset.remove); rerender(); }));
    mount.querySelector('[data-clear]')?.addEventListener('click', () => { clearAll(); rerender(); });
    mount.querySelectorAll('[data-open]').forEach(b => b.addEventListener('click', () => onOpen?.(items.find(l => l.id === b.dataset.open))));
  }

  return { has, count, toggle, remove, render, onChange, MAX };
})();
