/**
 * The lot page: the whole gallery, four key numbers, the history in one block, the
 * inspection, the specs and the options. The same view fills the phone screen and
 * the desktop's right-hand pane. Actions come from the shell (ctx.on*).
 */
const LotView = (() => {
  const OPTION_GROUPS = { exterior: 'Экстерьер', safety: 'Безопасность', convenience: 'Комфорт', interior: 'Салон' };

  function gallery(lot) {
    const photos = Fmt.photos(lot);
    if (!photos.length) {
      return `<div class="gallery"><div class="gallery__track"><div class="gallery__slide"><img src="${Fmt.PLACEHOLDER}" alt=""></div></div></div>`;
    }
    return `<div class="gallery">
      <div class="gallery__track" tabindex="0" aria-label="Фото, ${photos.length}">
        ${photos.map((src, i) => `<div class="gallery__slide"><img src="${Fmt.esc(src)}" alt="Фото ${i + 1}" ${i > 1 ? 'loading="lazy"' : ''}
          onerror="this.onerror=null;this.src='${Fmt.PLACEHOLDER}'"></div>`).join('')}
      </div>
      <span class="gallery__count num" data-count>1 / ${photos.length}</span>
      ${photos.length > 1 ? `<button class="gallery__nav gallery__nav--prev" type="button" data-step="-1" aria-label="Предыдущее фото">${Fmt.icon.back}</button>
        <button class="gallery__nav gallery__nav--next" type="button" data-step="1" aria-label="Следующее фото">${Fmt.icon.next}</button>
        <div class="gallery__thumbs">${photos.map((src, i) => `<button type="button" data-go="${i}" class="${i === 0 ? 'on' : ''}" aria-label="Фото ${i + 1}">
          <img src="${Fmt.esc(src)}" alt="" loading="lazy"></button>`).join('')}</div>` : ''}
    </div>`;
  }

  function wireGallery(root) {
    const track = root.querySelector('.gallery__track');
    const count = root.querySelector('[data-count]');
    if (!track || !count) return;
    const total = track.children.length;
    const current = () => Math.round(track.scrollLeft / Math.max(1, track.clientWidth));
    const go = i => track.scrollTo({ left: Math.max(0, Math.min(total - 1, i)) * track.clientWidth, behavior: 'smooth' });
    let raf = 0;
    track.addEventListener('scroll', () => {
      cancelAnimationFrame(raf);
      raf = requestAnimationFrame(() => {
        const i = current();
        count.textContent = `${i + 1} / ${total}`;
        root.querySelectorAll('.gallery__thumbs button').forEach((b, k) => b.classList.toggle('on', k === i));
      });
    }, { passive: true });
    root.querySelectorAll('[data-step]').forEach(b => b.addEventListener('click', () => go(current() + Number(b.dataset.step))));
    root.querySelectorAll('[data-go]').forEach(b => b.addEventListener('click', () => go(Number(b.dataset.go))));
    track.addEventListener('keydown', e => {
      if (e.key === 'ArrowRight') { e.preventDefault(); go(current() + 1); }
      if (e.key === 'ArrowLeft') { e.preventDefault(); go(current() - 1); }
    });
  }

  const kv = rows => `<dl class="kv">${rows.filter(Boolean).map(([k, v, cls]) =>
    `<div class="${cls?.row ?? ''}"><dt>${k}</dt><dd class="${cls?.value ?? ''}">${v}</dd></div>`).join('')}</dl>`;
  const yesNo = (v, yes = 'было', no = 'нет') => v === true ? [yes, 'bad'] : v === false ? [no, 'ok'] : null;

  function historyBlock(lot, inspection, q) {
    const rows = [];
    const add = (label, pair, match) => pair && rows.push([label, Fmt.esc(pair[0]), { value: pair[1], row: match ? 'match' : '' }]);
    add('ДТП по страховым', yesNo(lot.hasAccident), q?.hasAccident !== undefined);
    if (lot.ownersCount != null) rows.push(['Владельцы', Fmt.owners(lot.ownersCount), { value: lot.ownersCount >= 3 ? 'warn' : '', row: q?.ownersCountMax ? 'match' : '' }]);
    if (lot.insuranceCount != null) rows.push(['Страховые случаи', String(lot.insuranceCount), { value: lot.insuranceCount >= 2 ? 'warn' : '', row: q?.insuranceCountMax ? 'match' : '' }]);
    if (lot.repairCost) rows.push(['Ремонт по страховым', Fmt.won(lot.repairCost), { value: 'num warn' }]);
    add('Затопление', yesNo(lot.floodHistory), q?.floodHistory !== undefined);
    add('Тотал', yesNo(lot.totalLossHistory), q?.totalLossHistory !== undefined);
    if (lot.lienStatus) rows.push(['Залог', lot.lienStatus === 'clean' ? 'нет' : 'есть', { value: lot.lienStatus === 'clean' ? 'ok' : 'bad' }]);
    if (lot.seizureStatus) rows.push(['Арест', lot.seizureStatus === 'clean' ? 'нет' : 'есть', { value: lot.seizureStatus === 'clean' ? 'ok' : 'bad' }]);
    if (inspection) {
      const marks = [inspection.accident_detail, inspection.outer_detail].filter(Boolean);
      rows.push([`Осмотр${inspection.inspection_date ? ' ' + Fmt.day(inspection.inspection_date) : ''}`,
        marks.length ? Fmt.esc(marks.join('; ')) : 'замечаний нет', { value: inspection.accident_detail ? 'bad' : marks.length ? 'warn' : 'ok' }]);
      if (inspection.inspection_mileage) rows.push(['Пробег по осмотру', Fmt.km(inspection.inspection_mileage), { value: 'num' }]);
      if (inspection.valid_until) rows.push(['Осмотр действует до', Fmt.day(inspection.valid_until), { value: 'num' }]);
      const note = inspection.details?.inspector_note;
      if (note) rows.push(['Заметки инспектора', Fmt.esc(note)]);
    }
    if (!rows.length) return '';
    return `<section class="sect"><h3 class="sect__title">История</h3><div class="card">${kv(rows)}</div></section>`;
  }

  function specsBlock(lot, q) {
    const copy = (v, what) => v ? `<span class="num">${Fmt.esc(v)}</span><button class="copy" type="button" data-copy="${Fmt.esc(v)}" aria-label="Скопировать ${what}">копировать</button>` : null;
    const rows = [
      ['Кузов', Fmt.label('body_type', lot.bodyType), q?.bodyTypes],
      ['Цвет', Fmt.label('color', lot.color), q?.colors],
      ['Салон', Fmt.label('seat_color', lot.seatColor)],
      ['Мест', lot.seatCount],
      ['Комплектация', Fmt.trim(lot), q?.trim],
      ['Регистрация', lot.firstRegDate ? Fmt.month(lot.firstRegDate) : null],
      ['Выставлен', lot.listedAt ? Fmt.day(lot.listedAt) : null],
      ['Новая стоила', lot.retailValue ? Fmt.won(lot.retailValue) : null],
      ['Город', lot.location && lot.location !== 'Korea' ? Fmt.esc(lot.location) : null],
      ['VIN', copy(lot.vin, 'VIN')],
      ['Номер', copy(lot.plateNumber, 'номер')],
    ].filter(([, v]) => v !== null && v !== undefined && v !== '')
      .map(([k, v, match]) => [k, typeof v === 'string' && v.startsWith('<') ? v : Fmt.esc(v), { row: match && (Array.isArray(match) ? match.length : match) ? 'match' : '' }]);
    return rows.length ? `<section class="sect"><h3 class="sect__title">Автомобиль</h3><div class="card">${kv(rows)}</div></section>` : '';
  }

  function optionsBlock(lot, q) {
    const opts = Array.isArray(lot.options) ? lot.options.filter(o => o && typeof o === 'object') : [];
    if (!opts.length) return '';
    const wanted = new Set(q?.options ?? []);
    const groups = new Map();
    for (const o of opts) {
      const g = OPTION_GROUPS[o.category] ? o.category : 'other';
      if (!groups.has(g)) groups.set(g, []);
      groups.get(g).push(o);
    }
    const order = [...Object.keys(OPTION_GROUPS), 'other'].filter(g => groups.has(g));
    return `<section class="sect"><h3 class="sect__title"><span>Опции</span><span class="num">${opts.length}</span></h3>
      <div class="card opts">${order.map(g => `<div class="opts__group"><h4>${OPTION_GROUPS[g] ?? 'Прочее'}</h4>
        <div class="opts__list">${groups.get(g)
          .sort((a, b) => (wanted.has(b.code) ? 1 : 0) - (wanted.has(a.code) ? 1 : 0))
          .map(o => `<span class="opt${wanted.has(o.code) ? ' opt--match' : ''}">${Fmt.esc(o.name_ru || o.name_en || o.name_kr || o.code)}</span>`).join('')}</div></div>`).join('')}
      </div></section>`;
  }

  /**
   * @param {HTMLElement} mount
   * @param {object} lot
   * @param {{inspection?: object, query?: object, favorite?: boolean, compared?: boolean, closable?: 'back'|'close'|null,
   *          onClose?: Function, onShare: Function, onSource: Function, onFav: Function, onCompare: Function}} ctx
   */
  function render(mount, lot, ctx) {
    const q = ctx.query ?? null;
    const facts = [
      [Fmt.num(lot.mileage), 'км'],
      [Fmt.engine(lot).replace(' л', '') || '—', Fmt.lower(Fmt.label('fuel', lot.fuel)) || 'двигатель'],
      [lot.transmission === 'automatic' ? 'AT' : lot.transmission === 'manual' ? 'MT' : (Fmt.label('transmission', lot.transmission) || '—'),
        Fmt.lower(Fmt.label('drive_type', lot.driveType)) || 'привод'],
      [lot.firstRegDate ? Fmt.month(lot.firstRegDate) : (lot.year || '—'), lot.firstRegDate ? 'регистрация' : 'год'],
    ];
    mount.innerHTML = `
      ${ctx.closable ? `<div class="lot__bar">
        <button class="icon-btn" type="button" data-close aria-label="${ctx.closable === 'back' ? 'Назад' : 'Закрыть'}">${ctx.closable === 'back' ? Fmt.icon.back : Fmt.icon.close}</button>
        <span class="spacer"></span>
        <button class="icon-btn fav${ctx.favorite ? ' on' : ''}" type="button" data-fav aria-pressed="${!!ctx.favorite}" aria-label="Избранное">${Fmt.icon.heart}</button>
      </div>` : ''}
      ${gallery(lot)}
      <div class="lot__body">
        <div>
          <h2 class="lot__title">${Fmt.esc(Fmt.title(lot))}</h2>
          ${Fmt.trim(lot) ? `<div class="lot__trim">${Fmt.esc(Fmt.trim(lot))}</div>` : ''}
          <div class="lot__price">${Fmt.won(lot.price)}</div>
          <div class="lot__meta">${Fmt.esc([lot.sourceName, Fmt.city(lot), lot.listedAt ? 'выставлен ' + Fmt.day(lot.listedAt) : ''].filter(Boolean).join(' · '))}</div>
        </div>
        ${lot.lotUrl ? `<button class="btn btn--primary btn--block lot__source" type="button" data-source>${Fmt.icon.out}Открыть объявление на ${Fmt.esc(lot.sourceName || 'площадке')}</button>` : ''}
        ${lot.isActive === false ? '<div class="lot__inactive">Снят с продажи или больше не проходит правила отбора. Данные — на момент последнего обхода.</div>' : ''}
        <div class="facts">${facts.map(([b, s]) => `<div><b>${Fmt.esc(b)}</b><span>${Fmt.esc(s)}</span></div>`).join('')}</div>
        <div class="lot__tools">
          ${ctx.closable ? '' : `<button class="btn fav${ctx.favorite ? ' on' : ''}" type="button" data-fav aria-pressed="${!!ctx.favorite}">${Fmt.icon.heart}<span>${ctx.favorite ? 'В избранном' : 'В избранное'}</span></button>`}
          <button class="btn${ctx.compared ? ' on' : ''}" type="button" data-compare aria-pressed="${!!ctx.compared}">${Fmt.icon.compare}<span>${ctx.compared ? 'В сравнении' : 'Сравнить'}</span></button>
        </div>
        ${historyBlock(lot, ctx.inspection, q)}
        ${specsBlock(lot, q)}
        ${optionsBlock(lot, q)}
        <div class="lot__actions">
          <button class="btn" type="button" data-share>${Fmt.icon.share}Клиенту</button>
          <button class="btn btn--primary" type="button" data-source>Открыть на ${Fmt.esc(lot.sourceName || 'площадке')}</button>
        </div>
      </div>`;

    wireGallery(mount);
    mount.querySelector('[data-close]')?.addEventListener('click', () => ctx.onClose?.());
    mount.querySelectorAll('[data-fav]').forEach(b => b.addEventListener('click', () => ctx.onFav(b)));
    mount.querySelector('[data-compare]')?.addEventListener('click', e => ctx.onCompare(e.currentTarget));
    mount.querySelector('[data-share]')?.addEventListener('click', () => ctx.onShare());
    mount.querySelectorAll('[data-source]').forEach(b => b.addEventListener('click', () => ctx.onSource()));
    mount.querySelectorAll('[data-copy]').forEach(b => b.addEventListener('click', async () => {
      try { await navigator.clipboard.writeText(b.dataset.copy); App.toast('Скопировано'); } catch (_) { App.toast(b.dataset.copy); }
    }));
  }

  function setFavorite(mount, on) {
    mount.querySelectorAll('[data-fav]').forEach(b => {
      b.classList.toggle('on', on);
      b.setAttribute('aria-pressed', String(on));
      const label = b.querySelector('span');
      if (label) label.textContent = on ? 'В избранном' : 'В избранное';
    });
  }
  function setCompared(mount, on) {
    const b = mount.querySelector('[data-compare]');
    if (!b) return;
    b.classList.toggle('on', on);
    b.setAttribute('aria-pressed', String(on));
    b.querySelector('span').textContent = on ? 'В сравнении' : 'Сравнить';
  }

  function state(mount, text, closable, onClose) {
    mount.innerHTML = `${closable ? `<div class="lot__bar"><button class="icon-btn" type="button" data-close aria-label="Закрыть">${Fmt.icon.close}</button></div>` : ''}
      <div class="lot__state">${text}</div>`;
    mount.querySelector('[data-close]')?.addEventListener('click', () => onClose?.());
  }

  return { render, setFavorite, setCompared, state };
})();
