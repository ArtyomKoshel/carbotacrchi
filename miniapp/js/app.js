/**
 * The shell. Decides the interface (Telegram / browser / desktop work place), routes between
 * views (#favorites, #compare, #alerts) and overlays (#filters, #lot-<id>), keeps the search
 * in the URL (?q=…, a link to send or bookmark) and wires Telegram's own buttons.
 */
const App = (() => {
  const WIDE = window.matchMedia('(min-width: 900px)');
  const VIEW_KEY = 'carbot_results_view';
  const LAST_KEY = 'lastSearch';
  const PAGE = 40;

  const s = {
    tg: false,
    wide: false,
    view: 'search',
    overlay: null,          // 'filters' | 'lot' | null
    pushed: false,          // the overlay added a history entry (Back closes it)
    query: null,
    lots: [],
    total: 0,
    loading: false,
    seq: 0,
    resultsView: 'table',
    favorites: new Set(),
    favoriteLots: [],
    lot: null,
    inspection: null,
    count: null,
  };
  const $ = id => document.getElementById(id);

  // ── shell ────────────────────────────────────────────────────────────────
  function applyShell() {
    const wasWide = s.wide;
    s.wide = !s.tg && WIDE.matches;
    document.body.classList.toggle('tg', s.tg);
    document.body.classList.toggle('web', !s.tg);
    document.body.classList.toggle('wide', s.wide);
    document.body.classList.toggle('tg-nosecondary', s.tg && !TG.hasSecondary());
    if (wasWide !== s.wide) {
      if (io) observeMore();
      if (s.wide && s.overlay === 'filters') closeOverlay();
      renderResults();
      if (s.overlay === 'lot' && s.lot) renderLot();
      if (s.view === 'compare') Compare.render($('compare-table'), { onOpen: lot => openLot(lot) });
    }
  }

  function toast(msg, ms = 2400) {
    const t = $('toast');
    t.textContent = msg;
    t.classList.add('show');
    clearTimeout(toast._t);
    toast._t = setTimeout(() => t.classList.remove('show'), ms);
  }

  // ── URL state ────────────────────────────────────────────────────────────
  const b64 = {
    enc: obj => btoa(unescape(encodeURIComponent(JSON.stringify(obj)))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, ''),
    dec: str => { try { return JSON.parse(decodeURIComponent(escape(atob(str.replace(/-/g, '+').replace(/_/g, '/'))))); } catch (_) { return null; } },
  };
  function cleanQuery(q) {
    const out = {};
    for (const [k, v] of Object.entries(q ?? {})) {
      if (k === 'limit' || k === 'offset' || v === undefined || v === null || v === '' || (Array.isArray(v) && !v.length)) continue;
      if ((k === 'sort' && v === 'date') || k === 'sources') continue; // every platform is the default
      out[k] = v;
    }
    return out;
  }
  function writeUrl() {
    const u = new URL(location.href);
    u.hash = location.hash;
    const q = cleanQuery(s.query);
    if (Object.keys(q).length) u.searchParams.set('q', b64.enc(q)); else u.searchParams.delete('q');
    u.searchParams.delete('search');
    history.replaceState(history.state, '', u);
  }
  function readDeepLink() {
    const u = new URL(location.href);
    const fromHash = (location.hash.match(/^#lot-(.+)$/) || [])[1];
    const start = TG.startParam();
    const lotFromStart = (start.match(/^lot_(.+)$/) || [])[1];
    let query = u.searchParams.get('q') ? b64.dec(u.searchParams.get('q')) : null;
    if (!query && u.searchParams.get('search')) { try { query = JSON.parse(u.searchParams.get('search')); } catch (_) {} }
    if (!query && start && !lotFromStart) query = b64.dec(start);
    return { query, lotId: fromHash || lotFromStart || null };
  }

  // ── routing ──────────────────────────────────────────────────────────────
  function route() {
    const h = location.hash.replace(/^#/, '');
    const lotId = (h.match(/^lot-(.+)$/) || [])[1];
    if (lotId) { if (!s.lot || s.lot.id !== lotId) openLot(lotId, { fromRoute: true }); else setOverlay('lot'); return; }
    if (h === 'filters' && !s.wide) { setOverlay('filters'); return; }
    setOverlay(null);
    const view = ['favorites', 'compare', 'alerts'].includes(h) ? h : 'search';
    showView(view);
  }

  function go(hash) {
    if (location.hash === hash || (!location.hash && hash === '#')) { route(); return; }
    location.hash = hash;
  }

  function showView(view) {
    if (view !== s.view) { if (s.wide) $('main').scrollTop = 0; else window.scrollTo(0, 0); }
    s.view = view;
    for (const v of ['search', 'favorites', 'compare', 'alerts']) $(`view-${v}`).hidden = v !== view;
    document.querySelectorAll('[data-nav]').forEach(a => a.classList.toggle('on', a.dataset.nav === view));
    if (view === 'favorites') loadFavorites();
    if (view === 'compare') Compare.render($('compare-table'), { onOpen: lot => openLot(lot) });
    if (view === 'alerts') Subscriptions.loadAndRender();
    syncTelegramButtons();
  }

  function setOverlay(name) {
    s.overlay = name;
    if (name) document.body.dataset.overlay = name; else delete document.body.dataset.overlay;
    $('lot-pane').hidden = name !== 'lot';
    if (name !== 'lot') { s.lot = null; s.inspection = null; markSelected(); }
    if (name === 'filters') Filters.refreshCount();
    syncTelegramButtons();
    if (!s.wide && name) window.scrollTo(0, 0);
  }

  function openOverlay(hash) {
    if (location.hash === hash) { route(); return; }
    // on the desktop, moving from lot to lot replaces the entry: Back leaves the pane in one step
    if (s.wide && s.overlay === 'lot' && hash.startsWith('#lot-')) { history.replaceState(history.state, '', hash); route(); return; }
    s.pushed = true;
    location.hash = hash;
  }

  function closeOverlay() {
    if (s.pushed) { s.pushed = false; history.back(); return; }
    history.replaceState(history.state, '', location.pathname + location.search + (s.view !== 'search' ? '#' + s.view : ''));
    route();
  }

  // ── Telegram's buttons follow the screen ─────────────────────────────────
  function countLabel() {
    if (s.count === undefined) return 'Показать…';
    if (s.count === null) return 'Показать';
    return s.count ? `Показать ${Fmt.lots(s.count)}` : 'Ничего не найдено';
  }

  function syncTelegramButtons() {
    const actionbar = $('actionbar');
    actionbar.hidden = true;
    if (s.overlay) TG.setBack(closeOverlay); else TG.setBack(null);
    if (s.overlay === 'filters') {
      TG.setMain(countLabel(), applyFilters, { active: s.count !== 0, progress: s.count === undefined });
      // red text: in Telegram's default colours the secondary button read as part of the bar
      TG.setSecondary('Сбросить', resetFilters, { active: Filters.hasActive(), textColor: TG.theme('destructive_text_color') || '#e5484d' });
      $('filters-apply-btn').textContent = countLabel();
      $('filters-apply-btn').disabled = s.count === 0;
    } else if (s.overlay === 'lot' && s.lot) {
      TG.setMain('Отправить клиенту', shareLot);
      // above the main one, full width: side by side each got half and the link read small
      TG.setSecondary(`Открыть на ${s.lot.sourceName || 'площадке'}`, openSource, { position: 'top' });
    } else {
      TG.setMain(null, null);
      TG.setSecondary(null, null);
    }
  }

  // ── search ───────────────────────────────────────────────────────────────
  async function search(query, { more = false } = {}) {
    const seq = ++s.seq;
    if (!more) {
      s.query = query;
      s.lots = [];
      s.total = 0;
      Results.skeleton($('results'), modeFor());
      $('results-banner').hidden = true;
      renderToolbar();
      writeUrl();
      try { localStorage.setItem(LAST_KEY, JSON.stringify(query)); } catch (_) {}
      Subscriptions.setCurrentQuery(query);
    }
    s.loading = true;
    $('results-more').hidden = !more;
    try {
      const data = await API.search({ ...query, limit: PAGE }, more ? s.lots.length : 0);
      if (seq !== s.seq) return;
      const page = data.lots ?? [];
      s.lots = more ? s.lots.concat(page) : page;
      s.total = data.total ?? s.lots.length;
      const banner = $('results-banner');
      if (!more && data.relaxed && data.relaxedMessage) { banner.textContent = data.relaxedMessage; banner.className = 'banner banner--info'; banner.hidden = false; }
      else if (!more && data.errors?.length) { banner.textContent = 'Часть площадок не ответила: ' + data.errors.join(', '); banner.className = 'banner'; banner.hidden = false; }
      renderResults();
      renderToolbar();
    } catch (e) {
      if (seq !== s.seq) return;
      if (API.isAuthError(e)) $('results').innerHTML = BrowserAuth.authRequiredHtml();
      else Results.empty($('results'), { title: 'Не удалось загрузить лоты', text: e.message, action: { label: 'Повторить', run: () => search(s.query) } });
    } finally {
      if (seq === s.seq) { s.loading = false; $('results-more').hidden = true; }
    }
  }
  const searchWithQuery = q => { go('#'); return search(q); };

  function modeFor() { return s.wide ? s.resultsView : 'rows'; }

  function renderResults() {
    const mount = $('results');
    if (!s.query) return;
    if (!s.lots.length && !s.loading) {
      Results.empty(mount, Filters.hasActive()
        ? { title: 'Ничего не найдено', text: 'Снимите один из фильтров выше или расширьте диапазон.', action: { label: 'Сбросить фильтры', run: resetFilters } }
        : { title: 'Лотов пока нет', text: 'Список появится после обхода площадки.' });
      return;
    }
    if (!s.lots.length) return;
    Results.render(mount, s.lots, {
      mode: modeFor(),
      favorites: s.favorites,
      selectedId: s.lot?.id,
      sort: s.query?.sort ?? 'date',
      query: s.query,
      onOpen: lot => openLot(lot),
      onFav: (lot, btn) => toggleFavorite(lot, btn),
      onCompare: lot => toggleCompare(lot),
      onSort: sort => setSort(sort),
    });
  }

  function renderToolbar() {
    $('results-count').innerHTML = s.loading && !s.lots.length ? '' : `<b>${Fmt.num(s.total)}</b> ${Fmt.plural(s.total, 'лот', 'лота', 'лотов')}`;
    const chips = Filters.activeChips();
    // "Сбросить" first: the row scrolls sideways on a phone, and a lone × on a chip was all there was
    $('applied-chips').innerHTML = (chips.length ? '<button class="chip chip--reset" type="button" data-reset-all>Сбросить</button>' : '')
      + chips.map(c => `<button class="chip chip--applied" type="button" data-clear="${c.key}" aria-label="Снять фильтр: ${Fmt.esc(c.label)}">${Fmt.esc(c.label)}</button>`).join('');
    $('applied-chips').querySelector('[data-reset-all]')?.addEventListener('click', () => {
      TG.haptic('selection');
      Filters.reset();
      search(Filters.getQuery());
    });
    $('applied-chips').querySelectorAll('[data-clear]').forEach(b => b.addEventListener('click', () => {
      TG.haptic('selection');
      Filters.clear(b.dataset.clear);
      search(Filters.getQuery());
    }));
    $('filters-count').textContent = chips.length || '';
    $('sort-select').value = s.query?.sort ?? 'date';
    document.querySelectorAll('.seg__btn').forEach(b => b.setAttribute('aria-pressed', String(b.dataset.view === s.resultsView)));
  }

  function setSort(sort) {
    Filters.setSort(sort);
    search(Filters.getQuery());
  }

  let liveTimer = 0;
  function onFiltersChanged() {
    if (!s.wide) return; // on the phone the filters apply with "Показать N лотов"
    clearTimeout(liveTimer);
    liveTimer = setTimeout(() => search(Filters.getQuery()), 450);
  }
  function applyFilters() {
    TG.haptic('impact', 'medium');
    search(Filters.getQuery());
    closeOverlay();
  }
  function resetFilters() {
    Filters.reset();
    if (s.wide || !s.overlay) search(Filters.getQuery());
  }

  // the next page loads near the end of the list; on the desktop the list scrolls inside <main>
  let io = null;
  function observeMore() {
    io?.disconnect();
    io = new IntersectionObserver(entries => {
      if (entries[0].isIntersecting && !s.loading && s.query && s.lots.length < s.total && s.view === 'search') search(s.query, { more: true });
    }, { root: s.wide ? $('main') : null, rootMargin: '600px' });
    io.observe($('scroll-sentinel'));
  }

  // ── AI search line: words, an Encar link or a lot id ──────────────────────
  async function ask(text) {
    const t = text.trim();
    if (!t) return;
    const link = t.match(/encar\.com\/.*?(\d{7,9})/) || t.match(/^(\d{7,9})$/);
    if (link) { openLot(link[1]); return; }
    const form = $('ask-form');
    form.classList.add('busy');
    try {
      const res = await API.searchChat(t);
      if (res?.query) {
        Filters.applyQuery({ ...res.query, sort: s.query?.sort });
        $('ask-input').value = '';
        $('ask-input').blur();
        go('#');
        await search(Filters.getQuery());
        // what the AI understood stays above the results until the next search
        const chips = Filters.activeChips().map(c => c.label).join(' · ');
        const banner = $('results-banner');
        banner.innerHTML = `<b>ИИ понял:</b> ${Fmt.esc(chips || res.message || 'без фильтров')}. Поправьте фильтры, если что-то не так.`;
        banner.className = 'banner banner--ai';
        banner.hidden = false;
      } else {
        toast(res?.message || 'Не понял запрос. Назовите марку, модель, год или бюджет.', 3600);
      }
    } catch (e) {
      toast(API.isAuthError(e) ? 'Нужен вход через Telegram' : 'ИИ-поиск не ответил: ' + e.message, 3600);
    } finally {
      form.classList.remove('busy');
    }
  }

  // ── lot ──────────────────────────────────────────────────────────────────
  async function openLot(lotOrId, { fromRoute = false } = {}) {
    const id = typeof lotOrId === 'object' ? lotOrId.id : String(lotOrId);
    const known = typeof lotOrId === 'object' ? lotOrId : (s.lots.find(l => l.id === id) || s.favoriteLots.find(l => l.id === id) || null);
    TG.haptic('impact', 'light');
    s.lot = known ?? { id };
    s.inspection = null;
    if (!fromRoute) openOverlay(`#lot-${encodeURIComponent(id)}`);
    else setOverlay('lot');
    markSelected();
    const mount = $('lot-view');
    if (known) renderLot(); else LotView.state(mount, '<span class="spinner"></span>', closable(), closeOverlay);
    try {
      const data = await API.getLot(id);
      if (s.lot?.id !== id) return;
      s.lot = data.lot;
      s.inspection = data.inspection;
      renderLot();
    } catch (e) {
      if (s.lot?.id !== id) return;
      if (!known) LotView.state(mount, API.isAuthError(e) ? BrowserAuth.authRequiredHtml() : Fmt.esc(e.message || 'Лот не найден'), closable(), closeOverlay);
    }
  }

  function closable() { return s.tg ? null : (s.wide ? 'close' : 'back'); }

  function renderLot() {
    if (!s.lot) return;
    const mount = $('lot-view');
    const keep = mount.querySelector('.gallery__track')?.scrollLeft ?? 0;
    LotView.render(mount, s.lot, {
      inspection: s.inspection,
      query: s.query,
      favorite: s.favorites.has(s.lot.id),
      compared: Compare.has(s.lot.id),
      closable: closable(),
      onClose: closeOverlay,
      onShare: shareLot,
      onSource: openSource,
      onFav: () => toggleFavorite(s.lot),
      onCompare: () => toggleCompare(s.lot),
    });
    const track = mount.querySelector('.gallery__track');
    if (track && keep) track.scrollLeft = keep;
    if (s.wide) $('lot-pane').scrollTop = 0;
    syncTelegramButtons();
  }

  function markSelected() {
    if (!s.wide) return;
    document.querySelectorAll('#results [data-i]').forEach(el => {
      const lot = s.lots[+el.dataset.i];
      el.classList.toggle('sel', !!lot && lot.id === s.lot?.id);
    });
  }

  function openSource() {
    if (s.lot?.lotUrl) TG.openLink(s.lot.lotUrl);
  }

  async function shareLot() {
    const lot = s.lot;
    if (!lot) return;
    TG.haptic('impact', 'medium');
    try {
      if (s.tg) {
        const res = await API.shareLot(lot.id, TG.canShare() ? 'prepare' : 'chat');
        if (res.prepared_message_id && await TG.share(res.prepared_message_id)) { toast('Отправлено'); return; }
        if (res.prepared_message_id) return; // the picker closed Telegram's chat list
        toast(res.sent_to_chat ? 'Отправил карточку вам в чат — перешлите клиенту' : 'Не удалось отправить', 3200);
        return;
      }
      const res = await API.shareLot(lot.id, BrowserAuth.isLinked() ? 'chat' : 'text');
      let copied = false;
      try { await navigator.clipboard.writeText(res.text); copied = true; } catch (_) {}
      toast(res.sent_to_chat
        ? `Карточка с фото — в вашем Telegram${copied ? ', текст скопирован' : ''}`
        : (copied ? 'Описание для клиента скопировано' : res.text), 3600);
    } catch (e) {
      toast(API.isAuthError(e) ? 'Нужен вход через Telegram' : 'Не удалось подготовить карточку: ' + e.message, 3600);
    }
  }

  // ── favorites, compare ───────────────────────────────────────────────────
  async function loadFavoriteIds() {
    try {
      const favs = await API.getFavorites();
      s.favoriteLots = favs ?? [];
      s.favorites = new Set(s.favoriteLots.map(f => f.id));
    } catch (_) { s.favorites = new Set(); }
    $('fav-count').textContent = s.favorites.size || '';
  }

  async function loadFavorites() {
    const mount = $('favorites-list');
    Results.skeleton(mount, 'rows', 3);
    try {
      await loadFavoriteIds();
      if (!s.favoriteLots.length) {
        Results.empty(mount, { title: 'В избранном пусто', text: 'Отмечайте машины сердечком в списке или в лоте.' });
        return;
      }
      Results.render(mount, s.favoriteLots, {
        mode: s.wide ? 'cards' : 'rows', favorites: s.favorites, selectedId: s.lot?.id,
        onOpen: lot => openLot(lot), onFav: (lot, btn) => toggleFavorite(lot, btn),
      });
    } catch (e) {
      mount.innerHTML = API.isAuthError(e) ? BrowserAuth.authRequiredHtml() : `<div class="empty"><b>Не загрузилось</b><span>${Fmt.esc(e.message)}</span></div>`;
    }
  }

  async function toggleFavorite(lot, btn) {
    TG.haptic('impact', 'light');
    const on = !s.favorites.has(lot.id);
    try {
      if (on) await API.addFavorite(lot.id, lot.source, lot); else await API.removeFavorite(lot.id);
      if (on) { s.favorites.add(lot.id); s.favoriteLots = [lot, ...s.favoriteLots.filter(l => l.id !== lot.id)]; }
      else { s.favorites.delete(lot.id); s.favoriteLots = s.favoriteLots.filter(l => l.id !== lot.id); }
      $('fav-count').textContent = s.favorites.size || '';
      if (btn) { btn.classList.toggle('on', on); btn.setAttribute('aria-pressed', String(on)); }
      if (s.lot?.id === lot.id) LotView.setFavorite($('lot-view'), on);
      if (s.wide && s.view === 'search') renderResults();
      if (s.view === 'favorites' && !on) loadFavorites();
      toast(on ? 'В избранном' : 'Убрано из избранного');
    } catch (e) {
      toast(API.isAuthError(e) ? 'Избранное доступно после входа через Telegram' : 'Ошибка: ' + e.message, 3200);
    }
  }

  function toggleCompare(lot) {
    const r = Compare.toggle(lot);
    TG.haptic('selection');
    if (r === 'full') { toast(`В сравнении уже ${Compare.MAX} машины — уберите одну`, 3000); return; }
    toast(r === 'added' ? `В сравнении: ${Compare.count()} из ${Compare.MAX}` : 'Убрано из сравнения');
    if (s.lot?.id === lot.id) LotView.setCompared($('lot-view'), Compare.has(lot.id));
    if (s.wide && s.view === 'search') renderResults();
    if (s.view === 'compare') Compare.render($('compare-table'), { onOpen: l => openLot(l) });
  }
  function renderCompareCount() {
    const n = Compare.count() || '';
    $('cmp-count').textContent = n;
    $('cmp-count-tab').textContent = n;
  }

  // ── desktop: the filters column drags wider ──────────────────────────────
  const SIDE_KEY = 'carbot_side_width';
  const SIDE_MIN = 240, SIDE_MAX = 560;
  function setSide(px) {
    const w = Math.round(Math.max(SIDE_MIN, Math.min(SIDE_MAX, Math.min(px, window.innerWidth * 0.45))));
    document.documentElement.style.setProperty('--side', w + 'px');
    return w;
  }
  function initSideResize() {
    const handle = $('side-resize');
    try { const saved = +localStorage.getItem(SIDE_KEY); if (saved) setSide(saved); } catch (_) {}
    const save = w => { try { localStorage.setItem(SIDE_KEY, String(w)); } catch (_) {} };
    handle.addEventListener('pointerdown', e => {
      e.preventDefault();
      handle.setPointerCapture(e.pointerId);
      document.body.classList.add('resizing');
      const left = $('filters-pane').getBoundingClientRect().left;
      let w = 0;
      const move = ev => { w = setSide(ev.clientX - left); };
      const up = () => {
        handle.removeEventListener('pointermove', move);
        document.body.classList.remove('resizing');
        if (w) save(w);
      };
      handle.addEventListener('pointermove', move);
      handle.addEventListener('pointerup', up, { once: true });
      handle.addEventListener('pointercancel', up, { once: true });
    });
    handle.addEventListener('keydown', e => {
      if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') return;
      e.preventDefault();
      save(setSide($('filters-pane').offsetWidth + (e.key === 'ArrowRight' ? 24 : -24)));
    });
    handle.addEventListener('dblclick', () => {
      document.documentElement.style.removeProperty('--side');
      try { localStorage.removeItem(SIDE_KEY); } catch (_) {}
    });
  }

  // ── desktop keyboard ─────────────────────────────────────────────────────
  function onKey(e) {
    if (!s.wide) return;
    const typing = /^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement?.tagName) || document.activeElement?.isContentEditable;
    if (e.key === '/' && !typing) { e.preventDefault(); $('ask-input').focus(); return; }
    if (e.key === 'Escape' && s.overlay === 'lot') { closeOverlay(); return; }
    if (typing || s.view !== 'search' || !s.lots.length) return;
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault();
      const i = s.lots.findIndex(l => l.id === s.lot?.id);
      const next = Math.max(0, Math.min(s.lots.length - 1, i < 0 ? 0 : i + (e.key === 'ArrowDown' ? 1 : -1)));
      openLot(s.lots[next]);
      document.querySelector(`#results [data-i="${next}"]`)?.scrollIntoView({ block: 'nearest' });
    }
  }

  // ── start ────────────────────────────────────────────────────────────────
  async function init() {
    s.tg = TG.init();
    try { s.resultsView = localStorage.getItem(VIEW_KEY) === 'cards' ? 'cards' : 'table'; } catch (_) {}
    applyShell();
    WIDE.addEventListener('change', applyShell);
    initSideResize();

    BrowserAuth.init(() => {
      loadFavoriteIds();
      Subscriptions.loadSubs();
      if (s.query) search(s.query);
    });

    await Filters.init();
    Filters.onChange(onFiltersChanged);
    Filters.onCount(n => { s.count = n; if (s.overlay === 'filters') syncTelegramButtons(); });

    $('ask-form').addEventListener('submit', e => { e.preventDefault(); hints(false); ask($('ask-input').value); });
    const hints = on => { $('ask-hints').hidden = !on; };
    $('ask-input').addEventListener('focus', () => hints(!$('ask-input').value.trim()));
    $('ask-input').addEventListener('input', () => hints(!$('ask-input').value.trim()));
    $('ask-input').addEventListener('blur', () => setTimeout(() => hints(false), 150));
    document.querySelectorAll('.ask__hint').forEach(b => b.addEventListener('mousedown', e => {
      e.preventDefault(); // keep the field focused until the request starts
      $('ask-input').value = b.textContent;
      hints(false);
      ask(b.textContent);
    }));
    $('open-filters').addEventListener('click', () => openOverlay('#filters'));
    $('filters-apply-btn').addEventListener('click', applyFilters);
    $('filters-reset-btn').addEventListener('click', resetFilters);
    $('sort-select').addEventListener('change', e => setSort(e.target.value));
    $('subscribe-btn').addEventListener('click', () => Subscriptions.toggleSubscribe());
    document.querySelectorAll('.seg__btn').forEach(b => b.addEventListener('click', () => {
      s.resultsView = b.dataset.view;
      try { localStorage.setItem(VIEW_KEY, s.resultsView); } catch (_) {}
      renderToolbar();
      renderResults();
    }));
    document.querySelectorAll('[data-nav]').forEach(a => a.addEventListener('click', e => {
      e.preventDefault();
      TG.haptic('selection');
      go(a.dataset.nav === 'search' ? '#' : '#' + a.dataset.nav);
    }));
    // Back from an overlay may change the query string as well as the hash: then the browser sends
    // only popstate. Both lead here; the same URL twice is routed once.
    let routed = '';
    const onNav = () => {
      if (location.href === routed) return;
      if (!/^#(lot-|filters)/.test(location.hash)) s.pushed = false;
      route();
      if (s.query) writeUrl();
      routed = location.href;
    };
    window.addEventListener('hashchange', onNav);
    window.addEventListener('popstate', onNav);
    document.addEventListener('keydown', onKey);
    Compare.onChange(renderCompareCount);
    renderCompareCount();
    observeMore();

    loadFavoriteIds();
    Subscriptions.loadSubs();

    const deep = readDeepLink();
    let query = deep.query;
    if (!query) { try { query = JSON.parse(localStorage.getItem(LAST_KEY) || 'null'); } catch (_) {} }
    if (query) Filters.applyQuery(query);
    search(Filters.getQuery()); // with no filters: the newest lots — never an empty screen
    route();

    const boot = $('boot');
    boot.classList.add('done');
    setTimeout(() => boot.remove(), 250);
  }

  document.addEventListener('DOMContentLoaded', init);

  return { toast, searchWithQuery, openLot, showToast: toast };
})();
