const App = (() => {
  let currentTab = 'search';

  async function init() {
    TG.init();

    await Filters.init();

    document.getElementById('search-btn').addEventListener('click', runSearch);
    document.getElementById('sort-select')?.addEventListener('change', e => {
      /* re-render with new sort — results already in DOM; server-side re-sort on next search */
    });

    document.getElementById('sheet-overlay').addEventListener('click', e => {
      if (e.target === e.currentTarget) Results.closeSheet();
    });

    document.querySelectorAll('.bottom-nav-item').forEach(btn => {
      btn.addEventListener('click', () => switchTab(btn.dataset.tab));
    });

    switchTab('search');
  }

  function switchTab(tab) {
    currentTab = tab;
    document.querySelectorAll('.screen').forEach(s => s.classList.remove('active'));
    document.querySelectorAll('.bottom-nav-item').forEach(b => b.classList.remove('active'));

    document.getElementById(`screen-${tab}`)?.classList.add('active');
    document.querySelector(`.bottom-nav-item[data-tab="${tab}"]`)?.classList.add('active');

    if (tab === 'favorites') loadFavorites();
    if (tab === 'alerts')    Subscriptions.loadAndRender();
  }

  async function searchWithQuery(query) {
    TG.haptic('impact', 'medium');
    showScreen('results');
    showLoading(true);
    try {
      const data = await API.search(query);
      try {
        const favData = await API.getFavorites();
        Results.setFavorites((favData ?? []).map(f => f.id));
      } catch (_) {}
      Results.render(data);
      Subscriptions.setCurrentQuery(query);
    } catch (e) {
      showError(e.message);
    } finally {
      showLoading(false);
    }
  }

  async function runSearch() {
    const query = Filters.getQuery();
    TG.haptic('impact', 'medium');

    showScreen('results');
    showLoading(true);

    try {
      const data = await API.search(query);

      try {
        const favData = await API.getFavorites();
        Results.setFavorites((favData ?? []).map(f => f.id));
      } catch (_) {}

      Results.render(data);
      Subscriptions.setCurrentQuery(query);
    } catch (e) {
      showError(e.message);
    } finally {
      showLoading(false);
    }
  }

  async function loadFavorites() {
    const container = document.getElementById('favorites-list');
    container.innerHTML = '<div class="loading-overlay"><div class="spinner"></div><span>Загрузка…</span></div>';

    try {
      const favs = await API.getFavorites();
      if (!favs || !favs.length) {
        container.innerHTML = `
          <div class="empty-state">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
              <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>
            </svg>
            <p>Нет сохранённых лотов. Найдите лоты и нажмите ♡ чтобы сохранить.</p>
          </div>`;
        return;
      }
      Results.setFavorites(favs.map(f => f.id));
      Results.render({ lots: favs, total: favs.length, errors: [] });
      container.innerHTML = document.getElementById('cards-grid').innerHTML;
    } catch (e) {
      container.innerHTML = `<div class="empty-state"><p style="color:var(--danger)">${e.message}</p></div>`;
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
    if (el)   el.style.display   = visible ? 'flex' : 'none';
    if (grid) grid.style.display = visible ? 'none' : 'block';
  }

  function showError(msg) {
    const grid = document.getElementById('cards-grid');
    if (grid) grid.innerHTML = `<div class="empty-state" style="grid-column:span 2"><p style="color:var(--danger)">${msg}</p></div>`;
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

  return { switchTab, showToast, searchWithQuery };
})();
