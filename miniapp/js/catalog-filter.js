/**
 * Model → generation → modification → complectation, from the reference catalog
 * (GET /api/catalog/{makeId}), for the makes it covers. Filters keeps make; this module
 * owns the four selects below it and the ref* fields of the query.
 *
 * The cascade runs on the client from the make's `cells` [model, generation, modification,
 * complectation, lots]: each list shows what the choices above leave, with how many lots.
 * A modification code ("520i") is one choice across the selected generations.
 */
const CatalogFilter = (() => {
  let makes = null;          // lower-case name → {id, name, lots}
  let tree = null;           // the current make's /api/catalog/{id}
  let seq = 0;
  let mountEl = null;
  let changed = () => {};
  const ts = {};
  const sel = { model: 0, generations: [], modifications: [], complectations: [] }; // modifications: codes

  async function load() {
    try {
      const data = await API.getCatalogMakes();
      makes = new Map((data?.makes ?? []).map(m => [m.name.toLowerCase(), m]));
    } catch (_) {
      makes = new Map(); // no catalog: every make keeps the old filters
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
      sel.model = Number(restore.refModelId) || 0;
      sel.generations = (restore.refGenerationIds ?? []).map(Number);
      sel.complectations = (restore.refComplectationIds ?? []).map(Number);
      const ids = new Set((restore.refModificationIds ?? []).map(Number));
      sel.modifications = [...new Set(tree.modifications.filter(m => ids.has(m.id)).map(m => m.code))];
    }
    skeleton();
    return true;
  }

  // ── the cascade ──────────────────────────────────────────────────────────

  const byId = list => new Map((list ?? []).map(x => [x.id, x]));

  /** Cells the choices above `level` leave: 0 model, 1 generation, 2 modification, 3 complectation. */
  function cells(level) {
    const mods = byId(tree.modifications);
    return tree.cells.filter(([m, g, d]) =>
      (level < 1 || !sel.model || m === sel.model)
      && (level < 2 || !sel.generations.length || sel.generations.includes(g))
      && (level < 3 || !sel.modifications.length || (d && sel.modifications.includes(mods.get(d)?.code))));
  }

  function counts(level, key) {
    const out = new Map();
    for (const c of cells(level)) {
      const k = key(c);
      if (k === null || k === undefined) continue;
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

  /**
   * Rebuilds the selects from `from` down (0 model … 3 complectation); the ones above keep
   * their state, so a multi-select stays open while several are picked.
   */
  function render(from) {
    const gens = byId(tree.generations), mods = byId(tree.modifications), models = byId(tree.models), comps = byId(tree.complectations);
    const modelCounts = counts(0, c => c[0]);
    const genCounts = counts(1, c => c[1]);
    const modCounts = counts(2, c => (c[2] ? mods.get(c[2])?.code : null));
    const compCounts = counts(3, c => c[3]);

    // a choice the cascade no longer offers is dropped
    if (from <= 0 && sel.model && !modelCounts.has(sel.model)) sel.model = 0;
    if (from <= 1) sel.generations = sel.generations.filter(id => genCounts.has(id));
    if (from <= 2) sel.modifications = sel.modifications.filter(code => modCounts.has(code));
    if (from <= 3) sel.complectations = sel.complectations.filter(id => compCounts.has(id));

    const build = {
      model: () => select('model', [{ value: '', text: 'Любая модель' },
        ...[...modelCounts].map(([id, k]) => ({ value: String(id), text: models.get(id)?.name ?? String(id), n: k }))
          .sort((a, b) => a.text.localeCompare(b.text, 'en', { numeric: true }))],
        sel.model ? [String(sel.model)] : [''], 1, v => {
          sel.model = Number(v) || 0; sel.generations = []; sel.modifications = []; sel.complectations = [];
          render(1); changed();
        }),
      gen: () => select('gen', [...genCounts].map(([id, k]) => ({ g: gens.get(id) ?? {}, id, k }))
          .sort((a, b) => (a.g.year_from ?? 0) - (b.g.year_from ?? 0))
          .map(({ g, id, k }) => ({ value: String(id), text: [g.code, years(g)].filter(Boolean).join(' · '), n: k })),
        sel.generations.map(String), null, v => { sel.generations = v.map(Number); render(2); changed(); }, 'Любое поколение'),
      mod: () => select('mod', [...modCounts].map(([code, k]) => ({ value: code, text: code, n: k }))
          .sort((a, b) => a.text.localeCompare(b.text, 'en', { numeric: true })),
        sel.modifications, null, v => { sel.modifications = v; render(3); changed(); }, 'Любая модификация'),
      comp: () => select('comp', [...compCounts].map(([id, k]) => ({ value: String(id), text: comps.get(id)?.name ?? String(id), n: k }))
          .sort((a, b) => b.n - a.n),
        sel.complectations.map(String), null, v => { sel.complectations = v.map(Number); changed(); }, 'Любая комплектация'),
    };
    const shown = { model: true, gen: !!sel.model, mod: !!sel.model, comp: compCounts.size > 0 };

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
        item: o => `<div>${esc(o.text)}</div>`,
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
    return {
      refMakeId: tree.make.id,
      refModelId: sel.model || undefined,
      refGenerationIds: sel.generations.length ? sel.generations : undefined,
      refModificationIds: mods.length ? mods.map(m => m.id) : undefined,
      refComplectationIds: sel.complectations.length ? sel.complectations : undefined,
    };
  }

  /** Words for the applied-filter chip: "5 Series G60 · 530i xDrive · M Sport". */
  function summary() {
    if (!tree) return '';
    const model = tree.models.find(m => m.id === sel.model)?.name;
    const gens = tree.generations.filter(g => sel.generations.includes(g.id)).map(g => g.code);
    const comps = tree.complectations.filter(c => sel.complectations.includes(c.id)).map(c => c.name);
    return [[model, gens.join(', ')].filter(Boolean).join(' '), sel.modifications.join(', '), comps.join(', ')].filter(Boolean).join(' · ');
  }

  const hasChoice = () => !!(sel.model || sel.complectations.length);

  return { load, covers, active, mount, reset, query, summary, hasChoice };
})();
