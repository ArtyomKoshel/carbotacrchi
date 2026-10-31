/**
 * Model → generation → modification → complectation, from the reference catalog
 * (GET /api/catalog/{makeId}). Filters keeps make; this module
 * owns the four selects below it and the ref* fields of the query.
 *
 * The cascade runs on the client from the make's `cells` [model, generation, modification,
 * complectation, lots, platform]: each list shows what the picked platforms and the choices
 * above leave, with how many lots.
 * A modification code ("520i") is one choice across the selected generations.
 */
const CatalogFilter = (() => {
  let makes = null;          // lower-case name → {id, name, lots}
  let tree = null;           // the current make's /api/catalog/{id}
  let seq = 0;
  let mountEl = null;
  let changed = () => {};
  let sources = null;        // the platforms picked; null = every one
  const ts = {};
  const sel = { model: 0, generations: [], modifications: [], complectations: [] }; // modifications: codes

  async function load() {
    try {
      const data = await API.getCatalogMakes();
      makes = new Map((data?.makes ?? []).map(m => [m.name.toLowerCase(), m]));
    } catch (_) {
      makes = new Map(); // no catalog: the make alone filters
    }
  }

  const makeOf = name => (name && makes?.get(String(name).toLowerCase())) || null;
  const covers = name => !!makeOf(name);
  const active = () => !!tree;

  function reset() {
    seq++;
    tree = null;
    sel.model = 0; sel.generations = []; sel.modifications = []; sel.complectations = [];
    destroy();
    if (mountEl) { mountEl.innerHTML = ''; mountEl.hidden = true; }
  }

  function destroy() {
    for (const k of Object.keys(ts)) { try { ts[k].destroy(); } catch (_) {} delete ts[k]; }
  }

  /**
   * Shows the cascade of `makeName` in `el`; `restore` (a query's ref* fields) picks what
   * was chosen. Resolves false when the catalog does not cover the make.
   */
  async function mount(el, makeName, onChange, restore = null) {
    reset();
    mountEl = el;
    changed = onChange || (() => {});
    const make = makeOf(makeName);
    if (!make || !el) return false;
    const my = ++seq;
    el.hidden = false;
    el.innerHTML = '<div class="filter-hint">Загружаю справочник…</div>';
    let data;
    try {
      data = await API.getCatalog(make.id);
    } catch (_) {
      if (my === seq) { el.innerHTML = ''; el.hidden = true; }
      return false;
    }
    if (my !== seq) return false;
    tree = data;
    if (restore) {
      // a query saved before the catalog names its model as text ("Sonata")
      const named = String(restore.model ?? '').toLowerCase();
      sel.model = Number(restore.refModelId) || (named && tree.models.find(m => m.name.toLowerCase() === named)?.id) || 0;
      sel.generations = (restore.refGenerationIds ?? []).map(Number);
      sel.complectations = (restore.refComplectationIds ?? []).map(Number);
      const ids = new Set((restore.refModificationIds ?? []).map(Number));
      sel.modifications = [...new Set(tree.modifications.filter(m => ids.has(m.id)).map(m => m.code))];
      if (ids.has(NONE)) sel.modifications.push(NONE_MOD);
    }
    skeleton();
    return true;
  }

  // ── the cascade ──────────────────────────────────────────────────────────
  //
  // Lots the catalog could not place all the way stay choosable: «Прочие модели» (model −1),
  // «… не указано» (generation / complectation 0, modification NONE_MOD). A choice never hides
  // a lot without saying so.

  const OTHER_MODEL = -1;
  const NONE = 0;
  const NONE_MOD = '·none';
  const byId = list => new Map((list ?? []).map(x => [x.id, x]));

  /** A cell's key at each level, with the "not stated" sentinels. */
  const keyOf = {
    model: c => c[0] ?? OTHER_MODEL,
    gen: c => c[1] ?? NONE,
    mod: (c, mods) => (c[2] ? mods.get(c[2])?.code ?? NONE_MOD : NONE_MOD),
    comp: c => c[3] ?? NONE,
  };

  /** Cells the choices above `level` leave: 0 model, 1 generation, 2 modification, 3 complectation. */
  function cells(level) {
    const mods = byId(tree.modifications);
    return tree.cells.filter(c =>
      (!sources || sources.includes(c[5]))
      && (level < 1 || !sel.model || keyOf.model(c) === sel.model)
      && (level < 2 || !sel.generations.length || sel.generations.includes(keyOf.gen(c)))
      && (level < 3 || !sel.modifications.length || sel.modifications.includes(keyOf.mod(c, mods))));
  }

  function counts(level, key) {
    const mods = byId(tree.modifications);
    const out = new Map();
    for (const c of cells(level)) {
      const k = key(c, mods);
      out.set(k, (out.get(k) ?? 0) + c[4]);
    }
    return out;
  }

  const years = g => g.year_from ? `${g.year_from}–${g.year_to ?? 'н.в.'}` : '';
  const n = v => Number(v).toLocaleString('ru-RU');

  const LEVELS = ['model', 'gen', 'mod', 'comp'];

  function skeleton() {
    destroy();
    mountEl.innerHTML = LEVELS.map(l => `<div class="filter-select-wrap" data-level="${l}"><select class="filter-select" id="cat-${l}"></select></div>`).join('');
    render(0);
  }

  // "not stated" last, the rest by its own order
  const noneLast = (isNone, order) => (a, b) => (isNone(a) - isNone(b)) || order(a, b);

  /**
   * Rebuilds the selects from `from` down (0 model … 3 complectation); the ones above keep
   * their state, so a multi-select stays open while several are picked.
   */
  function render(from) {
    const gens = byId(tree.generations), models = byId(tree.models), comps = byId(tree.complectations);
    const modelCounts = counts(0, keyOf.model);
    const genCounts = counts(1, keyOf.gen);
    const modCounts = counts(2, keyOf.mod);
    const compCounts = counts(3, keyOf.comp);

    // a choice the cascade no longer offers is dropped
    if (from <= 0 && sel.model && !modelCounts.has(sel.model)) sel.model = 0;
    if (from <= 1) sel.generations = sel.generations.filter(id => genCounts.has(id));
    if (from <= 2) sel.modifications = sel.modifications.filter(code => modCounts.has(code));
    if (from <= 3) sel.complectations = sel.complectations.filter(id => compCounts.has(id));

    const build = {
      model: () => select('model', [{ value: '', text: 'Любая модель' },
        ...[...modelCounts].map(([id, k]) => ({ id, value: String(id), text: id === OTHER_MODEL ? 'Прочие модели' : (models.get(id)?.name ?? String(id)), n: k }))
          .sort(noneLast(o => o.id === OTHER_MODEL, (a, b) => a.text.localeCompare(b.text, 'en', { numeric: true })))],
        sel.model ? [String(sel.model)] : [''], 1, v => {
          sel.model = Number(v) || 0; sel.generations = []; sel.modifications = []; sel.complectations = [];
          render(1); changed();
        }),
      gen: () => select('gen', [...genCounts].map(([id, k]) => ({ id, g: gens.get(id) ?? {}, n: k }))
          .sort(noneLast(o => o.id === NONE, (a, b) => (a.g.year_from ?? 0) - (b.g.year_from ?? 0)))
          .map(({ id, g, n: k }) => ({ value: String(id), text: id === NONE ? 'Поколение не указано' : [g.code, years(g)].filter(Boolean).join(' · '), n: k })),
        sel.generations.map(String), null, v => { sel.generations = v.map(Number); render(2); changed(); }, 'Любое поколение'),
      mod: () => select('mod', [...modCounts].map(([code, k]) => ({ value: code, text: code === NONE_MOD ? 'Модификация не указана' : code, n: k }))
          .sort(noneLast(o => o.value === NONE_MOD, (a, b) => a.text.localeCompare(b.text, 'en', { numeric: true }))),
        sel.modifications, null, v => { sel.modifications = v; render(3); changed(); }, 'Любая модификация'),
      comp: () => select('comp', [...compCounts].map(([id, k]) => ({ id, value: String(id), text: id === NONE ? 'Комплектация не указана' : (comps.get(id)?.name ?? String(id)), n: k }))
          .sort(noneLast(o => o.id === NONE, (a, b) => b.n - a.n)),
        sel.complectations.map(String), null, v => { sel.complectations = v.map(Number); changed(); }, 'Любая комплектация'),
    };
    const placed = sel.model && sel.model !== OTHER_MODEL;
    // complectations only when there is a real one to pick, not just "not stated"
    const shown = { model: true, gen: placed, mod: placed, comp: [...compCounts.keys()].some(k => k !== NONE) };

    LEVELS.forEach((l, i) => {
      if (i < from) return;
      if (ts[l]) { try { ts[l].destroy(); } catch (_) {} delete ts[l]; }
      const wrap = mountEl.querySelector(`[data-level="${l}"]`);
      wrap.innerHTML = `<select class="filter-select" id="cat-${l}"${l === 'model' ? '' : ' multiple'}></select>`;
      wrap.hidden = !shown[l];
      if (shown[l]) ts[l] = build[l]();
    });
  }

  /** A Tom Select; options carry their lot count. maxItems 1 = single, null = many. */
  function select(level, options, items, maxItems, onChange, placeholder = '') {
    const el = document.getElementById('cat-' + level);
    if (!el || typeof TomSelect === 'undefined') return null;
    const t = new TomSelect(el, {
      valueField: 'value', labelField: 'text', searchField: ['text'],
      options, items, maxItems, maxOptions: null, allowEmptyOption: maxItems === 1, placeholder,
      plugins: maxItems === 1 ? [] : ['remove_button'],
      render: {
        option: o => `<div class="cat-opt"><span>${esc(o.text)}</span>${o.n ? `<span class="cat-opt__n">${n(o.n)}</span>` : ''}</div>`,
        item: o => `<div>${esc(o.text)}${o.n ? `<span class="cat-opt__n cat-item__n">${n(o.n)}</span>` : ''}</div>`,
      },
      onChange(v) { onChange(maxItems === 1 ? (v || '') : (Array.isArray(v) ? v : String(v || '').split(',').filter(Boolean))); },
    });
    t.wrapper.classList.add(maxItems === 1 ? 'ts-single' : 'ts-multi');
    return t;
  }

  const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  // ── the query ────────────────────────────────────────────────────────────

  /** The ref* fields of the search query (empty when the make is not in the catalog). */
  function query() {
    if (!tree) return {};
    const mods = tree.modifications.filter(m => sel.modifications.includes(m.code)
      && (!sel.generations.length || sel.generations.includes(m.generation_id))
      && tree.generations.some(g => g.id === m.generation_id && g.model_id === sel.model));
    const modIds = [...mods.map(m => m.id), ...(sel.modifications.includes(NONE_MOD) ? [NONE] : [])];
    return {
      refMakeId: tree.make.id,
      refModelId: sel.model || undefined,
      refGenerationIds: sel.generations.length ? sel.generations : undefined,
      refModificationIds: modIds.length ? modIds : undefined,
      refComplectationIds: sel.complectations.length ? sel.complectations : undefined,
    };
  }

  /** Words for the applied-filter chip: "5 Series G60 · 530i xDrive · M Sport". */
  function summary() {
    if (!tree) return '';
    const model = sel.model === OTHER_MODEL ? 'прочие модели' : tree.models.find(m => m.id === sel.model)?.name;
    const gens = sel.generations.map(id => (id === NONE ? 'поколение не указано' : tree.generations.find(g => g.id === id)?.code)).filter(Boolean);
    const mods = sel.modifications.map(c => (c === NONE_MOD ? 'модификация не указана' : c));
    const comps = sel.complectations.map(id => (id === NONE ? 'комплектация не указана' : tree.complectations.find(c => c.id === id)?.name)).filter(Boolean);
    return [[model, gens.join(', ')].filter(Boolean).join(' '), mods.join(', '), comps.join(', ')].filter(Boolean).join(' · ');
  }

  const hasChoice = () => !!(sel.model || sel.complectations.length);

  /**
   * The platforms picked (empty = every one): the lists count only their lots, and a choice
   * none of them has is dropped.
   */
  function setSources(list) {
    const next = list?.length ? [...list] : null;
    if (String(next) === String(sources)) return;
    sources = next;
    if (tree && mountEl) render(0);
  }

  return { load, covers, active, mount, reset, query, summary, hasChoice, setSources };
})();
