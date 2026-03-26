const Results = (() => {
  let lots       = [];
  let favorites  = new Set();
  let activeLot  = null;

  function setFavorites(ids) {
    favorites = new Set(ids);
  }

  function render(data) {
    lots = data.lots ?? [];
    const total  = data.total  ?? 0;
    const errors = data.errors ?? [];

    document.getElementById('results-count').textContent =
      `${total} ${ruLots(total)}`;

    const banner = document.getElementById('errors-banner');
    if (errors.length) {
      banner.textContent = `⚠️ Ошибка источников: ${errors.join(', ')}`;
      banner.style.display = 'block';
    } else {
      banner.style.display = 'none';
    }

    const grid = document.getElementById('cards-grid');
    if (!lots.length) {
      grid.innerHTML = '';
      document.getElementById('results-empty').style.display = 'flex';
      return;
    }
    document.getElementById('results-empty').style.display = 'none';
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

  function renderCard(lot, i) {
    const price  = '$' + Number(lot.price).toLocaleString();
    const km     = Number(lot.mileage).toLocaleString() + ' km';
    const isFav  = favorites.has(lot.id);
    const imgSrc = lot.imageUrl ?? '/miniapp/img/placeholder.svg';

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
          ${lot.damage ? `<div class="lot-card__damage">⚡ ${escHtml(lot.damage)}</div>` : ''}
          <div class="lot-card__tags">
            ${lot.bodyType     ? `<span class="lot-card__tag">${escHtml(lot.bodyType)}</span>`     : ''}
            ${lot.transmission ? `<span class="lot-card__tag">${escHtml(lot.transmission)}</span>` : ''}
            ${lot.fuel         ? `<span class="lot-card__tag">${escHtml(lot.fuel)}</span>`         : ''}
            ${lot.driveType    ? `<span class="lot-card__tag">${escHtml(lot.driveType)}</span>`    : ''}
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

  function openSheet(lot) {
    activeLot = lot;
    const overlay = document.getElementById('sheet-overlay');
    const price   = '$' + Number(lot.price).toLocaleString();
    const km      = Number(lot.mileage).toLocaleString() + ' km';
    const imgSrc  = lot.imageUrl ?? '/miniapp/img/placeholder.svg';
    const isFav   = favorites.has(lot.id);

    document.getElementById('sheet-content').innerHTML = `
      <div class="sheet-handle"></div>
      <img class="sheet-img" src="${imgSrc}" alt="${escHtml(lot.make)}"
           onerror="this.src='/miniapp/img/placeholder.svg'">
      <div class="sheet-body">
        <div class="sheet-source">${escHtml(lot.sourceName)}</div>
        <div class="sheet-title">${escHtml(lot.year)} ${escHtml(lot.make)} ${escHtml(lot.model)}</div>
        <div class="sheet-price">${price}</div>
        <div class="sheet-details">
          <div class="sheet-detail-item">
            <span class="sheet-detail-label">Пробег</span>
            <span class="sheet-detail-value">${km}</span>
          </div>
          <div class="sheet-detail-item">
            <span class="sheet-detail-label">Дата аукциона</span>
            <span class="sheet-detail-value">${escHtml(lot.auctionDate ?? '—')}</span>
          </div>
          <div class="sheet-detail-item">
            <span class="sheet-detail-label">Местоположение</span>
            <span class="sheet-detail-value">${escHtml(lot.location ?? '—')}</span>
          </div>
          <div class="sheet-detail-item">
            <span class="sheet-detail-label">Статус</span>
            <span class="sheet-detail-value">${escHtml(lot.title ?? '—')}</span>
          </div>
          ${lot.bodyType ? `<div class="sheet-detail-item">
            <span class="sheet-detail-label">Кузов</span>
            <span class="sheet-detail-value">${escHtml(lot.bodyType)}</span>
          </div>` : ''}
          ${lot.transmission ? `<div class="sheet-detail-item">
            <span class="sheet-detail-label">КПП</span>
            <span class="sheet-detail-value">${escHtml(lot.transmission)}</span>
          </div>` : ''}
          ${lot.fuel ? `<div class="sheet-detail-item">
            <span class="sheet-detail-label">Топливо</span>
            <span class="sheet-detail-value">${escHtml(lot.fuel)}</span>
          </div>` : ''}
          ${lot.driveType ? `<div class="sheet-detail-item">
            <span class="sheet-detail-label">Привод</span>
            <span class="sheet-detail-value">${escHtml(lot.driveType)}</span>
          </div>` : ''}
          ${lot.engineVolume ? `<div class="sheet-detail-item">
            <span class="sheet-detail-label">Двигатель</span>
            <span class="sheet-detail-value">${lot.engineVolume} л${lot.cylinders ? ' / ' + lot.cylinders + ' цил.' : ''}</span>
          </div>` : ''}
          ${lot.color ? `<div class="sheet-detail-item">
            <span class="sheet-detail-label">Цвет</span>
            <span class="sheet-detail-value">${escHtml(lot.color)}</span>
          </div>` : ''}
          ${lot.trim ? `<div class="sheet-detail-item">
            <span class="sheet-detail-label">Комплектация</span>
            <span class="sheet-detail-value">${escHtml(lot.trim)}</span>
          </div>` : ''}
          ${lot.hasKeys !== null && lot.hasKeys !== undefined ? `<div class="sheet-detail-item">
            <span class="sheet-detail-label">Ключи</span>
            <span class="sheet-detail-value">${lot.hasKeys ? 'Есть' : 'Нет'}</span>
          </div>` : ''}
          ${lot.vin ? `<div class="sheet-detail-item" style="grid-column:span 2">
            <span class="sheet-detail-label">VIN</span>
            <span class="sheet-detail-value" style="font-size:12px;font-family:monospace">${escHtml(lot.vin)}</span>
          </div>` : ''}
          ${lot.damage ? `<div class="sheet-detail-item" style="grid-column:span 2">
            <span class="sheet-detail-label">Повреждения</span>
            <span class="sheet-detail-value" style="color:var(--danger)">${escHtml(lot.damage)}</span>
          </div>` : ''}
          ${lot.secondaryDamage ? `<div class="sheet-detail-item" style="grid-column:span 2">
            <span class="sheet-detail-label">Доп. повреждения</span>
            <span class="sheet-detail-value" style="color:var(--danger)">${escHtml(lot.secondaryDamage)}</span>
          </div>` : ''}
          ${lot.document ? `<div class="sheet-detail-item" style="grid-column:span 2">
            <span class="sheet-detail-label">Документ</span>
            <span class="sheet-detail-value" style="font-size:12px">${escHtml(lot.document)}</span>
          </div>` : ''}
          ${lot.retailValue ? `<div class="sheet-detail-item">
            <span class="sheet-detail-label">Рыночная цена</span>
            <span class="sheet-detail-value">$${Number(lot.retailValue).toLocaleString()}</span>
          </div>` : ''}
          ${lot.repairCost ? `<div class="sheet-detail-item">
            <span class="sheet-detail-label">Стоимость ремонта</span>
            <span class="sheet-detail-value" style="color:var(--danger)">$${Number(lot.repairCost).toLocaleString()}</span>
          </div>` : ''}
        </div>
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

  return { render, setFavorites, closeSheet, sheetToggleFav };
})();
