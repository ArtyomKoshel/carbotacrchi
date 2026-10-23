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
        <div class="row__price">${Fmt.won(lot.price)}</div>
        ${flags(lot, ctx.query)}
      </div>
      <button class="row__fav${fav ? ' on' : ''}" type="button" data-fav="${i}" aria-label="${fav ? 'Убрать из избранного' : 'В избранное'}" aria-pressed="${fav}">${Fmt.icon.heart}</button>
    </div>`;
  }

  function tableRow(lot, i, ctx) {
    const h = Fmt.history(lot);
    const sel = ctx.selectedId === lot.id;
    return `<tr data-i="${i}" class="${sel ? 'sel' : ''}" aria-selected="${sel}">
      <td class="t-chk"><button type="button" class="cbx${Compare.has(lot.id) ? ' on' : ''}" data-cmp="${i}" aria-label="Сравнить" aria-pressed="${Compare.has(lot.id)}"></button></td>
      <td class="t-thumb">${thumb(lot, i < 12)}</td>
      <td class="t-car"><b>${Fmt.esc([Fmt.make(lot), Fmt.model(lot)].filter(Boolean).join(' '))}${ctx.favorites?.has(lot.id) ? ' <span class="t-fav" title="В избранном">♥</span>' : ''}</b>
        <small>${Fmt.esc([Fmt.trim(lot), Fmt.drive(lot)].filter(Boolean).join(' · '))}</small></td>
      <td class="r t-num">${Fmt.num(lot.price)}</td>
      <td class="r t-num">${Fmt.num(lot.mileage)}</td>
      <td class="t-num">${Fmt.esc(lot.year || '—')}</td>
      <td><span class="hist-dot hist-dot--${h.level}">${Fmt.esc(h.short)}</span></td>
      <td class="r t-num">${Fmt.esc(lot.ownersCount ?? '—')}</td>
      <td>${Fmt.esc(Fmt.city(lot) || '—')}</td>
      <td class="t-num">${Fmt.esc(Fmt.shortDay(lot.listedAt))}</td>
    </tr>`;
  }

  function card(lot, i, ctx) {
    return `<div class="gc${ctx.selectedId === lot.id ? ' sel' : ''}" role="button" tabindex="0" data-i="${i}">
      ${thumb(lot, i < 8)}
      <div class="gc__body">
        <div class="row__title">${Fmt.esc(Fmt.title(lot))}</div>
        <div class="row__sub">${Fmt.esc(Fmt.sub(lot))}</div>
        <div class="row__price">${Fmt.won(lot.price)}</div>
        ${flags(lot, ctx.query)}
      </div>
    </div>`;
  }

  function sortHead(col, label, cls, sort) {
    const keys = SORT_COLS[col];
    const on = keys && keys.includes(sort);
    const arrow = on ? (sort.endsWith('_asc') ? ' ↑' : ' ↓') : '';
    return `<th scope="col" class="${cls}${on ? ' sorted' : ''}" ${keys ? `data-sort="${col}"` : ''}>${label}${arrow}</th>`;
  }

  /**
   * @param {HTMLElement} mount
   * @param {object[]} lots
   * @param {{mode: 'rows'|'table'|'cards', favorites?: Set, selectedId?: string, sort?: string, query?: object,
   *          onOpen: Function, onFav?: Function, onCompare?: Function, onSort?: Function}} ctx
   */
  function render(mount, lots, ctx) {
    if (ctx.mode === 'table') {
      mount.innerHTML = `<div class="lt-wrap"><table class="lt"><thead><tr>
          <th class="t-chk"></th><th class="t-thumb"></th><th scope="col">Авто</th>
          ${sortHead('price', 'Цена, ₩', 'r', ctx.sort)}${sortHead('mileage', 'Пробег, км', 'r', ctx.sort)}${sortHead('year', 'Год', '', ctx.sort)}
          <th scope="col">История</th><th scope="col" class="r" title="Смен владельца">Смен влад.</th><th scope="col">Город</th>${sortHead('date', 'Выставлен', '', ctx.sort)}
        </tr></thead><tbody>${lots.map((l, i) => tableRow(l, i, ctx)).join('')}</tbody></table></div>`;
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

  return { render, skeleton, empty };
})();
