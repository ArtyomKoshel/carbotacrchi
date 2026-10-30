/**
 * The results in three forms over one list of lots:
 *  rows  — phone: photo, title, price, mileage, history flags;
 *  table — desktop: sortable columns, compare checkbox, keyboard ↑ ↓ Enter;
 *  cards — desktop: a photo grid.
 */
const Results = (() => {
  // table column → sort key pair [asc, desc]; the first click picks the natural direction
  const SORT_COLS = {
    price: ['price_asc', 'price_desc'],
    mileage: ['mileage_asc', 'mileage_desc'],
    year: ['year_desc', 'year_asc'],
    date: ['date', 'date'],
  };

  // the table's optional columns, in order; `match` names the field of Fmt.matched() that lights the cell
  const dash = v => (v === null || v === undefined || v === '' ? '—' : v);
  const COLUMNS = [
    { id: 'price', label: 'Цена', cls: 'r t-num', sort: 'price', match: 'price', cell: l => Fmt.price(l.price, l.currency) },
    { id: 'mileage', label: 'Пробег, км', cls: 'r t-num', sort: 'mileage', match: 'mileage', cell: l => Fmt.num(l.mileage) },
    { id: 'year', label: 'Год', cls: 't-num', sort: 'year', match: 'year', cell: l => dash(l.year) },
    { id: 'engine', label: 'Объём', cls: 'r t-num', match: 'engine', cell: l => dash(Fmt.engine(l)) },
    { id: 'fuel', label: 'Топливо', match: 'fuel', cell: l => dash(Fmt.label('fuel', l.fuel)) },
    { id: 'transmission', label: 'КПП', match: 'transmission', cell: l => dash(Fmt.label('transmission', l.transmission)) },
    { id: 'drive', label: 'Привод', match: 'drive', cell: l => dash(Fmt.label('drive_type', l.driveType)) },
    { id: 'body', label: 'Кузов', match: 'body', cell: l => dash(Fmt.label('body_type', l.bodyType)) },
    { id: 'color', label: 'Цвет', match: 'color', cell: l => dash(Fmt.label('color', l.color)) },
    { id: 'complectation', label: 'Комплектация', match: 'complectation', cell: l => dash(l.catalog?.complectation) },
    { id: 'history', label: 'История', match: 'accident', html: true,
      cell: l => { const h = Fmt.history(l); return `<span class="hist-dot hist-dot--${h.level}">${Fmt.esc(h.short)}</span>`; } },
    { id: 'owners', label: 'Смен влад.', title: 'Смен владельца', cls: 'r t-num', match: 'owners', cell: l => dash(l.ownersCount) },
    { id: 'insurance', label: 'Страх.', title: 'Страховые случаи', cls: 'r t-num', match: 'insurance', cell: l => dash(l.insuranceCount) },
    { id: 'city', label: 'Город', cell: l => dash(Fmt.city(l)) },
    { id: 'listed', label: 'Выставлен', cls: 't-num', sort: 'date', match: 'listed', cell: l => Fmt.shortDay(l.listedAt) },
  ];
  const DEFAULT_COLUMNS = ['price', 'mileage', 'year', 'history', 'owners', 'city', 'listed'];
  const COLUMNS_KEY = 'carbot_table_columns';

  function shownColumns() {
    let ids = DEFAULT_COLUMNS;
    try {
      const saved = JSON.parse(localStorage.getItem(COLUMNS_KEY) || 'null');
      if (Array.isArray(saved)) ids = saved;
    } catch (_) {}
    return COLUMNS.filter(c => ids.includes(c.id));
  }

  function saveColumns(ids) {
    try { localStorage.setItem(COLUMNS_KEY, JSON.stringify(ids)); } catch (_) {}
  }

  /** The «Колонки» menu under `anchor`: tick the table's columns; `onChange` redraws. */
  function columnsMenu(anchor, onChange) {
    document.querySelector('.colmenu')?.remove();
    const shown = new Set(shownColumns().map(c => c.id));
    const menu = document.createElement('div');
    menu.className = 'colmenu';
    menu.setAttribute('role', 'dialog');
    menu.setAttribute('aria-label', 'Колонки таблицы');
    menu.innerHTML = `${COLUMNS.map(c => `<label class="colmenu__item"><input type="checkbox" value="${c.id}" ${shown.has(c.id) ? 'checked' : ''}>
        <span>${Fmt.esc(c.title || c.label)}</span></label>`).join('')}
      <button type="button" class="colmenu__reset">По умолчанию</button>`;
    const r = anchor.getBoundingClientRect();
    menu.style.top = `${r.bottom + 6}px`;
    menu.style.left = `${Math.max(8, r.right - 240)}px`;
    document.body.appendChild(menu);
    const apply = ids => { saveColumns(ids); onChange(); };
    menu.querySelectorAll('input').forEach(i => i.addEventListener('change', () =>
      apply([...menu.querySelectorAll('input:checked')].map(x => x.value))));
    menu.querySelector('.colmenu__reset').addEventListener('click', () => {
      menu.querySelectorAll('input').forEach(i => { i.checked = DEFAULT_COLUMNS.includes(i.value); });
      apply(DEFAULT_COLUMNS);
    });
    const close = e => {
      if (menu.contains(e.target) || anchor.contains(e.target)) return;
      menu.remove();
      document.removeEventListener('mousedown', close);
    };
    setTimeout(() => document.addEventListener('mousedown', close), 0);
  }

  function thumb(lot, eager) {
    const n = Fmt.photoCount(lot);
    return `<div class="thumb"><img src="${Fmt.esc(Fmt.cover(lot))}" alt="" ${eager ? '' : 'loading="lazy"'} decoding="async"
      onerror="this.onerror=null;this.src='${Fmt.PLACEHOLDER}'">${n > 1 ? `<span class="thumb__n">${n}</span>` : ''}</div>`;
  }

  function flags(lot, query) {
    const h = Fmt.history(lot);
    const out = h.flags.map(f => `<span class="flag${f.cls ? ' flag--' + f.cls : ''}">${Fmt.esc(f.text)}</span>`);
    if (query?.trim && lot.trim) out.push(`<span class="flag flag--match">${Fmt.esc(Fmt.label('trim', lot.trim))}</span>`);
    return out.length ? `<div class="flags">${out.join('')}</div>` : '';
  }

  function row(lot, i, ctx) {
    const fav = ctx.favorites?.has(lot.id);
    return `<div class="row" role="button" tabindex="0" data-i="${i}" aria-label="${Fmt.esc(Fmt.title(lot))}">
      ${thumb(lot, i < 4)}
      <div>
        <div class="row__title">${Fmt.esc(Fmt.title(lot))}${Fmt.trim(lot) ? ` <span style="color:var(--muted);font-weight:500">${Fmt.esc(Fmt.trim(lot))}</span>` : ''}</div>
        <div class="row__sub">${Fmt.esc(Fmt.sub(lot))}</div>
        <div class="row__price">${Fmt.price(lot.price, lot.currency)}</div>
        ${flags(lot, ctx.query)}
      </div>
      <button class="row__fav${fav ? ' on' : ''}" type="button" data-fav="${i}" aria-label="${fav ? 'Убрать из избранного' : 'В избранное'}" aria-pressed="${fav}">${Fmt.icon.heart}</button>
    </div>`;
  }

  function tableRow(lot, i, ctx, columns, asked) {
    const sel = ctx.selectedId === lot.id;
    const carAsked = ['model', 'generation', 'modification', 'complectation'].some(f => asked.has(f));
    return `<tr data-i="${i}" class="${sel ? 'sel' : ''}" aria-selected="${sel}">
      <td class="t-chk"><button type="button" class="cbx${Compare.has(lot.id) ? ' on' : ''}" data-cmp="${i}" aria-label="Сравнить" aria-pressed="${Compare.has(lot.id)}"></button></td>
      <td class="t-thumb">${thumb(lot, i < 12)}</td>
      <td class="t-car${carAsked ? ' match' : ''}"><b>${Fmt.esc([Fmt.make(lot), Fmt.model(lot)].filter(Boolean).join(' '))}${ctx.favorites?.has(lot.id) ? ' <span class="t-fav" title="В избранном">♥</span>' : ''}</b>
        <small>${Fmt.esc([Fmt.trim(lot), Fmt.drive(lot)].filter(Boolean).join(' · '))}</small></td>
      ${columns.map(c => `<td class="${[c.cls, c.match && asked.has(c.match) ? 'match' : ''].filter(Boolean).join(' ')}">${c.html ? c.cell(lot) : Fmt.esc(c.cell(lot))}</td>`).join('')}
    </tr>`;
  }

  function card(lot, i, ctx) {
    return `<div class="gc${ctx.selectedId === lot.id ? ' sel' : ''}" role="button" tabindex="0" data-i="${i}">
      ${thumb(lot, i < 8)}
      <div class="gc__body">
        <div class="row__title">${Fmt.esc(Fmt.title(lot))}</div>
        <div class="row__sub">${Fmt.esc(Fmt.sub(lot))}</div>
        <div class="row__price">${Fmt.price(lot.price, lot.currency)}</div>
        ${flags(lot, ctx.query)}
      </div>
    </div>`;
  }

  function head(c, sort) {
    const keys = c.sort && SORT_COLS[c.sort];
    const on = keys && keys.includes(sort);
    const arrow = on ? (sort.endsWith('_asc') ? ' ↑' : ' ↓') : '';
    const cls = [(c.cls || '').includes('r') ? 'r' : '', on ? 'sorted' : ''].filter(Boolean).join(' ');
    return `<th scope="col" class="${cls}" ${keys ? `data-sort="${c.sort}"` : ''} ${c.title ? `title="${Fmt.esc(c.title)}"` : ''}>${Fmt.esc(c.label)}${arrow}</th>`;
  }

  /**
   * @param {HTMLElement} mount
   * @param {object[]} lots
   * @param {{mode: 'rows'|'table'|'cards', favorites?: Set, selectedId?: string, sort?: string, query?: object,
   *          onOpen: Function, onFav?: Function, onCompare?: Function, onSort?: Function}} ctx
   */
  function render(mount, lots, ctx) {
    if (ctx.mode === 'table') {
      const columns = shownColumns();
      const asked = Fmt.matched(ctx.query);
      mount.innerHTML = `<div class="lt-wrap"><table class="lt"><thead><tr>
          <th class="t-chk"></th><th class="t-thumb"></th><th scope="col">Авто</th>
          ${columns.map(c => head(c, ctx.sort)).join('')}
        </tr></thead><tbody>${lots.map((l, i) => tableRow(l, i, ctx, columns, asked)).join('')}</tbody></table></div>`;
      mount.querySelectorAll('th[data-sort]').forEach(th => th.addEventListener('click', () => {
        const [first, second] = SORT_COLS[th.dataset.sort];
        ctx.onSort?.(ctx.sort === first && first !== second ? second : first);
      }));
      mount.querySelectorAll('[data-cmp]').forEach(b => b.addEventListener('click', e => {
        e.stopPropagation();
        ctx.onCompare?.(lots[+b.dataset.cmp], b);
      }));
      mount.querySelectorAll('tbody tr').forEach(tr => tr.addEventListener('click', () => ctx.onOpen(lots[+tr.dataset.i])));
      return;
    }
    const tpl = ctx.mode === 'cards' ? card : row;
    mount.innerHTML = ctx.mode === 'cards'
      ? `<div class="grid">${lots.map((l, i) => tpl(l, i, ctx)).join('')}</div>`
      : lots.map((l, i) => tpl(l, i, ctx)).join('');
    mount.querySelectorAll('[data-i]').forEach(el => {
      const open = e => { if (e.target.closest('[data-fav]')) return; ctx.onOpen(lots[+el.dataset.i]); };
      el.addEventListener('click', open);
      el.addEventListener('keydown', e => { if (e.key === 'Enter') open(e); });
    });
    mount.querySelectorAll('[data-fav]').forEach(b => b.addEventListener('click', e => {
      e.stopPropagation();
      ctx.onFav?.(lots[+b.dataset.fav], b);
    }));
  }

  function skeleton(mount, mode, n = 6) {
    if (mode === 'table') {
      mount.innerHTML = `<div class="lt-wrap"><table class="lt"><tbody>${Array.from({ length: 10 }, () =>
        `<tr><td colspan="10"><div class="skeleton" style="height:28px"></div></td></tr>`).join('')}</tbody></table></div>`;
      return;
    }
    mount.innerHTML = Array.from({ length: n }, () => `<div class="row row--skeleton">
      <div class="thumb skeleton"></div>
      <div><div class="line skeleton" style="width:80%"></div><div class="line skeleton" style="width:55%"></div><div class="line skeleton" style="width:35%"></div></div>
    </div>`).join('');
  }

  function empty(mount, { title, text, action }) {
    mount.innerHTML = `<div class="empty">${Fmt.icon.search}<b>${Fmt.esc(title)}</b>${text ? `<span>${Fmt.esc(text)}</span>` : ''}
      ${action ? `<button class="btn btn--ghost" type="button" data-empty-action>${Fmt.esc(action.label)}</button>` : ''}</div>`;
    if (action) mount.querySelector('[data-empty-action]').addEventListener('click', action.run);
  }

  return { render, skeleton, empty, columnsMenu };
})();
