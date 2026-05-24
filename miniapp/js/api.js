const API = (() => {
  const BASE = '/api';

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

  function getFilters(locale = 'ru') {
    const p = new URLSearchParams();
    if (locale) p.set('locale', locale);
    return request('GET', `/filters?${p}`);
  }

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

  function getTrims(make, model, locale = 'ru') {
    const p = new URLSearchParams();
    if (make) p.set('make', make);
    if (model) p.set('model', model);
    if (locale) p.set('locale', locale);
    return request('GET', `/filters/trims?${p}`);
  }

  async function getInspection(lotId) {
    const res = await fetch(`${BASE}/lots/${encodeURIComponent(lotId)}/inspection`);
    if (res.status === 404) return null;
    const json = await res.json();
    return json?.data ?? null;
  }

  return { getFilters, getTrims, search, getFavorites, addFavorite, removeFavorite, getSubscriptions, subscribe, unsubscribe, markSeen, getInspection };
})();
