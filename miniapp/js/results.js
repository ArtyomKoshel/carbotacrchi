const Results = (() => {
  let lots           = [];
  let favorites      = new Set();
  let activeLot      = null;
  let searchedOptions = new Set(); // codes from current search query

  const CATEGORY_EMOJI = {
    exterior:    '🚗',
    safety:      '🛡️',
    convenience: '⭐',
    interior:    '🪑',
  };

  function setFavorites(ids) {
    favorites = new Set(ids);
  }

  function setSearchOptions(codes) {
    searchedOptions = new Set(Array.isArray(codes) ? codes : []);
  }

  function render(data, mount) {
    lots = data.lots ?? [];
    const total   = data.total  ?? 0;
    const errors  = data.errors ?? [];
    const relaxed = data.relaxed ?? false;
    const relaxedMessage = data.relaxedMessage ?? '';
    const isCustomMount = !!mount;

    if (!isCustomMount) {
      document.getElementById('results-count').textContent =
        `${total} ${ruLots(total)}`;
    }

    const banner = document.getElementById('errors-banner');
    if (!isCustomMount) {
      if (relaxed && relaxedMessage) {
        banner.innerHTML = `ℹ️ ${relaxedMessage}`;
        banner.className = 'errors-banner errors-banner--relaxed';
        banner.style.display = 'block';
      } else if (errors.length) {
        banner.innerHTML = `⚠️ Ошибка источников: ${errors.join(', ')}`;
        banner.className = 'errors-banner';
        banner.style.display = 'block';
      } else {
        banner.style.display = 'none';
      }
    }

    const grid = mount?.grid ?? document.getElementById('cards-grid');
    const emptyState = mount?.empty ?? document.getElementById('results-empty');

    if (!grid) return;

    if (!lots.length) {
      grid.innerHTML = '';
      if (emptyState) emptyState.style.display = 'flex';
      return;
    }
    if (emptyState) emptyState.style.display = 'none';
    grid.innerHTML = lots.map((lot, i) => renderCard(lot, i)).join('');

    grid.querySelectorAll('.lot-card').forEach((card, i) => {
      card.addEventListener('click', e => {
        if (e.target.closest('.lot-card__fav-btn')) return;
        openSheet(lots[i]);
      });
    });

    grid.querySelectorAll('.lot-card__fav-btn').forEach((btn, i) => {
      btn.addEventListener('click', () => toggleFav(lots[i], btn));
    });
  }

  function showSkeletons(n = 6) {
    const grid = document.getElementById('cards-grid');
    const emptyState = document.getElementById('results-empty');
    if (!grid) return;
    if (emptyState) emptyState.style.display = 'none';
    grid.innerHTML = Array.from({ length: n }, () => `
      <div class="lot-card lot-card--skeleton">
        <div class="lot-card__img"></div>
        <div class="lot-card__body">
          <div class="skeleton-line w80"></div>
          <div class="skeleton-line w55"></div>
          <div class="skeleton-line w35"></div>
        </div>
      </div>`).join('');
  }

  function appendCards(newLots) {
    if (!newLots || !newLots.length) return;
    const startIdx = lots.length;
    lots = lots.concat(newLots);

    const grid = document.getElementById('cards-grid');
    if (!grid) return;

    const fragment = document.createDocumentFragment();
    const temp = document.createElement('div');
    temp.innerHTML = newLots.map((lot, i) => renderCard(lot, startIdx + i)).join('');
    while (temp.firstChild) fragment.appendChild(temp.firstChild);
    grid.appendChild(fragment);

    const newCards = grid.querySelectorAll('.lot-card');
    for (let i = startIdx; i < newCards.length; i++) {
      const card = newCards[i];
      const lot = lots[i];
      card.addEventListener('click', e => {
        if (e.target.closest('.lot-card__fav-btn')) return;
        openSheet(lot);
      });
      const favBtn = card.querySelector('.lot-card__fav-btn');
      if (favBtn) favBtn.addEventListener('click', () => toggleFav(lot, favBtn));
    }
  }

  function renderCard(lot, i) {
    const price  = '$' + Number(lot.price).toLocaleString();
    const km     = Number(lot.mileage).toLocaleString() + ' km';
    const isFav  = favorites.has(lot.id);
    const imgSrc = lot.imageUrl ?? '/miniapp/img/placeholder.svg';
    const cardFields = new Set(Filters.getCardFields());

    return `
      <div class="lot-card" data-idx="${i}">
        <img class="lot-card__img" src="${imgSrc}" alt="${escHtml(lot.make)} ${escHtml(lot.model)}"
             onerror="this.src='/miniapp/img/placeholder.svg'">
        <button class="lot-card__fav-btn${isFav?' saved':''}" data-id="${escHtml(lot.id)}"
                aria-label="Сохранить">
          ${isFav ? heartFilled() : heartOutline()}
        </button>
        <div class="lot-card__body">
          <div class="lot-card__source">${escHtml(lot.sourceName)}</div>
          <div class="lot-card__title">${escHtml(lot.year)} ${escHtml(lot.make)} ${escHtml(lot.model)}</div>
          <div class="lot-card__price">${price}</div>
          <div class="lot-card__meta">
            <span class="lot-card__meta-item">
              <svg viewBox="0 0 16 16" fill="currentColor"><path d="M8 1a6 6 0 100 12A6 6 0 008 1zM0 8a8 8 0 1116 0A8 8 0 010 8z"/><path d="M8 4v4l2.5 2.5-1 1L7 8.5V4h1z"/></svg>
              ${escHtml(lot.auctionDate ?? '—')}
            </span>
            <span class="lot-card__meta-item">
              <svg viewBox="0 0 16 16" fill="currentColor"><path d="M8 0a8 8 0 100 16A8 8 0 008 0zm0 14A6 6 0 118 2a6 6 0 010 12z"/><path d="M7 4h2v5H7zM7 10h2v2H7z"/></svg>
              ${km}
            </span>
          </div>
          <div class="lot-card__tags">
            ${cardFields.has('has_accident') && lot.hasAccident  ? `<span class="lot-card__tag lot-card__tag--danger">Авария</span>`   : ''}
            ${cardFields.has('flood_history') && lot.floodHistory ? `<span class="lot-card__tag lot-card__tag--danger">Затоплен</span>` : ''}
            ${cardFields.has('owners_count') && lot.ownersCount  ? `<span class="lot-card__tag">${lot.ownersCount} влад.</span>`       : ''}
            ${cardFields.has('listed_at') && (lot.listedAt || lot.auctionDate) ? `<span class="lot-card__tag">📅 ${escHtml(lot.listedAt || lot.auctionDate)}</span>` : ''}
            ${cardFields.has('first_reg_date') && lot.firstRegDate ? `<span class="lot-card__tag">🗓 ${escHtml(lot.firstRegDate)}</span>` : ''}
            ${cardFields.has('body_type') && lot.bodyType     ? `<span class="lot-card__tag">${escHtml(Taxonomy.label('body_type', lot.bodyType))}</span>`       : ''}
            ${cardFields.has('transmission') && lot.transmission ? `<span class="lot-card__tag">${escHtml(Taxonomy.label('transmission', lot.transmission))}</span>`  : ''}
            ${cardFields.has('fuel') && lot.fuel         ? `<span class="lot-card__tag">${escHtml(Taxonomy.label('fuel', lot.fuel))}</span>`           : ''}
            ${cardFields.has('drive_type') && lot.driveType    ? `<span class="lot-card__tag">${escHtml(Taxonomy.label('drive_type', lot.driveType))}</span>`      : ''}
            ${cardFields.has('engine_volume') && lot.engineVolume && Number(lot.engineVolume) >= 0.5 ? `<span class="lot-card__tag">⚙️ ${lot.engineVolume}л</span>` : ''}
            ${cardFields.has('color') && lot.color           ? `<span class="lot-card__tag">🎨 ${escHtml(lot.color)}</span>` : ''}
            ${cardFields.has('seat_color') && lot.seatColor  ? `<span class="lot-card__tag">💺 ${escHtml(lot.seatColor)}</span>` : ''}
            ${cardFields.has('seat_count') && lot.seatCount  ? `<span class="lot-card__tag">${lot.seatCount} мест</span>` : ''}
            ${cardFields.has('trim') && lot.trim            ? `<span class="lot-card__tag">🏷 ${escHtml(Taxonomy.label('trim', lot.trim))}</span>` : ''}
            ${cardFields.has('generation') && lot.generation ? `<span class="lot-card__tag">🧬 ${escHtml(lot.generation)}</span>` : ''}
            ${cardFields.has('options') && Array.isArray(lot.options) && lot.options.length ? (() => {
                const names = lot.options.map(o => o.name_ru || o.name_en || o.name_kr || o.code).filter(Boolean);
                const shown = names.slice(0, 3).map(n => `<span class="lot-card__tag">⚙️ ${escHtml(n)}</span>`).join('');
                const rest  = names.length > 3 ? `<span class="lot-card__tag">+${names.length - 3}</span>` : '';
                return shown + rest;
              })() : ''}
          </div>
        </div>
      </div>`;
  }

  async function toggleFav(lot, btn) {
    TG.haptic('impact', 'light');
    try {
      if (favorites.has(lot.id)) {
        await API.removeFavorite(lot.id);
        favorites.delete(lot.id);
        btn.classList.remove('saved');
        btn.innerHTML = heartOutline();
        showToast('Удалено из избранного');
      } else {
        await API.addFavorite(lot.id, lot.source, lot);
        favorites.add(lot.id);
        btn.classList.add('saved');
        btn.innerHTML = heartFilled();
        showToast('Сохранено ❤️');
      }
    } catch (e) {
      showToast('Ошибка: ' + e.message);
    }
  }

  function fmtDate(raw) {
    if (!raw) return null;
    // ISO timestamp → only the date part: "2022-07-19T00:00:00Z" → "2022-07-19"
    const m = String(raw).match(/^(\d{4}-\d{2}-\d{2})/);
    return m ? m[1] : String(raw);
  }

  function openSheet(lot) {
    activeLot = lot;
    const overlay  = document.getElementById('sheet-overlay');
    const price    = '$' + Number(lot.price).toLocaleString();
    const km       = Number(lot.mileage).toLocaleString() + ' km';
    const imgSrc   = lot.imageUrl ?? '/miniapp/img/placeholder.svg';
    const isFav    = favorites.has(lot.id);
    const d = (label, value, extra = '') =>
      value ? `<div class="sheet-detail-item"${extra}>
        <span class="sheet-detail-label">${label}</span>
        <span class="sheet-detail-value">${value}</span>
      </div>` : '';

    document.getElementById('sheet-content').innerHTML = `
      <div class="sheet-header" id="sheet-drag-handle">
        <div class="sheet-handle"></div>
        <div class="sheet-header-row">
          <span class="sheet-header-source">${escHtml(lot.sourceName)}</span>
          <button class="sheet-close-btn" onclick="Results.closeSheet()">
            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.5">
              <path d="M18 6L6 18M6 6l12 12"/>
            </svg>
          </button>
        </div>
      </div>
      <img class="sheet-img" src="${imgSrc}" alt="${escHtml(lot.make)}"
           onerror="this.src='/miniapp/img/placeholder.svg'">
      <div class="sheet-body">
        <div class="sheet-title">${escHtml(lot.year)} ${escHtml(lot.make)} ${escHtml(lot.model)}</div>
        <div class="sheet-price">${price}</div>
        <div class="sheet-section-title">Основное</div>
        <div class="sheet-details">
          ${d('Пробег', km)}
          ${d('Дата аукциона', escHtml(lot.auctionDate ?? '—'))}
          ${d('Местоположение', escHtml(lot.location ?? '—'))}
        </div>

        <div class="sheet-section-title">Характеристики</div>
        <div class="sheet-details">
          ${d('Кузов',        lot.bodyType     ? escHtml(Taxonomy.label('body_type',    lot.bodyType))    : '')}
          ${d('КПП',          lot.transmission ? escHtml(Taxonomy.label('transmission', lot.transmission)) : '')}
          ${d('Топливо',      lot.fuel         ? escHtml(Taxonomy.label('fuel',         lot.fuel))        : '')}
          ${d('Привод',       lot.driveType    ? escHtml(Taxonomy.label('drive_type',   lot.driveType))   : '')}
          ${d('Двигатель',    lot.engineVolume && Number(lot.engineVolume) >= 0.5 ? `${lot.engineVolume} л` : '')}
          ${d('Цвет кузова',  lot.color        ? escHtml(Taxonomy.label('color', lot.color))           : '')}
          ${d('Цвет салона',  lot.seatColor    ? escHtml(Taxonomy.label('seat_color', lot.seatColor)) : '')}
          ${d('Мест',         lot.seatCount    ? String(lot.seatCount)                                : '')}
          ${d('Комплектация', lot.trim         ? escHtml(Taxonomy.label('trim', lot.trim))            : '')}
          ${d('Поколение',    lot.generation   ? escHtml(lot.generation)                              : '')}
        </div>

        <div class="sheet-section-title">История</div>
        <div class="sheet-details">
          ${lot.hasAccident  !== null && lot.hasAccident  !== undefined ? d('Авария (офиц.)', `<span style="color:${lot.hasAccident  ? 'var(--danger)' : 'var(--success)'}">${lot.hasAccident  ? 'Да' : 'Нет'}</span>`) : ''}
          ${lot.floodHistory !== null && lot.floodHistory !== undefined ? d('Затопление',     `<span style="color:${lot.floodHistory ? 'var(--danger)' : 'var(--success)'}">${lot.floodHistory ? 'Да' : 'Нет'}</span>`) : ''}
          ${d('Владельцев',   lot.ownersCount    ? String(lot.ownersCount)    : '')}
          ${d('Страховых',    lot.insuranceCount ? String(lot.insuranceCount) : '')}
          ${d('VIN', lot.vin ? `<span style="font-size:12px;font-family:monospace">${escHtml(lot.vin)}</span>` : '', ' style="grid-column:span 2"')}
          ${d('Документ', lot.document ? `<span style="font-size:12px">${escHtml(lot.document)}</span>` : '', ' style="grid-column:span 2"')}
          ${d('Рыночная цена',    lot.retailValue  ? `$${Number(lot.retailValue).toLocaleString()}`  : '')}
          ${d('Стоимость ремонта',lot.repairCost   ? `<span style="color:var(--danger)">$${Number(lot.repairCost).toLocaleString()}</span>` : '')}
          ${d('Номер',  lot.plateNumber ? `<span style="font-family:monospace">${escHtml(lot.plateNumber)}</span>` : '')}
          ${d('Дилер',  lot.dealerName  ? escHtml(lot.dealerName)  : '')}
          ${d('Телефон',lot.dealerPhone ? escHtml(lot.dealerPhone) : '')}
          ${lot.hasKeys !== null && lot.hasKeys !== undefined ? d('Ключи', lot.hasKeys ? 'Есть' : 'Нет') : ''}
        </div>
        ${Array.isArray(lot.options) && lot.options.length ? (() => {
          // Searched options first, then the rest
          const searched = lot.options.filter(o => searchedOptions.has(o.code));
          const rest     = lot.options.filter(o => !searchedOptions.has(o.code));
          const sorted   = [...searched, ...rest];
          const id       = `opts-${lot.id}`;

          const renderOpt = (o, highlight) => {
            const name  = escHtml(o.name_ru || o.name_en || o.name_kr || o.code);
            const emoji = CATEGORY_EMOJI[o.category] || '⚙️';
            const bg    = highlight ? 'rgba(51,144,236,.22)' : 'rgba(255,255,255,.05)';
            const ring  = highlight ? `border:1.5px solid var(--accent);` : 'border:1.5px solid transparent;';
            return `<div style="display:flex;flex-direction:column;align-items:center;gap:3px;text-align:center;background:${bg};${ring}border-radius:10px;padding:6px 4px">
              <span style="font-size:20px;line-height:1">${emoji}</span>
              <span style="font-size:9px;color:${highlight ? 'var(--accent)' : 'var(--hint)'};line-height:1.2;font-weight:${highlight ? 600 : 400}">${name}</span>
            </div>`;
          };

          const first10 = sorted.slice(0, 10);
          const more    = sorted.slice(10);
          const moreHtml = more.length ? `
            <div id="${id}-more" style="display:none;grid-column:1/-1;display:none">
              <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:8px">
                ${more.map(o => renderOpt(o, searchedOptions.has(o.code))).join('')}
              </div>
            </div>
            <button onclick="document.getElementById('${id}-more').style.display=document.getElementById('${id}-more').style.display==='none'?'block':'none';this.textContent=this.textContent.includes('▼')?'Скрыть ▲':'Ещё ${more.length} ▼'"
              style="grid-column:1/-1;background:none;border:none;color:var(--hint);font-size:11px;cursor:pointer;padding:4px 0">
              Ещё ${more.length} ▼
            </button>` : '';

          return `<div style="margin:12px 0 0">
            <div style="font-size:11px;font-weight:600;color:var(--accent);text-transform:uppercase;letter-spacing:.4px;padding:0 4px 6px">Опции (${sorted.length})</div>
            <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:8px;padding:0 4px">
              ${first10.map(o => renderOpt(o, searchedOptions.has(o.code))).join('')}
              ${moreHtml}
            </div>
          </div>`;
        })() : ''}
        <div id="sheet-inspection" style="margin:12px 0 12px"></div>
        <div class="sheet-actions">
          <a href="${escHtml(lot.lotUrl)}" target="_blank" rel="noopener"
             class="btn btn-primary" style="text-decoration:none;flex:1">Открыть лот</a>
          <button class="btn btn-secondary btn-sm" style="flex:none;padding:12px 16px"
                  onclick="Results.sheetToggleFav()"
                  id="sheet-fav-btn">${isFav ? '❤️' : '🤍'}</button>
        </div>
      </div>`;

    overlay.classList.add('open');
    TG.haptic('impact', 'medium');

    // Swipe-to-dismiss on the drag handle header
    const sheetEl  = document.getElementById('sheet-content');
    const dragArea = document.getElementById('sheet-drag-handle');
    if (sheetEl && dragArea) {
      let startY = 0;
      const onStart = e => { startY = e.touches[0].clientY; sheetEl.style.transition = 'none'; };
      const onMove  = e => {
        const dy = e.touches[0].clientY - startY;
        if (dy > 0) sheetEl.style.transform = `translateY(${dy}px)`;
      };
      const onEnd   = e => {
        sheetEl.style.transition = '';
        if (e.changedTouches[0].clientY - startY > 110) {
          closeSheet();
        } else {
          sheetEl.style.transform = '';
        }
        dragArea.removeEventListener('touchstart', onStart);
        dragArea.removeEventListener('touchmove',  onMove);
        dragArea.removeEventListener('touchend',   onEnd);
      };
      dragArea.addEventListener('touchstart', onStart, { passive: true });
      dragArea.addEventListener('touchmove',  onMove,  { passive: true });
      dragArea.addEventListener('touchend',   onEnd);
    }

    API.getInspection(lot.id).then(insp => {
      const el = document.getElementById('sheet-inspection');
      if (!el || !insp) return;
      const rows = [];
      if (insp.valid_until) rows.push(`<div class="sheet-detail-item"><span class="sheet-detail-label">Техосмотр до</span><span class="sheet-detail-value">${escHtml(fmtDate(insp.valid_until))}</span></div>`);
      if (insp.cert_no)     rows.push(`<div class="sheet-detail-item"><span class="sheet-detail-label">Номер акта</span><span class="sheet-detail-value" style="font-size:12px;font-family:monospace">${escHtml(insp.cert_no)}</span></div>`);
      if (insp.first_registration) rows.push(`<div class="sheet-detail-item"><span class="sheet-detail-label">1-я регистрация</span><span class="sheet-detail-value">${escHtml(fmtDate(insp.first_registration))}</span></div>`);
      if (insp.inspection_mileage)  rows.push(`<div class="sheet-detail-item"><span class="sheet-detail-label">Пробег (акт)</span><span class="sheet-detail-value">${Number(insp.inspection_mileage).toLocaleString()} km</span></div>`);
      if (insp.accident_detail) rows.push(`<div class="sheet-detail-item" style="grid-column:span 2"><span class="sheet-detail-label">Структурные повреждения</span><span class="sheet-detail-value" style="color:var(--danger)">${escHtml(insp.accident_detail)}</span></div>`);
      if (insp.outer_detail)    rows.push(`<div class="sheet-detail-item" style="grid-column:span 2"><span class="sheet-detail-label">Внешние ремонты</span><span class="sheet-detail-value">${escHtml(insp.outer_detail)}</span></div>`);
      const note = insp.details?.inspector_note;
      if (note) rows.push(`<div class="sheet-detail-item" style="grid-column:span 2"><span class="sheet-detail-label">Заметки инспектора</span><span class="sheet-detail-value" style="font-size:11px">${escHtml(note)}</span></div>`);
      if (rows.length) {
        el.innerHTML = `<div style="font-size:11px;font-weight:600;color:var(--accent);text-transform:uppercase;letter-spacing:.4px;padding:0 4px 4px">Инспекция</div><div class="sheet-details">${rows.join('')}</div>`;
      }
    }).catch(() => {});
  }

  function sheetToggleFav() {
    if (!activeLot) return;
    const btn = document.getElementById('sheet-fav-btn');
    if (!btn) return;
    if (favorites.has(activeLot.id)) {
      API.removeFavorite(activeLot.id).then(() => {
        favorites.delete(activeLot.id);
        btn.textContent = '🤍';
        showToast('Удалено из избранного');
        syncCardFavBtn(activeLot.id, false);
      });
    } else {
      API.addFavorite(activeLot.id, activeLot.source, activeLot).then(() => {
        favorites.add(activeLot.id);
        btn.textContent = '❤️';
        showToast('Сохранено ❤️');
        syncCardFavBtn(activeLot.id, true);
      });
    }
  }

  function syncCardFavBtn(id, saved) {
    const btn = document.querySelector(`.lot-card__fav-btn[data-id="${CSS.escape(id)}"]`);
    if (!btn) return;
    btn.classList.toggle('saved', saved);
    btn.innerHTML = saved ? heartFilled() : heartOutline();
  }

  function closeSheet() {
    const sheetEl = document.getElementById('sheet-content');
    if (sheetEl) sheetEl.style.transform = '';
    document.getElementById('sheet-overlay').classList.remove('open');
    activeLot = null;
  }

  function heartOutline() {
    return `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>`;
  }
  function heartFilled() {
    return `<svg viewBox="0 0 24 24" fill="currentColor"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>`;
  }

  function ruLots(n) {
    const mod10 = n % 10, mod100 = n % 100;
    if (mod10 === 1 && mod100 !== 11) return 'лот';
    if (mod10 >= 2 && mod10 <= 4 && (mod100 < 10 || mod100 >= 20)) return 'лота';
    return 'лотов';
  }

  function escHtml(v) {
    return String(v ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  }

  function showToast(msg) {
    const t = document.getElementById('toast');
    if (!t) return;
    t.textContent = msg;
    t.classList.add('show');
    setTimeout(() => t.classList.remove('show'), 2000);
  }

  return { render, appendCards, showSkeletons, setFavorites, setSearchOptions, closeSheet, sheetToggleFav };
})();
