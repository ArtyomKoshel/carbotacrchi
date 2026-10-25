const API = (() => {
  const BASE = '/api';
  const FILTERS_CACHE_KEY = 'carbot_filters_v6'; // v6: makes from the reference catalog
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
      const err = new Error(json?.error ?? `HTTP ${res.status}`);
      err.status = res.status;
      throw err;
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
      if (Date.now() - ts > FILTERS_CACHE_TTL || !_hasMakes(data)) return null;
      return data;
    } catch (_) {
      return null;
    }
  }

  // an answer without makes (the server mid-deploy) is shown once, never kept
  const _hasMakes = data => Array.isArray(data?.makeOptions) && data.makeOptions.length > 0;

  function _writeFiltersCache(data) {
    if (!_hasMakes(data)) return;
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

  // In a browser that is not linked to Telegram these endpoints can only return 401,
  // so fail locally instead of sending user_id=0 with an empty token.
  function _requireAuth() {
    if (typeof BrowserAuth !== 'undefined' && BrowserAuth.isBrowserMode() && !BrowserAuth.isLinked()) {
      const err = new Error('Нужен вход через Telegram');
      err.status = 401;
      throw err;
    }
  }

  function isAuthError(e) {
    return e?.status === 401;
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

  /** Local sign-in as the test user: exists only when the server runs with APP_ENV=local. */
  function browserAuthDevLogin() {
    return request('POST', '/auth/dev-login');
  }

  function browserAuthStatus(token) {
    return request('GET', `/auth/browser-status?token=${encodeURIComponent(token)}`);
  }

  // ── Search ─────────────────────────────────────────────────────────────────

  async function search(query, offset = 0) {
    _requireAuth();
    const q = offset > 0 ? { ...query, offset } : query;
    return request('POST', '/search', { ..._authBody(), query: q });
  }

  // ── AI Chat search ─────────────────────────────────────────────────────────

  async function searchChat(text) {
    _requireAuth();
    return request('POST', '/search-chat', { ..._authBody(), text });
  }

  async function resetChat() {
    _requireAuth();
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


  // ── Reference catalog (makes → models → generations → modifications) ──────

  function getCatalogMakes() {
    return request('GET', '/catalog');
  }

  function getCatalog(makeId) {
    return request('GET', `/catalog/${encodeURIComponent(makeId)}`);
  }

  // ── Favorites ──────────────────────────────────────────────────────────────

  async function getFavorites() {
    _requireAuth();
    return request('GET', `/favorites?${_authQuery()}`);
  }

  async function addFavorite(lotId, source, lotData) {
    _requireAuth();
    return request('POST', '/favorites', { ..._authBody(), lot_id: lotId, source, lot_data: lotData });
  }

  async function removeFavorite(lotId) {
    _requireAuth();
    return request('DELETE', `/favorites/${encodeURIComponent(lotId)}?${_authQuery()}`);
  }

  // ── Subscriptions ──────────────────────────────────────────────────────────

  async function getSubscriptions() {
    _requireAuth();
    return request('GET', `/subscriptions?${_authQuery()}`);
  }

  async function subscribe(query) {
    _requireAuth();
    return request('POST', '/subscriptions', { ..._authBody(), query });
  }

  async function unsubscribe(id) {
    _requireAuth();
    return request('DELETE', `/subscriptions/${id}?${_authQuery()}`);
  }

  async function markSeen(id) {
    _requireAuth();
    return request('POST', `/subscriptions/${id}/seen`, _authBody());
  }

  // ── Inspection ─────────────────────────────────────────────────────────────

  async function getInspection(lotId) {
    const res = await fetch(`${BASE}/lots/${encodeURIComponent(lotId)}/inspection`);
    if (res.status === 404) return null;
    const json = await res.json();
    return json?.data ?? null;
  }

  // ── Lot by id, sending to a client ─────────────────────────────────────────

  async function getLot(lotId) {
    _requireAuth();
    return request('GET', `/lots/${encodeURIComponent(lotId)}?${_authQuery()}`);
  }

  /** mode: 'prepare' (Telegram chat picker), 'chat' (to the picker's own chat), 'text' (copy). */
  async function shareLot(lotId, mode) {
    _requireAuth();
    return request('POST', `/lots/${encodeURIComponent(lotId)}/share`, { ..._authBody(), mode });
  }

  return {
    getLot, shareLot,
    getFilters, invalidateFiltersCache, isFiltersCached,
    browserAuthInit, browserAuthStatus, browserAuthDevLogin, isAuthError,
    search,
    searchChat, resetChat,
    getCount, getContext,
    getCatalogMakes, getCatalog,
    getFavorites, addFavorite, removeFavorite,
    getSubscriptions, subscribe, unsubscribe, markSeen,
    getInspection,
  };
})();
