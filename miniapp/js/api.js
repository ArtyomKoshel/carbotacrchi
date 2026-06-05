const API = (() => {
  const BASE = '/api';
  const FILTERS_CACHE_KEY = 'carbot_filters_v5'; // v5: Russian labels
  const FILTERS_CACHE_TTL = 10 * 60 * 1000; // 10 minutes in ms

  async function request(method, path, body = null) {
    const opts = {
      method,
      headers: { 'Content-Type': 'application/json' },
    };
    if (body) opts.body = JSON.stringify(body);

    const res = await fetch(BASE + path, opts);

    let json = null;
    try {
      json = await res.json();
    } catch (_) {
      json = null;
    }

    if (!res.ok) {
      const err = json?.error ?? `HTTP ${res.status}`;
      throw new Error(err);
    }

    if (!json || json.ok !== true) {
      throw new Error(json?.error ?? 'Server error');
    }

    return json.data;
  }

  // ── Filters with localStorage cache ────────────────────────────────────────

  function _readFiltersCache() {
    try {
      const raw = localStorage.getItem(FILTERS_CACHE_KEY);
      if (!raw) return null;
      const { ts, data } = JSON.parse(raw);
      if (Date.now() - ts > FILTERS_CACHE_TTL) return null;
      return data;
    } catch (_) {
      return null;
    }
  }

  function _writeFiltersCache(data) {
    try {
      localStorage.setItem(FILTERS_CACHE_KEY, JSON.stringify({ ts: Date.now(), data }));
    } catch (_) {}
  }

  async function _fetchFilters(locale) {
    const p = new URLSearchParams();
    if (locale) p.set('locale', locale);
    const data = await request('GET', `/filters?${p}`);
    _writeFiltersCache(data);
    return data;
  }

  async function getFilters(locale = 'ru') {
    const cached = _readFiltersCache();
    if (cached) {
      _fetchFilters(locale).catch(() => {});
      return cached;
    }
    return _fetchFilters(locale);
  }

  function invalidateFiltersCache() {
    try { localStorage.removeItem(FILTERS_CACHE_KEY); } catch (_) {}
  }

  function isFiltersCached() {
    return _readFiltersCache() !== null;
  }

  // ── Auth helpers ──────────────────────────────────────────────────────────
  // Returns auth fields to merge into POST/DELETE bodies.
  // In browser mode: passes browser_token; middleware injects correct user_id.
  // In Telegram mode: passes user_id + init_data as before.

  function _authBody() {
    if (typeof BrowserAuth !== 'undefined' && BrowserAuth.isBrowserMode()) {
      const token = localStorage.getItem('carbot_browser_token') || '';
      return { user_id: BrowserAuth.getChatId(), browser_token: token };
    }
    return { user_id: TG.getUserId(), init_data: TG.getInitData() };
  }

  function _authQuery() {
    if (typeof BrowserAuth !== 'undefined' && BrowserAuth.isBrowserMode()) {
      const token = localStorage.getItem('carbot_browser_token') || '';
      return `user_id=${BrowserAuth.getChatId()}&browser_token=${encodeURIComponent(token)}`;
    }
    return `user_id=${TG.getUserId()}&init_data=${encodeURIComponent(TG.getInitData())}`;
  }

  // ── Browser ↔ Telegram linking ────────────────────────────────────────────

  function browserAuthInit() {
    return request('POST', '/auth/browser-init');
  }

  function browserAuthStatus(token) {
    return request('GET', `/auth/browser-status?token=${encodeURIComponent(token)}`);
  }

  // ── Search ─────────────────────────────────────────────────────────────────

  function search(query, offset = 0) {
    const q = offset > 0 ? { ...query, offset } : query;
    return request('POST', '/search', { ..._authBody(), query: q });
  }

  // ── AI Chat search ─────────────────────────────────────────────────────────

  function searchChat(text) {
    return request('POST', '/search-chat', { ..._authBody(), text });
  }

  function resetChat() {
    return request('POST', '/search-chat/reset', _authBody());
  }

  // ── Filters count & context ────────────────────────────────────────────────

  function getCount(query) {
    return request('POST', '/filters/count', { query });
  }

  function getContext(params = {}) {
    const p = new URLSearchParams({ status: 'active', locale: 'ru', ...params });
    return request('GET', `/filters/context?${p}`);
  }

  function getTrims(make, model, locale = 'ru') {
    const p = new URLSearchParams();
    if (make) p.set('make', make);
    if (model) p.set('model', model);
    if (locale) p.set('locale', locale);
    return request('GET', `/filters/trims?${p}`);
  }

  // ── Favorites ──────────────────────────────────────────────────────────────

  function getFavorites() {
    return request('GET', `/favorites?${_authQuery()}`);
  }

  function addFavorite(lotId, source, lotData) {
    return request('POST', '/favorites', { ..._authBody(), lot_id: lotId, source, lot_data: lotData });
  }

  function removeFavorite(lotId) {
    return request('DELETE', `/favorites/${encodeURIComponent(lotId)}?${_authQuery()}`);
  }

  // ── Subscriptions ──────────────────────────────────────────────────────────

  function getSubscriptions() {
    return request('GET', `/subscriptions?${_authQuery()}`);
  }

  function subscribe(query) {
    return request('POST', '/subscriptions', { ..._authBody(), query });
  }

  function unsubscribe(id) {
    return request('DELETE', `/subscriptions/${id}?${_authQuery()}`);
  }

  function markSeen(id) {
    return request('POST', `/subscriptions/${id}/seen`, _authBody());
  }

  // ── Inspection ─────────────────────────────────────────────────────────────

  async function getInspection(lotId) {
    const res = await fetch(`${BASE}/lots/${encodeURIComponent(lotId)}/inspection`);
    if (res.status === 404) return null;
    const json = await res.json();
    return json?.data ?? null;
  }

  return {
    getFilters, invalidateFiltersCache, isFiltersCached,
    browserAuthInit, browserAuthStatus,
    search,
    searchChat, resetChat,
    getCount, getContext, getTrims,
    getFavorites, addFavorite, removeFavorite,
    getSubscriptions, subscribe, unsubscribe, markSeen,
    getInspection,
  };
})();
