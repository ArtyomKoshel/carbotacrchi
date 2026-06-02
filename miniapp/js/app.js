const App = (() => {
  let currentTab = 'search';
  let paginationState = { query: null, offset: 0, total: 0, loading: false, limit: 40 };
  let scrollObserver = null;

  function hideLoadingOverlay() {
    const el = document.getElementById('app-loading-overlay');
    if (!el) return;
    el.classList.add('hidden');
    setTimeout(() => el.remove(), 300);
  }

  async function init() {
    TG.init();

    // If filters are already cached → hide overlay immediately (no visible flash)
    if (API.isFiltersCached()) {
      hideLoadingOverlay();
    }

    await Filters.init();

    // Hide overlay after init (covers the case when cache was empty)
    hideLoadingOverlay();

    Chat.init();

    document.getElementById('search-btn').addEventListener('click', runSearch);
    document.getElementById('load-more-btn')?.addEventListener('click', loadMore);
    document.getElementById('sort-select')?.addEventListener('change', async e => {
      Filters.setSort(e.target.value);
      if (currentTab === 'results') {
        await runSearch();
      }
    });

    document.getElementById('sheet-overlay').addEventListener('click', e => {
      if (e.target === e.currentTarget) Results.closeSheet();
    });

    document.querySelectorAll('.bottom-nav-item').forEach(btn => {
      btn.addEventListener('click', () => switchTab(btn.dataset.tab));
    });

    Subscriptions.loadSubs();

    const deepQuery = parseDeepLink();
    if (deepQuery) {
      Filters.applyQuery(deepQuery);
      await searchWithQuery(Filters.getQuery());
    } else {
      const lastQuery = loadLastSearch();
      if (lastQuery) {
        Filters.applyQuery(lastQuery);
        await searchWithQuery(Filters.getQuery());
      } else {
        switchTab('search');
      }
    }
  }

  function saveLastSearch(query) {
    try { localStorage.setItem('lastSearch', JSON.stringify(query)); } catch (_) {}
  }

  function loadLastSearch() {
    try {
      const raw = localStorage.getItem('lastSearch');
      if (!raw) return null;
      const q = JSON.parse(raw);
      if (typeof q === 'object' && q !== null) return q;
    } catch (_) {}
    return null;
  }

  function parseDeepLink() {
    try {
      const parseJsonQuery = (raw) => {
        if (!raw) return null;
        try {
          const q = JSON.parse(raw);
          return (typeof q === 'object' && q !== null) ? q : null;
        } catch (_) {
          return null;
        }
      };

      const parseBase64Query = (raw) => {
        if (!raw) return null;
        try {
          const normalized = String(raw).replace(/-/g, '+').replace(/_/g, '/');
          const pad = normalized.length % 4 === 0 ? '' : '='.repeat(4 - (normalized.length % 4));
          const decoded = atob(normalized + pad);
          return parseJsonQuery(decoded);
        } catch (_) {
          return null;
        }
      };

      const fromUrlParams = (params) => {
        if (!params) return null;
        const fromSearch = parseJsonQuery(params.get('search'));
        if (fromSearch) return fromSearch;
        return parseBase64Query(params.get('q'));
      };

      const urlParams = new URLSearchParams(window.location.search);
      const fromQuery = fromUrlParams(urlParams);
      if (fromQuery) return fromQuery;

      const hashRaw = String(window.location.hash || '').replace(/^#/, '');
      if (hashRaw) {
        const hashParams = new URLSearchParams(hashRaw);
        const fromHash = fromUrlParams(hashParams);
        if (fromHash) return fromHash;
      }

      const startParam = window.Telegram?.WebApp?.initDataUnsafe?.start_param;
      const fromStartParam = parseBase64Query(startParam) ?? parseJsonQuery(startParam);
      if (fromStartParam) return fromStartParam;
    } catch (_) {}
    return null;
  }

  function switchTab(tab) {
    currentTab = tab;
    document.querySelectorAll('.screen').forEach(s => s.classList.remove('active'));
    document.querySelectorAll('.bottom-nav-item').forEach(b => b.classList.remove('active'));

    document.getElementById(`screen-${tab}`)?.classList.add('active');
    document.querySelector(`.bottom-nav-item[data-tab="${tab}"]`)?.classList.add('active');

    if (tab === 'favorites') loadFavorites();
    if (tab === 'alerts')    Subscriptions.loadAndRender();
    if (tab === 'results')   refreshFavState();
  }

  async function refreshFavState() {
    try {
      const favData = await API.getFavorites();
      Results.setFavorites((favData ?? []).map(f => f.id));
    } catch (_) {}
  }

  async function searchWithQuery(query) {
    TG.haptic('impact', 'medium');
    showScreen('results');
    showLoading(true);
    hideLoadMore();

    paginationState = { query, offset: 0, total: 0, loading: true, limit: 40 };

    try {
      const data = await API.search(query, 0);
      try {
        const favData = await API.getFavorites();
        Results.setFavorites((favData ?? []).map(f => f.id));
      } catch (_) {}
      Results.setSearchOptions(query.options ?? []);
      Results.render(data);
      paginationState.total = data.total ?? 0;
      paginationState.offset = (data.lots ?? []).length;
      Subscriptions.setCurrentQuery(query);
      saveLastSearch(query);
      updateLoadMoreUI();
      setupScrollObserver();
    } catch (e) {
      showError(e.message);
    } finally {
      paginationState.loading = false;
      showLoading(false);
    }
  }

  async function runSearch() {
    const query = Filters.getQuery();
    await searchWithQuery(query);
  }

  async function loadMore() {
    if (paginationState.loading) return;
    if (paginationState.offset >= paginationState.total) return;

    paginationState.loading = true;
    showLoadMoreLoading(true);

    try {
      const data = await API.search(paginationState.query, paginationState.offset);
      const newLots = data.lots ?? [];
      if (newLots.length) {
        Results.appendCards(newLots);
        paginationState.offset += newLots.length;
      }
      updateLoadMoreUI();
    } catch (e) {
      showToast('Ошибка загрузки: ' + (e.message || 'network'));
    } finally {
      paginationState.loading = false;
      showLoadMoreLoading(false);
    }
  }

  function setupScrollObserver() {
    if (scrollObserver) scrollObserver.disconnect();

    const sentinel = document.getElementById('scroll-sentinel');
    if (!sentinel) return;

    scrollObserver = new IntersectionObserver((entries) => {
      if (entries[0]?.isIntersecting && !paginationState.loading && paginationState.offset < paginationState.total) {
        loadMore();
      }
    }, { rootMargin: '200px' });

    scrollObserver.observe(sentinel);
  }

  function updateLoadMoreUI() {
    const wrap = document.getElementById('load-more-wrap');
    if (!wrap) return;
    const hasMore = paginationState.offset < paginationState.total;
    wrap.style.display = hasMore ? 'block' : 'none';
  }

  function hideLoadMore() {
    const wrap = document.getElementById('load-more-wrap');
    if (wrap) wrap.style.display = 'none';
  }

  function showLoadMoreLoading(visible) {
    const btn = document.getElementById('load-more-btn');
    if (!btn) return;
    btn.disabled = visible;
    btn.textContent = visible ? 'Загрузка…' : 'Загрузить ещё';
  }

  async function loadFavorites() {
    const container = document.getElementById('favorites-list');
    if (!container) return;

    const favGrid = document.getElementById('favorites-grid');
    const favEmpty = document.getElementById('favorites-empty');

    if (favGrid) {
      favGrid.innerHTML = '<div class="loading-overlay"><div class="spinner"></div><span>Загрузка…</span></div>';
    }
    if (favEmpty) {
      favEmpty.style.display = 'none';
      const msg = favEmpty.querySelector('p');
      if (msg) {
        msg.textContent = 'Нет сохранённых лотов.';
        msg.style.color = '';
      }
    }

    try {
      const favs = await API.getFavorites();

      if (!favs || !favs.length) {
        if (favEmpty) favEmpty.style.display = 'flex';
        if (favGrid) favGrid.innerHTML = '';
        return;
      }
      Results.setFavorites(favs.map(f => f.id));
      Results.render(
        { lots: favs, total: favs.length, errors: [] },
        { grid: favGrid, empty: favEmpty }
      );
    } catch (e) {
      if (favGrid) favGrid.innerHTML = '';
      if (favEmpty) {
        favEmpty.style.display = 'flex';
        const msg = favEmpty.querySelector('p');
        if (msg) {
          msg.textContent = e?.message ? `Ошибка: ${e.message}` : 'Ошибка загрузки избранного.';
          msg.style.color = 'var(--danger)';
        }
      } else {
        container.innerHTML = `<div class="empty-state"><p style="color:var(--danger)">${e.message}</p></div>`;
      }
    }
  }

  function showScreen(name) {
    document.querySelectorAll('.screen').forEach(s => s.classList.remove('active'));
    document.getElementById(`screen-${name}`)?.classList.add('active');
    document.querySelectorAll('.bottom-nav-item').forEach(b => b.classList.remove('active'));
    document.querySelector(`.bottom-nav-item[data-tab="${name}"]`)?.classList.add('active');
    currentTab = name;
  }

  function showLoading(visible) {
    const el = document.getElementById('results-loading');
    const grid = document.getElementById('results-grid-wrap');
    if (el) el.style.display = 'none'; // spinner hidden; skeletons used instead
    if (grid) grid.style.display = 'block';
    if (visible) Results.showSkeletons(6);
  }

  function setSearchMode(mode) {
    const screen      = document.getElementById('screen-search');
    const filterPanel = document.getElementById('mode-panel-filters');
    const chatPanel   = document.getElementById('mode-panel-chat');
    const tabFilters  = document.getElementById('mode-tab-filters');
    const tabChat     = document.getElementById('mode-tab-chat');

    if (mode === 'chat') {
      screen?.classList.add('mode-chat');
      if (filterPanel) filterPanel.style.display = 'none';
      if (chatPanel)   chatPanel.style.display   = 'flex';
      tabFilters?.classList.remove('active');
      tabChat?.classList.add('active');
      setTimeout(() => document.getElementById('chat-input')?.focus(), 50);
    } else {
      screen?.classList.remove('mode-chat');
      if (filterPanel) filterPanel.style.display = '';
      if (chatPanel)   chatPanel.style.display   = 'none';
      tabFilters?.classList.add('active');
      tabChat?.classList.remove('active');
    }
  }

  function showError(msg) {
    const grid = document.getElementById('cards-grid');
    if (grid) {
      const escaped = String(msg ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
      grid.innerHTML = `<div class="empty-state" style="grid-column:span 2"><p style="color:var(--danger)">${escaped}</p></div>`;
    }
    showLoading(false);
  }

  function showToast(msg) {
    const t = document.getElementById('toast');
    if (!t) return;
    t.textContent = msg;
    t.classList.add('show');
    setTimeout(() => t.classList.remove('show'), 2200);
  }

  document.addEventListener('DOMContentLoaded', init);

  return { switchTab, showToast, searchWithQuery, setSearchMode };
})();
