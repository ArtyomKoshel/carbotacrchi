const API = (() => {
  const BASE = '/api';
  const FILTERS_CACHE_KEY = 'carbot_filters_v3';
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

  /**
   * Returns filters data.
   * - If localStorage cache is fresh → returns immediately (< 1ms), refreshes in background.
   * - Otherwise → fetches from server (first open or cache expired).
   */
  async function getFilters(locale = 'ru') {
    const cached = _readFiltersCache();
    if (cached) {
      // Serve stale immediately, refresh in background
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

  // ── Other API methods ───────────────────────────────────────────────────────

  function search(query, offset = 0) {
    const q = offset > 0 ? { ...query, offset } : query;
    return request('POST', '/search', {
      user_id: TG.getUserId(),
      init_data: TG.getInitData(),
      query: q,
    });
  }

  function getFavorites() {
    return request('GET', `/favorites?user_id=${TG.getUserId()}&init_data=${encodeURIComponent(TG.getInitData())}`);
  }

  function addFavorite(lotId, source, lotData) {
    return request('POST', '/favorites', {
      user_id: TG.getUserId(),
      init_data: TG.getInitData(),
      lot_id: lotId,
      source,
      lot_data: lotData,
    });
  }

  function removeFavorite(lotId) {
    return request('DELETE', `/favorites/${encodeURIComponent(lotId)}?user_id=${TG.getUserId()}&init_data=${encodeURIComponent(TG.getInitData())}`);
  }

  function getSubscriptions() {
    return request('GET', `/subscriptions?user_id=${TG.getUserId()}&init_data=${encodeURIComponent(TG.getInitData())}`);
  }

  function subscribe(query) {
    return request('POST', '/subscriptions', {
      user_id:   TG.getUserId(),
      init_data: TG.getInitData(),
      query,
    });
  }

  function unsubscribe(id) {
    return request('DELETE', `/subscriptions/${id}?user_id=${TG.getUserId()}&init_data=${encodeURIComponent(TG.getInitData())}`);
  }

  function markSeen(id) {
    return request('POST', `/subscriptions/${id}/seen`, {
      user_id:   TG.getUserId(),
      init_data: TG.getInitData(),
    });
  }

  function searchChat(text) {
    return request('POST', '/search-chat', {
      text,
      user_id:   TG.getUserId(),
      init_data: TG.getInitData(),
    });
  }

  function resetChat() {
    return request('POST', '/search-chat/reset', {
      user_id:   TG.getUserId(),
      init_data: TG.getInitData(),
    });
  }

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

  function searchChat(text) {
    return request('POST', '/search-chat', {
      user_id:   TG.getUserId(),
      init_data: TG.getInitData(),
      text,
    });
  }

  async function getInspection(lotId) {
    const res = await fetch(`${BASE}/lots/${encodeURIComponent(lotId)}/inspection`);
    if (res.status === 404) return null;
    const json = await res.json();
    return json?.data ?? null;
  }

  return {
    getFilters, invalidateFiltersCache, isFiltersCached,
    searchChat, resetChat,
    getCount, getContext, getTrims,
    search, searchChat,
    getFavorites, addFavorite, removeFavorite,
    getSubscriptions, subscribe, unsubscribe, markSeen,
    getInspection,
  };
})();
