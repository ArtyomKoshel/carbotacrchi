const Filters = (() => {
  let filtersData = null;
  let optionsSelectInstance  = null;
  let makeSelectInstance     = null;
  let _countTimer = null;
  let _contextTimer = null;
  let _onChange = null;   // the shell: re-run the search (desktop) or refresh the count (phone)
  let _onCount  = null;   // (n | null) → shown on the "Показать N лотов" button
  let _catalogRestore = null; // ref* fields of an applied query, picked once the make's catalog loads
  const SORTS = ['date', 'price_asc', 'price_desc', 'mileage_asc', 'mileage_desc', 'year_desc', 'year_asc'];
  const SORTS2 = ['', ...SORTS];
  const COUNT_DEBOUNCE_MS = 700;
  const CONTEXT_DEBOUNCE_MS = 400;

  const state = {
    sources:          [], // filled from /api/filters sources
    make:             '',
    model:            '',
    badge:            '',
    yearFrom:         '',
    yearTo:           '',
    priceMin:         '',
    priceMax:         '',
    mileageMin:       '',
    mileageMax:       '',
    engineMin:        '',
    engineMax:        '',
    seatsMin:         '',
    seatsMax:         '',
    bodyTypes:        [],
    transmissions:    [],
    fuelTypes:        [],
    driveTypes:       [],
    colors:           [],
    damageTypes:      [],
    titleTypes:       [],
    lienStatuses:     [],
    seizureStatuses:  [],
    trim:             '',
    hasAccident:      null,
    floodHistory:     null,
    totalLossHistory: null,
    ownersCountMin:   '',
    ownersCountMax:   '',
    insuranceCountMin:'',
    insuranceCountMax:'',
    listedAfter:      '',
    listedBefore:     '',
    firstRegAfter:    '',
    firstRegBefore:   '',
    sort:             'date',
    sort2:            '',
    options:          [],
  };

  function _sourceKeys() {
    return (filtersData?.sources ?? []).map(s => s.key).filter(Boolean);
  }

  function resetState() {
    state.sources = _sourceKeys();
    state.make = ''; state.model = ''; state.badge = '';
    state.yearFrom = ''; state.yearTo = '';
    state.priceMin = ''; state.priceMax = '';
    state.mileageMin = ''; state.mileageMax = '';
    state.engineMin = ''; state.engineMax = '';
    state.seatsMin = ''; state.seatsMax = '';
    state.bodyTypes = []; state.transmissions = [];
    state.fuelTypes = []; state.driveTypes = [];
    state.colors = []; state.damageTypes = []; state.titleTypes = [];
    state.trim = '';
    state.hasAccident = null; state.floodHistory = null; state.totalLossHistory = null;
    state.lienStatuses = []; state.seizureStatuses = [];
    state.ownersCountMin = ''; state.ownersCountMax = '';
    state.insuranceCountMin = ''; state.insuranceCountMax = '';
    state.listedAfter = ''; state.listedBefore = '';
    state.firstRegAfter = ''; state.firstRegBefore = '';
    state.sort = 'date'; state.sort2 = ''; state.options = [];
    CatalogFilter.reset();
  }

  // ── Field metadata: maps field_name → UI render config ──
  // Labels come from API (field.label from bot_filter_settings.field_label)
  const FIELD_UI = {
    source:             { type: 'source_chips' },
    make:               { type: 'make_model' },
    model:              { type: 'skip' },
    badge:              { type: 'skip' },
    trim:               { type: 'skip' },
    year:               { type: 'range',      idMin: 'filter-year-from',       idMax: 'filter-year-to',       inputType: 'number', min: 1990, max: new Date().getFullYear() },
    first_reg_date:     { type: 'range',      idMin: 'filter-first-reg-after', idMax: 'filter-first-reg-before', inputType: 'date', step: '1' },
    listed_at:          { type: 'range',      idMin: 'filter-listed-after',    idMax: 'filter-listed-before',    inputType: 'date', step: '1' },
    price:              { type: 'range',      idMin: 'filter-price-min',       idMax: 'filter-price-max',        inputType: 'number', min: 0, prefix: '₩' },
    mileage:            { type: 'range',      idMin: 'filter-mileage-min',     idMax: 'filter-mileage-max',      inputType: 'number', min: 0 },
    engine_volume:      { type: 'range',      idMin: 'filter-engine-min',      idMax: 'filter-engine-max',       inputType: 'number', min: 0, step: '0.1' },
    seat_count:         { type: 'range',      idMin: 'filter-seats-min',       idMax: 'filter-seats-max',        inputType: 'number', min: 1, max: 12 },
    fuel:               { type: 'chips',      stateKey: 'fuelTypes',     optionsKey: 'fuelTypeOptions',     fallbackKey: 'fuelTypes' },
    transmission:       { type: 'chips',      stateKey: 'transmissions', optionsKey: 'transmissionOptions', fallbackKey: 'transmissions' },
    body_type:          { type: 'chips',      stateKey: 'bodyTypes',     optionsKey: 'bodyTypeOptions',     fallbackKey: 'bodyTypes' },
    drive_type:         { type: 'chips',      stateKey: 'driveTypes',    optionsKey: 'driveTypeOptions',    fallbackKey: 'driveTypes' },
    color:              { type: 'chips',      stateKey: 'colors',        optionsKey: 'colorOptions',        fallbackKey: 'colors' },
    has_accident:       { type: 'bool_chips', stateKey: 'hasAccident',       options: [{value:'',label:'Любые'},{value:'false',label:'Без ДТП'},{value:'true',label:'Было ДТП'},{value:'unknown',label:'Нет данных'}] },
    flood_history:      { type: 'bool_chips', stateKey: 'floodHistory',       options: [{value:'',label:'Любые'},{value:'false',label:'Не тонул'},{value:'true',label:'Было затопление'}] },
    total_loss_history: { type: 'bool_chips', stateKey: 'totalLossHistory',   options: [{value:'',label:'Любые'},{value:'false',label:'Не тотал'},{value:'true',label:'Тотал'}] },
    insurance_count:    { type: 'range',      idMin: 'filter-insurance-min',   idMax: 'filter-insurance-max',  inputType: 'number', min: 0 },
    owners_count:       { type: 'range',      idMin: 'filter-owners-min',      idMax: 'filter-owners-max',     inputType: 'number', min: 0, max: 20 },
    lien_status:        { type: 'chips',      stateKey: 'lienStatuses',   optionsKey: null, fallbackKey: null, staticOptions: [{value:'clean',label:'Без залога'}] },
    seizure_status:     { type: 'chips',      stateKey: 'seizureStatuses',optionsKey: null, fallbackKey: null, staticOptions: [{value:'clean',label:'Без ареста'}] },
    options:            { type: 'options_select', id: 'filter-options' },
  };

  function getEnabledFields() {
    const fields = filtersData?.filterFields ?? [];
    return fields.filter(f => f.enabled);
  }

  async function init() {
    try {
      [filtersData] = await Promise.all([API.getFilters('ru'), CatalogFilter.load()]);
      Taxonomy.ingestFilters(filtersData ?? {});
      state.sources = _sourceKeys();
      render();
    } catch (e) {
      console.error('Filters load failed', e);
      render();
    }
  }

  function render() {
    const container = document.getElementById('filters-container');
    if (!container) return;

    // Destroy all Tom Select instances before wiping the DOM
    _destroySelectInstances();

    container.innerHTML = '';

    const enabled = getEnabledFields();
    const rendered = new Set();

    for (const field of enabled) {
      const name = field.name;
      if (rendered.has(name)) continue;
      const ui = FIELD_UI[name];
      if (!ui || ui.type === 'skip') continue;
      // Nothing to choose with a single source; the section returns once the API offers more
      if (name === 'source' && _sourceKeys().length < 2) continue;
      rendered.add(name);

      if (name === 'make') { rendered.add('model'); rendered.add('trim'); }

      const section = buildFilterSection(name, ui, field.label);
      if (section) container.appendChild(section);
    }

    // Always add sources at top if not from filterFields
    if (!rendered.has('source') && _sourceKeys().length > 1) {
      const srcSection = buildFilterSection('source', FIELD_UI.source, 'Источники');
      if (srcSection) container.insertBefore(srcSection, container.firstChild);
    }

    // Always add options multi-select at the bottom when catalog has data
    if ((filtersData?.options ?? []).length > 0 && !rendered.has('options')) {
      const optSection = buildFilterSection('options', FIELD_UI.options, 'Опции / Комплектация');
      if (optSection) container.appendChild(optSection);
    }

    // Populate dynamic content
    populateFilters();

    // Keep reset button state synced after full rerender.
    _updateResetBtn();
  }

  function buildFilterSection(name, ui, apiLabel) {
    const ALWAYS_OPEN = ['source'];
    const canCollapse = !ALWAYS_OPEN.includes(name);
    const collapsed   = canCollapse && _getCollapsed(name);

    const section = document.createElement('div');
    section.className = 'filter-section';
    section.dataset.filterField = name;

    // ── Header ──
    const header = document.createElement('div');
    header.className = canCollapse
      ? `filter-label filter-label--toggle${collapsed ? ' filter-label--collapsed' : ''}`
      : 'filter-label';

    const labelSpan = document.createElement('span');
    labelSpan.className = 'filter-label__text';
    const displayLabel = name === 'make'
      ? 'Марка / Модель / Комплектация'
      : (apiLabel || name);
    labelSpan.textContent = displayLabel;
    header.appendChild(labelSpan);

    if (canCollapse) {
      const badge = document.createElement('span');
      badge.className = 'filter-active-badge';
      badge.id = `filter-badge-${name}`;
      badge.style.display = 'none';
      header.appendChild(badge);

      const chevron = document.createElement('span');
      chevron.className = 'filter-chevron';
      chevron.innerHTML = `<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M6 9l6 6 6-6"/></svg>`;
      header.appendChild(chevron);
    }

    section.appendChild(header);

    // ── Body (collapsible wrapper for controls) ──
    const body = document.createElement('div');
    body.className = collapsed ? 'filter-body filter-body--hidden' : 'filter-body';
    section.appendChild(body);

    // ── Controls (appended to body) ──
    switch (ui.type) {
      case 'source_chips': {
        const div = document.createElement('div');
        div.className = 'source-chips';
        div.id = 'source-chips';
        body.appendChild(div);
        break;
      }
      case 'make_model': {
        const selects = document.createElement('div');
        selects.className = 'filter-selects';
        selects.innerHTML = `
          <div class="filter-select-wrap">
            <select class="filter-select" id="filter-make"><option value="">Любая марка</option></select>
          </div>
          <div class="filter-selects" id="filter-catalog" hidden></div>`;
        body.appendChild(selects);
        break;
      }
      case 'text': {
        const input = document.createElement('input');
        input.className = 'filter-input';
        input.type = 'text';
        input.id = ui.id;
        input.placeholder = ui.placeholder || '';
        input.addEventListener('input', _scheduleCountUpdate);
        body.appendChild(input);
        break;
      }
      case 'range': {
        const row = document.createElement('div');
        row.className = 'filter-range-row';
        if (ui.prefix) {
          row.innerHTML = `
            <div class="filter-input-wrap">
              <span class="filter-input-prefix">${ui.prefix}</span>
              <input class="filter-input has-prefix" type="${ui.inputType||'number'}" id="${ui.idMin}" placeholder="От" min="${ui.min??''}" max="${ui.max??''}" step="${ui.step||'1'}">
            </div>
            <div class="filter-input-wrap">
              <span class="filter-input-prefix">${ui.prefix}</span>
              <input class="filter-input has-prefix" type="${ui.inputType||'number'}" id="${ui.idMax}" placeholder="До" min="${ui.min??''}" max="${ui.max??''}" step="${ui.step||'1'}">
            </div>`;
        } else {
          row.innerHTML = `
            <input class="filter-input" type="${ui.inputType||'number'}" id="${ui.idMin}" placeholder="От" min="${ui.min??''}" max="${ui.max??''}" step="${ui.step||'1'}">
            <input class="filter-input" type="${ui.inputType||'number'}" id="${ui.idMax}" placeholder="До" min="${ui.min??''}" max="${ui.max??''}" step="${ui.step||'1'}">`;
        }
        row.querySelectorAll('input').forEach(inp => inp.addEventListener('input', _scheduleCountUpdate));
        body.appendChild(row);
        break;
      }
      case 'chips': {
        const div = document.createElement('div');
        div.className = 'chip-group';
        div.id = `chips-${name}`;
        body.appendChild(div);
        break;
      }
      case 'bool_chips': {
        const div = document.createElement('div');
        div.className = 'chip-group';
        div.id = `chips-${name}`;
        body.appendChild(div);
        break;
      }
      case 'options_select': {
        const sel = document.createElement('select');
        sel.id = ui.id || 'filter-options';
        sel.multiple = true;
        sel.className = 'filter-options-ts';
        body.appendChild(sel);
        break;
      }
    }

    // ── Collapse toggle ──
    if (canCollapse) {
      header.addEventListener('click', () => {
        const nowCollapsed = body.classList.toggle('filter-body--hidden');
        header.classList.toggle('filter-label--collapsed', nowCollapsed);
        _setCollapsed(name, nowCollapsed);
      });
    }

    const divider = document.createElement('div');
    divider.className = 'filter-divider';

    const frag = document.createDocumentFragment();
    frag.appendChild(section);
    frag.appendChild(divider);
    return frag;
  }

  function _getCollapsed(name) {
    try {
      return !!(JSON.parse(localStorage.getItem('carbot_filter_collapsed') || '{}')[name]);
    } catch { return false; }
  }

  function _setCollapsed(name, val) {
    try {
      const s = JSON.parse(localStorage.getItem('carbot_filter_collapsed') || '{}');
      s[name] = val;
      localStorage.setItem('carbot_filter_collapsed', JSON.stringify(s));
    } catch {}
  }

  const OPTION_CATEGORY_LABELS = {
    exterior:    'Экстерьер',
    safety:      'Безопасность',
    convenience: 'Удобство',
    interior:    'Интерьер',
  };

  function initOptionsSelect(items) {
    const el = document.getElementById('filter-options');
    if (!el || !items.length || typeof TomSelect === 'undefined') return;

    // Build optgroups from distinct categories present in items
    const categoriesUsed = [...new Set(items.map(i => i.category).filter(Boolean))];
    const optgroups = categoriesUsed.map(k => ({
      value: k,
      label: OPTION_CATEGORY_LABELS[k] ?? k,
    }));
    const hasGroups = optgroups.length > 0;

    optionsSelectInstance = new TomSelect(el, {
      maxOptions:    null,
      maxItems:      null,
      plugins:       ['remove_button', 'clear_button'],
      placeholder:   'Выбрать опции…',
      searchField:   ['text'],
      optgroups:     hasGroups ? optgroups : [],
      optgroupField: hasGroups ? 'optgroup' : undefined,
      options: items.map(item => ({
        value:    item.value,
        text:     item.label ?? item.value,
        ...(hasGroups && item.category ? { optgroup: item.category } : {}),
      })),
      items: state.options,  // pre-select restored values
      onChange(vals) { state.options = Array.isArray(vals) ? vals : (vals ? [vals] : []); _scheduleCountUpdate(); },
    });
  }

  function populateFilters() {
    renderSourceChips();
    renderMakeSelect();
    renderCatalog();
    initOptionsSelect(filtersData?.options ?? []);

    const enabled = getEnabledFields();
    for (const field of enabled) {
      const name = field.name;
      const ui = FIELD_UI[name];
      if (!ui) continue;

      if (ui.type === 'chips') {
        const items = ui.staticOptions ?? (filtersData?.[ui.optionsKey] ?? filtersData?.[ui.fallbackKey] ?? []);
        renderChipGroup(`chips-${name}`, items, ui.stateKey);
      } else if (ui.type === 'bool_chips') {
        renderBoolChips(`chips-${name}`, ui.options, ui.stateKey);
      }
    }

    if (state.make) {
      _scheduleContextUpdate();
    }
  }

  function renderChipGroup(containerId, items, stateKey) {
    const container = document.getElementById(containerId);
    if (!container) return;

    const options = Taxonomy.normalizeOptionItems(items);
    if (!Array.isArray(options) || options.length === 0) {
      container.innerHTML = '<span class="filter-empty">нет данных</span>';
      return;
    }

    // Several raw values can share one label (e.g. 'Blue', '청옥색', '하늘색' → Синий):
    // one chip per label, selecting it selects all of its raw values.
    const groups = [];
    const byLabel = new Map();
    for (const o of options) {
      let g = byLabel.get(o.label);
      if (!g) { g = { label: o.label, values: [] }; byLabel.set(o.label, g); groups.push(g); }
      if (!g.values.includes(o.value)) g.values.push(o.value);
    }
    const isSelected = g => g.values.some(v => (state[stateKey] || []).includes(v));

    container.innerHTML = groups.map((g, i) => `
      <button class="filter-chip${isSelected(g) ? ' selected' : ''}"
              data-group="${i}">${escHtml(g.label)}</button>
    `).join('');
    container.querySelectorAll('.filter-chip').forEach(btn => {
      btn.addEventListener('click', () => {
        const g = groups[Number(btn.dataset.group)];
        if (!state[stateKey]) state[stateKey] = [];
        if (isSelected(g)) {
          state[stateKey] = state[stateKey].filter(x => !g.values.includes(x));
        } else {
          state[stateKey].push(...g.values.filter(v => !state[stateKey].includes(v)));
        }
        btn.classList.toggle('selected', isSelected(g));
        TG.haptic('selection');
        _scheduleCountUpdate();
      });
    });
  }

  function renderBoolChips(containerId, options, stateKey) {
    const container = document.getElementById(containerId);
    if (!container) return;
    const current = state[stateKey] === null ? '' : String(state[stateKey]);
    container.innerHTML = options.map(o =>
      `<button class="filter-chip${current === o.value ? ' selected' : ''}" data-value="${o.value}">${o.label}</button>`
    ).join('');
    container.querySelectorAll('.filter-chip').forEach(btn => {
      btn.addEventListener('click', () => {
        const v = btn.dataset.value;
        state[stateKey] = v === '' ? null : v === 'unknown' ? v : v === 'true';
        container.querySelectorAll('.filter-chip').forEach(b =>
          b.classList.toggle('selected', b.dataset.value === v));
        TG.haptic('selection');
        _scheduleCountUpdate();
      });
    });
  }

  function renderSourceChips() {
    const container = document.getElementById('source-chips');
    if (!container) return;
    const sources = filtersData?.sources ?? [];
    container.innerHTML = sources.map(s => `
      <button class="source-chip${state.sources.includes(s.key)?' selected':''}"
              data-key="${s.key}">${s.name}</button>
    `).join('');
    container.querySelectorAll('.source-chip').forEach(btn => {
      btn.addEventListener('click', () => {
        const k = btn.dataset.key;
        if (state.sources.includes(k)) {
          if (state.sources.length > 1) state.sources = state.sources.filter(x => x !== k);
        } else {
          state.sources.push(k);
        }
        btn.classList.toggle('selected', state.sources.includes(k));
        TG.haptic('selection');
        _scheduleCountUpdate();
      });
    });
  }

  function escHtml(v) {
    return String(v ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  }

  function _destroySelectInstances() {
    for (const inst of [optionsSelectInstance, makeSelectInstance]) {
      if (inst) { try { inst.destroy(); } catch (_) {} }
    }
    optionsSelectInstance    = null;
    makeSelectInstance       = null;
  }

  function _tsBase(el, opts) {
    if (!el || typeof TomSelect === 'undefined') return null;
    const ts = new TomSelect(el, {
      valueField:       'value',
      labelField:       'text',
      searchField:      ['text'],
      allowEmptyOption: true,
      maxOptions:       null,
      ...opts,
    });
    ts.wrapper.classList.add('ts-single');
    return ts;
  }

  function renderMakeSelect() {
    const el = document.getElementById('filter-make');
    if (!el) return;
    const makes = Taxonomy.normalizeOptionItems(filtersData?.makeOptions ?? [])
      .map(o => ({ value: o.value, text: o.label }));

    makeSelectInstance = _tsBase(el, {
      maxItems: 1,
      options:  [{ value: '', text: 'Любая марка' }, ...makes],
      items:    state.make ? [state.make] : [''],
      onChange(val) {
        const v = val || '';
        if (state.make === v) return;
        state.make = v; state.model = ''; state.badge = ''; state.trim = '';
        renderCatalog();
        _scheduleContextUpdate(); _scheduleCountUpdate();
      },
    });
  }

  // the make's reference catalog below the make: model → generation → modification → complectation
  function renderCatalog() {
    const el = document.getElementById('filter-catalog');
    if (!el) return;
    const restore = _catalogRestore;
    _catalogRestore = null;
    if (!state.make) { CatalogFilter.reset(); return; }
    CatalogFilter.mount(el, state.make, () => { _updateResetBtn(); _scheduleContextUpdate(); _scheduleCountUpdate(); }, restore)
      .then(() => { if (restore) _scheduleCountUpdate(); });
  }

  function readFormState() {
    state.yearFrom         = document.getElementById('filter-year-from')?.value    ?? '';
    state.yearTo           = document.getElementById('filter-year-to')?.value      ?? '';
    state.priceMin         = document.getElementById('filter-price-min')?.value    ?? '';
    state.priceMax         = document.getElementById('filter-price-max')?.value    ?? '';
    state.mileageMin       = document.getElementById('filter-mileage-min')?.value  ?? '';
    state.mileageMax       = document.getElementById('filter-mileage-max')?.value  ?? '';
    state.engineMin        = document.getElementById('filter-engine-min')?.value   ?? '';
    state.engineMax        = document.getElementById('filter-engine-max')?.value   ?? '';
    state.seatsMin         = document.getElementById('filter-seats-min')?.value    ?? '';
    state.seatsMax         = document.getElementById('filter-seats-max')?.value    ?? '';
    // badge and trim are managed by Tom Select instances — state already up-to-date
    state.ownersCountMin   = document.getElementById('filter-owners-min')?.value   ?? '';
    state.ownersCountMax   = document.getElementById('filter-owners-max')?.value   ?? '';
    state.insuranceCountMin= document.getElementById('filter-insurance-min')?.value ?? '';
    state.insuranceCountMax= document.getElementById('filter-insurance-max')?.value ?? '';
    state.listedAfter      = document.getElementById('filter-listed-after')?.value ?? '';
    state.listedBefore     = document.getElementById('filter-listed-before')?.value ?? '';
    state.firstRegAfter    = document.getElementById('filter-first-reg-after')?.value ?? '';
    state.firstRegBefore   = document.getElementById('filter-first-reg-before')?.value ?? '';
  }

  function getQuery() {
    readFormState();
    // Sync options from Tom Select (single source of truth)
    if (optionsSelectInstance) {
      state.options = [...optionsSelectInstance.items];
    }
    const catalog = CatalogFilter.query();
    return {
      ...catalog,
      make:             state.make              || undefined,
      model:            catalog.refMakeId ? undefined : (state.model || undefined),
      badge:            catalog.refMakeId ? undefined : (state.badge || undefined),
      yearFrom:         state.yearFrom          ? parseInt(state.yearFrom)          : undefined,
      yearTo:           state.yearTo            ? parseInt(state.yearTo)            : undefined,
      priceMin:         state.priceMin          ? parseInt(state.priceMin)          : undefined,
      priceMax:         state.priceMax          ? parseInt(state.priceMax)          : undefined,
      mileageMin:       state.mileageMin        ? parseInt(state.mileageMin)        : undefined,
      mileageMax:       state.mileageMax        ? parseInt(state.mileageMax)        : undefined,
      engineMin:        state.engineMin         ? parseFloat(state.engineMin)       : undefined,
      engineMax:        state.engineMax         ? parseFloat(state.engineMax)       : undefined,
      seatCountMin:     state.seatsMin          ? parseInt(state.seatsMin)          : undefined,
      seatCountMax:     state.seatsMax          ? parseInt(state.seatsMax)          : undefined,
      bodyTypes:        state.bodyTypes.length      ? state.bodyTypes      : undefined,
      transmissions:    state.transmissions.length  ? state.transmissions  : undefined,
      fuelTypes:        state.fuelTypes.length      ? state.fuelTypes      : undefined,
      driveTypes:       state.driveTypes.length     ? state.driveTypes     : undefined,
      colors:           state.colors.length         ? state.colors         : undefined,
      damageTypes:      state.damageTypes.length      ? state.damageTypes      : undefined,
      titleTypes:       state.titleTypes.length       ? state.titleTypes       : undefined,
      lienStatuses:     state.lienStatuses.length     ? state.lienStatuses     : undefined,
      seizureStatuses:  state.seizureStatuses.length  ? state.seizureStatuses  : undefined,
      trim:             catalog.refMakeId ? undefined : (state.trim || undefined),
      hasAccident:      state.hasAccident !== null ? state.hasAccident     : undefined,
      floodHistory:     state.floodHistory !== null ? state.floodHistory   : undefined,
      totalLossHistory: state.totalLossHistory !== null ? state.totalLossHistory : undefined,
      ownersCountMin:   state.ownersCountMin    ? parseInt(state.ownersCountMin)    : undefined,
      ownersCountMax:   state.ownersCountMax    ? parseInt(state.ownersCountMax)    : undefined,
      insuranceCountMin:state.insuranceCountMin ? parseInt(state.insuranceCountMin) : undefined,
      insuranceCountMax:state.insuranceCountMax ? parseInt(state.insuranceCountMax) : undefined,
      listedAfter:      state.listedAfter       || undefined,
      listedBefore:     state.listedBefore      || undefined,
      firstRegAfter:    state.firstRegAfter     || undefined,
      firstRegBefore:   state.firstRegBefore    || undefined,
      options:          state.options.length   ? state.options  : undefined,
      sources:          state.sources.length ? state.sources : undefined,
      sort:             state.sort,
      sort2:            state.sort2 || undefined,
      limit:            40,
    };
  }

  function applyQuery(q) {
    if (!q) return;
    resetState();

    const setEl = (id, val) => {
      const el = document.getElementById(id);
      if (!el) return;
      el.value = (val === undefined || val === null) ? '' : String(val);
    };

    if (q.make)  { state.make  = q.make;  }
    _catalogRestore = (q.refMakeId || q.model) ? q : null;
    if (q.model) { state.model = q.model; }
    if (q.badge) { state.badge = q.badge; }
    if (q.trim)  { state.trim  = q.trim;  }
    if (q.bodyTypes)        state.bodyTypes        = q.bodyTypes;
    if (q.transmissions)    state.transmissions    = q.transmissions;
    if (q.fuelTypes)        state.fuelTypes        = q.fuelTypes;
    if (q.driveTypes)       state.driveTypes       = q.driveTypes;
    if (q.colors)           state.colors           = q.colors;
    if (q.damageTypes)      state.damageTypes      = q.damageTypes;
    if (q.titleTypes)       state.titleTypes       = q.titleTypes;
    if (q.lienStatuses)     state.lienStatuses     = q.lienStatuses;
    if (q.seizureStatuses)  state.seizureStatuses  = q.seizureStatuses;
    if (Array.isArray(q.sources)) {
      // Saved queries may name sources that are no longer offered
      const available = _sourceKeys();
      const kept = available.length ? q.sources.filter(k => available.includes(k)) : q.sources;
      if (kept.length) state.sources = kept;
    }
    if (q.sort)             state.sort             = SORTS.includes(q.sort) ? q.sort : 'date';
    if (q.sort2 !== undefined) state.sort2 = SORTS2.includes(q.sort2) && q.sort2 !== state.sort ? q.sort2 : '';
    if (q.ownersCountMin !== undefined)    state.ownersCountMin    = String(q.ownersCountMin ?? '');
    if (q.ownersCountMax !== undefined)    state.ownersCountMax    = String(q.ownersCountMax ?? '');
    if (q.insuranceCountMin !== undefined) state.insuranceCountMin = String(q.insuranceCountMin ?? '');
    if (q.insuranceCountMax !== undefined) state.insuranceCountMax = String(q.insuranceCountMax ?? '');
    if (q.listedAfter !== undefined)       state.listedAfter       = String(q.listedAfter ?? '');
    if (q.listedBefore !== undefined)      state.listedBefore      = String(q.listedBefore ?? '');
    if (q.firstRegAfter !== undefined)     state.firstRegAfter     = String(q.firstRegAfter ?? '');
    if (q.firstRegBefore !== undefined)    state.firstRegBefore    = String(q.firstRegBefore ?? '');
    if (q.hasAccident !== undefined && q.hasAccident !== null) state.hasAccident = q.hasAccident;
    if (q.floodHistory !== undefined && q.floodHistory !== null) state.floodHistory = q.floodHistory;
    if (q.totalLossHistory !== undefined && q.totalLossHistory !== null) state.totalLossHistory = q.totalLossHistory;
    if (q.seatCountMin !== undefined) state.seatsMin = String(q.seatCountMin ?? '');
    if (q.seatCountMax !== undefined) state.seatsMax = String(q.seatCountMax ?? '');
    if (Array.isArray(q.options) && q.options.length) state.options = q.options;

    render();
    setEl('filter-year-from',     q.yearFrom);
    setEl('filter-year-to',       q.yearTo);
    setEl('filter-price-min',     q.priceMin);
    setEl('filter-price-max',     q.priceMax);
    setEl('filter-mileage-min',   q.mileageMin);
    setEl('filter-mileage-max',   q.mileageMax);
    setEl('filter-engine-min',    q.engineMin);
    setEl('filter-engine-max',    q.engineMax);
    setEl('filter-seats-min',     q.seatCountMin);
    setEl('filter-seats-max',     q.seatCountMax);
    setEl('filter-owners-min',    q.ownersCountMin);
    setEl('filter-owners-max',    q.ownersCountMax);
    setEl('filter-insurance-min', q.insuranceCountMin);
    setEl('filter-insurance-max', q.insuranceCountMax);
    setEl('filter-listed-after',  q.listedAfter);
    setEl('filter-listed-before', q.listedBefore);
    setEl('filter-first-reg-after',  q.firstRegAfter);
    setEl('filter-first-reg-before', q.firstRegBefore);
    setEl('sort-select', state.sort);
    setEl('sort2-select', state.sort2);

    // Keep reset button state in sync when query is applied from cache/deeplink
    // (without user interaction events).
    _updateResetBtn();
  }

  function getCardFields() {
    return Array.isArray(filtersData?.cardFields) ? filtersData.cardFields : [];
  }

  function setSort(sort) {
    state.sort = SORTS.includes(sort) ? sort : 'date';
    // clear secondary if it now conflicts
    if (state.sort2 === state.sort) state.sort2 = '';
  }

  function setSort2(sort2) {
    state.sort2 = (SORTS2.includes(sort2) && sort2 !== state.sort) ? sort2 : '';
  }

  // ── Live count ─────────────────────────────────────────────────────────────

  function _scheduleCountUpdate() {
    _updateResetBtn();
    if (typeof _onChange === 'function') _onChange();
    clearTimeout(_countTimer);
    _countTimer = setTimeout(_updateCount, COUNT_DEBOUNCE_MS);
  }

  function _updateResetBtn() {
    const btn = document.getElementById('filters-reset-btn');
    if (!btn) return;
    const active = hasActive();
    btn.disabled = !active;
  }

  function hasActive() {
    return !!(
      state.make || state.model || state.badge || state.trim || CatalogFilter.hasChoice() ||
      state.yearFrom || state.yearTo ||
      state.priceMin || state.priceMax ||
      state.mileageMin || state.mileageMax ||
      state.engineMin || state.engineMax ||
      state.seatsMin || state.seatsMax ||
      state.bodyTypes.length || state.transmissions.length ||
      state.fuelTypes.length || state.driveTypes.length ||
      state.colors.length || state.damageTypes.length || state.titleTypes.length ||
      state.lienStatuses.length || state.seizureStatuses.length ||
      state.hasAccident !== null || state.floodHistory !== null || state.totalLossHistory !== null ||
      state.ownersCountMin || state.ownersCountMax ||
      state.insuranceCountMin || state.insuranceCountMax ||
      state.listedAfter || state.listedBefore ||
      state.firstRegAfter || state.firstRegBefore ||
      state.options.length
    );
  }

  function reset() {
    resetState();
    render();
    _updateResetBtn();
    // Reset AI chat context and history
    if (typeof Chat !== 'undefined') Chat.reset();
    // Clear last search so page reload doesn't restore the old query
    try { localStorage.removeItem('lastSearch'); } catch (_) {}
    TG.haptic('notification', 'success');
  }

  async function _updateCount() {
    if (typeof _onCount !== 'function') return;
    _onCount(undefined); // counting…
    try {
      const data = await API.getCount(getQuery());
      _onCount(data?.count ?? 0);
    } catch (_) {
      _onCount(null); // not critical: the button keeps a plain label
    }
  }

  function _ruLots(n) {
    const mod10 = n % 10, mod100 = n % 100;
    let word;
    if (mod10 === 1 && mod100 !== 11) word = 'машина';
    else if (mod10 >= 2 && mod10 <= 4 && (mod100 < 10 || mod100 >= 20)) word = 'машины';
    else word = 'машин';
    return `${n.toLocaleString('ru')} ${word}`;
  }

  // ── Cascading context: update chips for make/model selection ──────────────

  function _scheduleContextUpdate() {
    clearTimeout(_contextTimer);
    _contextTimer = setTimeout(_updateContext, CONTEXT_DEBOUNCE_MS);
  }

  async function _updateContext() {
    if (!state.make) return;
    try {
      const { refMakeId, refModelId } = CatalogFilter.query();
      const params = { make: state.make };
      if (refMakeId) params.refMakeId = refMakeId;
      if (refModelId) params.refModelId = refModelId;
      const ctx = await API.getContext(params);
      if (!ctx) return;

      // Update chip groups with context-aware options
      const updates = {
        body_type:    { optKey: 'bodyTypeOptions',     stateKey: 'bodyTypes' },
        transmission: { optKey: 'transmissionOptions', stateKey: 'transmissions' },
        fuel:         { optKey: 'fuelTypeOptions',     stateKey: 'fuelTypes' },
        drive_type:   { optKey: 'driveTypeOptions',    stateKey: 'driveTypes' },
        color:        { optKey: 'colorOptions',        stateKey: 'colors' },
      };

      for (const [fieldName, { optKey, stateKey }] of Object.entries(updates)) {
        const items = ctx[optKey];
        if (Array.isArray(items) && items.length) {
          renderChipGroup(`chips-${fieldName}`, items, stateKey);
        }
      }
    } catch (_) {
      // Context update is best-effort
    }
  }

  const fmtNum = v => Number(v).toLocaleString('ru-RU').replace(/\s/g, '\u202f');
  const range = (a, b, unit = '', f = fmtNum) => a && b ? `${f(a)}–${f(b)}${unit}` : a ? `от ${f(a)}${unit}` : `до ${f(b)}${unit}`;
  const priceM = v => {
    const n = Number(v);
    return n >= 1e6 ? `${(n / 1e6).toLocaleString('ru-RU', { maximumFractionDigits: 1 })} млн` : fmtNum(n);
  };

  /** What is applied, one chip each: {key, label}. clear(key) removes just that one. */
  function activeChips() {
    readFormState();
    const q = getQuery();
    const chips = [];
    const add = (key, label) => label && chips.push({ key, label });
    if (q.make) add('make', [Taxonomy.label('make', q.make) || q.make, q.refMakeId ? CatalogFilter.summary() : q.model].filter(Boolean).join(' '));
    if (q.badge) add('badge', q.badge);
    if (q.trim) add('trim', Taxonomy.label('trim', q.trim) || q.trim);
    if (q.yearFrom || q.yearTo) add('year', range(q.yearFrom, q.yearTo, '', String));
    if (q.priceMin || q.priceMax) add('price', '₩' + range(q.priceMin, q.priceMax, '', priceM));
    if (q.mileageMin || q.mileageMax) add('mileage', range(q.mileageMin, q.mileageMax, ' км'));
    if (q.engineMin || q.engineMax) add('engine', range(q.engineMin, q.engineMax, ' л', String));
    if (q.seatCountMin || q.seatCountMax) add('seats', range(q.seatCountMin, q.seatCountMax, ' мест', String));
    const many = (key, arr, field) => arr?.length && add(key,
      [...new Set(arr.map(v => Taxonomy.label(field, v) || v))].slice(0, 2).join(', ') + (new Set(arr.map(v => Taxonomy.label(field, v) || v)).size > 2 ? ' …' : ''));
    many('fuelTypes', q.fuelTypes, 'fuel');
    many('transmissions', q.transmissions, 'transmission');
    many('driveTypes', q.driveTypes, 'drive_type');
    many('bodyTypes', q.bodyTypes, 'body_type');
    many('colors', q.colors, 'color');
    if (q.hasAccident !== undefined) add('hasAccident', q.hasAccident === 'unknown' ? 'ДТП: нет данных' : q.hasAccident ? 'было ДТП' : 'без ДТП');
    if (q.floodHistory !== undefined) add('floodHistory', q.floodHistory ? 'было затопление' : 'не тонул');
    if (q.totalLossHistory !== undefined) add('totalLossHistory', q.totalLossHistory ? 'тотал' : 'не тотал');
    if (q.lienStatuses?.length) add('lienStatuses', 'без залога');
    if (q.seizureStatuses?.length) add('seizureStatuses', 'без ареста');
    if (q.ownersCountMin || q.ownersCountMax) add('owners', 'владельцев ' + range(q.ownersCountMin, q.ownersCountMax, '', String));
    if (q.insuranceCountMin || q.insuranceCountMax) add('insurance', 'страховых ' + range(q.insuranceCountMin, q.insuranceCountMax, '', String));
    if (q.listedAfter || q.listedBefore) add('listed', 'выставлен ' + range(q.listedAfter, q.listedBefore, '', String));
    if (q.firstRegAfter || q.firstRegBefore) add('firstReg', 'регистрация ' + range(q.firstRegAfter, q.firstRegBefore, '', String));
    if (q.options?.length) add('options', `опции: ${q.options.length}`);
    return chips;
  }

  const CLEAR = {
    make: ['make', 'model', 'badge', 'trim', 'refMakeId', 'refModelId', 'refGenerationIds', 'refModificationIds', 'refComplectationIds'],
    badge: ['badge', 'trim'], trim: ['trim'],
    year: ['yearFrom', 'yearTo'], price: ['priceMin', 'priceMax'], mileage: ['mileageMin', 'mileageMax'],
    engine: ['engineMin', 'engineMax'], seats: ['seatCountMin', 'seatCountMax'],
    owners: ['ownersCountMin', 'ownersCountMax'], insurance: ['insuranceCountMin', 'insuranceCountMax'],
    listed: ['listedAfter', 'listedBefore'], firstReg: ['firstRegAfter', 'firstRegBefore'],
  };

  function clear(key) {
    readFormState();
    const q = getQuery();
    for (const k of CLEAR[key] ?? [key]) delete q[k];
    applyQuery(q);
    _scheduleCountUpdate();
  }

  function onChange(fn) { _onChange = fn; }
  function onCount(fn) { _onCount = fn; }
  function refreshCount() { _updateCount(); }

  return { init, getQuery, getCardFields, setSort, setSort2, applyQuery, reset, hasActive, activeChips, clear, onChange, onCount, refreshCount, SORTS, notifyChange: _scheduleCountUpdate };
})();
