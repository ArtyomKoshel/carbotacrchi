const API = (() => {
  const BASE = '/api';

  async function request(method, path, body = null) {
    const opts = {
      method,
      headers: { 'Content-Type': 'application/json' },
    };
    if (body) opts.body = JSON.stringify(body);

    const res = await fetch(BASE + path, opts);
    const json = await res.json();
    if (!json.ok) throw new Error(json.error ?? 'Server error');
    return json.data;
  }

  function getFilters() {
    return request('GET', '/filters');
  }

  function search(query) {
    return request('POST', '/search', {
      user_id: TG.getUserId(),
      init_data: TG.getInitData(),
      query,
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

  return { getFilters, search, getFavorites, addFavorite, removeFavorite, getSubscriptions, subscribe, unsubscribe, markSeen };
})();
