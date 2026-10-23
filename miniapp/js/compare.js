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

  function render(mount, { onOpen } = {}) {
    if (!items.length) {
      mount.innerHTML = `<div class="empty">${Fmt.icon.compare}<b>Здесь пока пусто</b>
        <span>Отметьте до ${MAX} машин кнопкой «Сравнить» в лоте или галочкой в таблице.</span></div>`;
      return;
    }
    const head = items.map(l => `<th scope="col"><div class="cmp__car">
        <img src="${Fmt.esc(Fmt.cover(l))}" alt="" loading="lazy" onerror="this.src='${Fmt.PLACEHOLDER}'">
        <span class="cmp__name">${Fmt.esc(Fmt.title(l))}</span>
        <span class="cmp__acts"><a href="#lot-${Fmt.esc(l.id)}" data-open="${Fmt.esc(l.id)}">Открыть</a>
          <button class="link-btn" type="button" data-remove="${Fmt.esc(l.id)}">Убрать</button></span>
      </div></th>`).join('');
    const body = ROWS.map(([label, text, best, value]) => {
      const cells = items.map(text).map(String);
      const same = cells.every(c => c === cells[0]);
      let bestIdx = new Set();
      if (best && items.length > 1) {
        const vals = items.map(value).map(v => (v === null || v === undefined || v === '' ? null : Number(v)));
        const known = vals.filter(v => v !== null && !isNaN(v));
        if (known.length > 1 && new Set(known).size > 1) {
          const target = best === 'min' ? Math.min(...known) : Math.max(...known);
          vals.forEach((v, i) => v === target && bestIdx.add(i));
        }
      }
      return `<tr><th scope="row">${label}</th>${cells.map((c, i) =>
        `<td class="${bestIdx.has(i) ? 'best' : ''}${!same && items.length > 1 ? ' diff' : ''}">${Fmt.esc(c)}</td>`).join('')}</tr>`;
    }).join('');
    mount.innerHTML = `<div class="cmp-wrap"><table class="cmp"><thead><tr><th></th>${head}</tr></thead><tbody>${body}</tbody></table></div>
      <p class="cmp-hint">Зелёным — лучшее значение, подсветкой — строки, где машины различаются.
        <button class="link-btn" type="button" data-clear>Очистить сравнение</button></p>`;
    mount.querySelectorAll('[data-remove]').forEach(b => b.addEventListener('click', () => { remove(b.dataset.remove); render(mount, { onOpen }); }));
    mount.querySelector('[data-clear]')?.addEventListener('click', () => { clearAll(); render(mount, { onOpen }); });
    mount.querySelectorAll('[data-open]').forEach(a => a.addEventListener('click', e => {
      if (!onOpen) return;
      e.preventDefault();
      onOpen(items.find(l => l.id === a.dataset.open));
    }));
  }

  return { has, count, toggle, remove, render, onChange, MAX };
})();
