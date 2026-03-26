const Subscriptions = (() => {
  let currentQuery  = null;
  let activeSubs    = [];

  function setCurrentQuery(query) {
    currentQuery = query;
    renderSubscribeBtn();
  }

  function normalizeQuery(q) {
    const sources = (q.sources ?? []).slice().sort();
    const n = {};
    if (q.make     && q.make !== '')  n.make     = String(q.make).trim();
    if (q.model    && q.model !== '') n.model    = String(q.model).trim();
    if (q.yearFrom && q.yearFrom > 0) n.yearFrom = Number(q.yearFrom);
    if (q.yearTo   && q.yearTo   > 0) n.yearTo   = Number(q.yearTo);
    if (q.priceMax && q.priceMax > 0) n.priceMax = Number(q.priceMax);
    if (sources.length)               n.sources  = sources;
    return JSON.stringify(n);
  }

  function isSubscribed() {
    if (!currentQuery || !activeSubs.length) return false;
    const qStr = normalizeQuery(currentQuery);
    return activeSubs.some(s => normalizeQuery(s.query) === qStr);
  }

  function getSubscriptionId() {
    if (!currentQuery) return null;
    const qStr = normalizeQuery(currentQuery);
    const s = activeSubs.find(sub => normalizeQuery(sub.query) === qStr);
    return s ? s.id : null;
  }

  function renderSubscribeBtn() {
    const btn = document.getElementById('subscribe-btn');
    if (!btn) return;
    if (!currentQuery) {
      btn.style.display = 'none';
      return;
    }
    btn.style.display = 'flex';
    const subscribed = isSubscribed();
    btn.classList.toggle('subscribed', subscribed);
    btn.innerHTML = subscribed
      ? `<svg viewBox="0 0 24 24" width="15" height="15" fill="currentColor"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/><circle cx="18" cy="6" r="3" fill="var(--success)"/></svg>Подписан`
      : `<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>Подписаться`;
  }

  async function toggleSubscribe() {
    if (!currentQuery) return;
    TG.haptic('impact', 'medium');

    const btn = document.getElementById('subscribe-btn');
    if (btn) btn.disabled = true;

    try {
      if (isSubscribed()) {
        const id = getSubscriptionId();
        await API.unsubscribe(id);
        activeSubs = activeSubs.filter(s => s.id !== id);
        showToast('Подписка удалена');
      } else {
        const data = await API.subscribe(currentQuery);
        activeSubs.push({ id: data.id, query: currentQuery, label: data.label });
        showToast('🔔 Подписка оформлена! Вы получите уведомление о новых лотах.');
        TG.haptic('notification', 'success');
      }
      renderSubscribeBtn();
      renderBadge();
    } catch (e) {
      showToast('Ошибка: ' + e.message);
    } finally {
      if (btn) btn.disabled = false;
    }
  }

  async function loadAndRender() {
    const container = document.getElementById('subs-list');
    if (!container) return;
    container.innerHTML = '<div class="loading-overlay"><div class="spinner"></div><span>Загрузка…</span></div>';

    try {
      activeSubs = await API.getSubscriptions() ?? [];
      renderBadge();
      if (!activeSubs.length) {
        container.innerHTML = `
          <div class="empty-state">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
              <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
              <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
            </svg>
            <p>Нет активных подписок.<br>Найдите лоты и нажмите <b>Подписаться</b>.</p>
          </div>`;
        return;
      }
      container.innerHTML = activeSubs.map(s => renderSubCard(s)).join('');
      container.querySelectorAll('.sub-remove-btn').forEach(btn => {
        btn.addEventListener('click', () => removeById(parseInt(btn.dataset.id)));
      });
      container.querySelectorAll('.sub-view-btn').forEach(btn => {
        btn.addEventListener('click', () => viewResults(parseInt(btn.dataset.id), JSON.parse(btn.dataset.query)));
      });
      container.querySelectorAll('.mini-lot[data-url]').forEach(card => {
        card.addEventListener('click', () => {
          TG.haptic('impact', 'light');
          window.open(card.dataset.url, '_blank');
        });
      });
    } catch (e) {
      container.innerHTML = `<div class="empty-state"><p style="color:var(--danger)">${e.message}</p></div>`;
    }
  }

  function renderSubCard(s) {
    const date    = s.created_at      ? new Date(s.created_at).toLocaleDateString()   : '';
    const checked = s.last_checked_at ? new Date(s.last_checked_at).toLocaleString()  : 'Ещё не проверялось';
    const newBadge = s.new_lots_count > 0
      ? `<span class="sub-new-badge">+${s.new_lots_count} новых</span>` : '';
    const previews = (s.new_lot_previews ?? []);
    const previewStrip = previews.length ? `
      <div class="sub-previews">
        <div class="sub-previews__label">🆕 Новые лоты</div>
        <div class="sub-previews__strip">
          ${previews.map(p => renderMiniLot(p)).join('')}
        </div>
      </div>` : '';
    return `
      <div class="sub-card">
        <div class="sub-card__top">
          <div class="sub-card__icon">🔔</div>
          <div class="sub-card__body">
            <div class="sub-card__label">${escHtml(s.label)} ${newBadge}</div>
            <div class="sub-card__meta">Последняя проверка: ${escHtml(checked)}</div>
            <div class="sub-card__meta">Создана: ${escHtml(date)}</div>
          </div>
          <div class="sub-card__actions">
            <button class="sub-view-btn" data-id="${s.id}" data-query='${JSON.stringify(s.query)}'
                    title="Просмотреть результаты">🔍</button>
            <button class="sub-remove-btn" data-id="${s.id}" title="Отписаться">✕</button>
          </div>
        </div>
        ${previewStrip}
      </div>`;
  }

  function renderMiniLot(p) {
    const img    = p.imageUrl ?? '/miniapp/img/placeholder.svg';
    const price  = '$' + Number(p.price).toLocaleString();
    const urlAttr = p.lotUrl ? ` data-url="${escHtml(p.lotUrl)}"` : '';
    return `
      <div class="mini-lot"${urlAttr}>
        <img class="mini-lot__img" src="${escHtml(img)}"
             onerror="this.src='/miniapp/img/placeholder.svg'"
             alt="${escHtml(p.make)} ${escHtml(p.model)}">
        <div class="mini-lot__body">
          <div class="mini-lot__title">${escHtml(p.year)} ${escHtml(p.make)} ${escHtml(p.model)}</div>
          <div class="mini-lot__price">${price}</div>
          <div class="mini-lot__source">${escHtml(p.sourceName ?? '')}</div>
        </div>
      </div>`;
  }

  async function removeById(id) {
    TG.haptic('impact', 'light');
    try {
      await API.unsubscribe(id);
      activeSubs = activeSubs.filter(s => s.id !== id);
      renderBadge();
      renderSubscribeBtn();
      showToast('Подписка удалена');
      loadAndRender();
    } catch (e) {
      showToast('Ошибка: ' + e.message);
    }
  }

  function renderBadge() {
    const badge = document.getElementById('subs-badge');
    if (!badge) return;
    badge.textContent = activeSubs.length || '';
    badge.style.display = activeSubs.length ? 'inline-block' : 'none';
  }

  function escHtml(v) {
    return String(v ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  }

  function showToast(msg) {
    const t = document.getElementById('toast');
    if (!t) return;
    t.textContent = msg;
    t.classList.add('show');
    setTimeout(() => t.classList.remove('show'), 2400);
  }

  async function viewResults(id, query) {
    TG.haptic('impact', 'light');
    API.markSeen(id).catch(() => {});
    const sub = activeSubs.find(s => s.id === id);
    if (sub) sub.new_lots_count = 0;
    renderBadge();
    await App.searchWithQuery(query);
  }

  return { setCurrentQuery, toggleSubscribe, loadAndRender };
})();
