const Subscriptions = (() => {
  let currentQuery  = null;
  let activeSubs    = [];

  function setCurrentQuery(query) {
    currentQuery = query;
    renderSubscribeBtn();
  }

  const IGNORE_KEYS = new Set(['limit', 'offset']);

  function normalizeQuery(q) {
    if (!q || typeof q !== 'object') return '{}';

    const normalizeArray = (arr) => {
      if (!Array.isArray(arr) || arr.length === 0) return undefined;
      return arr
        .map(v => String(v ?? '').trim())
        .filter(v => v !== '')
        .sort();
    };

    const normalized = {};
    for (const [key, value] of Object.entries(q)) {
      if (IGNORE_KEYS.has(key)) continue;
      if (value === undefined || value === null) continue;
      if (typeof value === 'string') {
        const s = value.trim();
        if (s === '') continue;
        normalized[key] = s;
        continue;
      }
      if (Array.isArray(value)) {
        const arr = normalizeArray(value);
        if (arr && arr.length) normalized[key] = arr;
        continue;
      }
      normalized[key] = value;
    }

    const sortedKeys = Object.keys(normalized).sort();
    const ordered = {};
    for (const key of sortedKeys) {
      ordered[key] = normalized[key];
    }

    return JSON.stringify(ordered);
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
    btn.hidden = !currentQuery;
    const subscribed = isSubscribed();
    btn.classList.toggle('on', subscribed);
    btn.setAttribute('aria-pressed', String(subscribed));
    btn.textContent = subscribed ? 'Слежу за поиском' : 'Следить за поиском';
    btn.title = subscribed ? 'Новые лоты по этому поиску приходят в Telegram. Нажмите, чтобы перестать.' : 'Присылать новые лоты по этому поиску в Telegram';
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
        showToast('Готово: новые лоты по этому поиску придут в Telegram');
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
          <div class="empty">
            <svg viewBox="0 0 24 24"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
            <b>Подписок пока нет</b>
            <span>Найдите машины и нажмите «Следить за поиском» над результатами.</span>
          </div>`;
        return;
      }
      container.innerHTML = activeSubs.map(s => renderSubCard(s)).join('');
      container.querySelectorAll('.sub-remove-btn').forEach(btn => {
        btn.addEventListener('click', () => removeById(parseInt(btn.dataset.id)));
      });
      container.querySelectorAll('.sub-view-btn').forEach(btn => {
        btn.addEventListener('click', () => {
          let query = {};
          try {
            query = JSON.parse(btn.dataset.query ?? '{}');
          } catch (_) {
            query = {};
          }
          viewResults(parseInt(btn.dataset.id), query);
        });
      });
      container.querySelectorAll('.mini-lot[data-id]').forEach(card => {
        card.addEventListener('click', () => {
          TG.haptic('impact', 'light');
          App.openLot(card.dataset.id);
        });
      });
    } catch (e) {
      container.innerHTML = API.isAuthError(e)
        ? BrowserAuth.authRequiredHtml()
        : `<div class="empty"><b>Не загрузилось</b><span>${escHtml(e.message)}</span></div>`;
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
        <div class="sub-previews__label">Новые лоты</div>
        <div class="sub-previews__strip">
          ${previews.map(p => renderMiniLot(p)).join('')}
        </div>
      </div>` : '';
    const queryPayload = escAttr(JSON.stringify(s.query ?? {}));

    return `
      <div class="sub-card">
        <div class="sub-card__top">
          <div class="sub-card__body">
            <div class="sub-card__label">${escHtml(s.label)} ${newBadge}</div>
            <div class="sub-card__meta">Последняя проверка: ${escHtml(checked)}</div>
            <div class="sub-card__meta">Создана: ${escHtml(date)}</div>
          </div>
          <div class="sub-card__actions">
            <button class="sub-view-btn" data-id="${s.id}" data-query="${queryPayload}"
                    title="Показать результаты">Показать</button>
            <button class="sub-remove-btn" data-id="${s.id}" title="Перестать следить">Удалить</button>
          </div>
        </div>
        ${previewStrip}
      </div>`;
  }

  function renderMiniLot(p) {
    const img    = p.imageUrl ?? '/miniapp/img/placeholder.svg';
    const price  = Fmt.price(p.price, p.currency);
    const urlAttr = p.id ? ` data-id="${escHtml(p.id)}"` : '';
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
    const newCount = activeSubs.reduce((sum, s) => sum + (s.new_lots_count || 0), 0);
    for (const id of ['subs-badge', 'subs-badge-tab']) {
      const badge = document.getElementById(id);
      if (badge) badge.textContent = newCount || '';
    }
  }

  function escHtml(v) {
    return String(v ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  }

  function escAttr(v) {
    return String(v ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  }

  function showToast(msg) { App.toast(msg, 3000); }

  async function viewResults(id, query) {
    TG.haptic('impact', 'light');
    API.markSeen(id).catch(() => {});
    const sub = activeSubs.find(s => s.id === id);
    if (sub) sub.new_lots_count = 0;
    renderBadge();
    Filters.applyQuery(query);
    await App.searchWithQuery(Filters.getQuery());
  }

  async function loadSubs() {
    try {
      activeSubs = await API.getSubscriptions() ?? [];
      renderBadge();
      renderSubscribeBtn();
    } catch (_) {}
  }

  return { setCurrentQuery, toggleSubscribe, loadAndRender, loadSubs };
})();
