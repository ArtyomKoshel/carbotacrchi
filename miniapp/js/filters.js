const Filters = (() => {
  let filtersData = null;
  let trimRequestSeq = 0;
  let optionsSelectInstance  = null;
  let makeSelectInstance     = null;
  let modelSelectInstance    = null;
  let trimSelectInstance     = null;
  let generationSelectInstance = null;
  let _countTimer = null;
  let _contextTimer = null;
  const COUNT_DEBOUNCE_MS = 700;
  const CONTEXT_DEBOUNCE_MS = 400;

  const state = {
    sources:          ['encar', 'kbcha'],
    make:             '',
    model:            '',
    generation:       '',
    yearFrom:         '',
    yearTo:           '',
    priceMin:         '',
    priceMax:         '',
    mileageMin:       '',
    mileageMax:       '',
    engineMin:        '',
    engineMax:        '',
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
    ownersCountMin:   '',
    ownersCountMax:   '',
    insuranceCountMin:'',
    insuranceCountMax:'',
    listedAfter:      '',
    listedBefore:     '',
    firstRegAfter:    '',
    firstRegBefore:   '',
    sort:             'date',
    options:          [],
  };

  function resetState() {
    console.trace('[RESET] resetState called');
    const sourceKeys = (filtersData?.sources ?? []).map(s => s.key).filter(Boolean);
    state.sources = sourceKeys.length ? sourceKeys : ['encar', 'kbcha'];
    state.make = ''; state.model = ''; state.generation = '';
    state.yearFrom = ''; state.yearTo = '';
    state.priceMin = ''; state.priceMax = '';
    state.mileageMin = ''; state.mileageMax = '';
    state.engineMin = ''; state.engineMax = '';
    state.bodyTypes = []; state.transmissions = [];
    state.fuelTypes = []; state.driveTypes = [];
    state.colors = []; state.damageTypes = []; state.titleTypes = [];
    state.trim = '';
    state.hasAccident = null; state.floodHistory = null;
    state.lienStatuses = []; state.seizureStatuses = [];
    state.ownersCountMin = ''; state.ownersCountMax = '';
    state.insuranceCountMin = ''; state.insuranceCountMax = '';
    state.listedAfter = ''; state.listedBefore = '';
    state.firstRegAfter = ''; state.firstRegBefore = '';
    state.sort = 'date'; state.options = [];
  }

  // ── Field metadata: maps field_name → UI render config ──
  // Labels come from API (field.label from bot_filter_settings.field_label)
  const FIELD_UI = {
    source:         { type: 'source_chips' },
    make:           { type: 'make_model' },
    model:          { type: 'skip' },
    trim:           { type: 'skip' },
    generation:     { type: 'text', id: 'filter-generation', placeholder: 'G30, W213, NQ5, CN7...' },
    year:           { type: 'range', idMin: 'filter-year-from', idMax: 'filter-year-to', inputType: 'number', min: 1990, max: new Date().getFullYear() },
    price:          { type: 'range', idMin: 'filter-price-min', idMax: 'filter-price-max', inputType: 'number', min: 0, prefix: '₩' },
    mileage:        { type: 'range', idMin: 'filter-mileage-min', idMax: 'filter-mileage-max', inputType: 'number', min: 0 },
    engine_volume:  { type: 'range', idMin: 'filter-engine-min', idMax: 'filter-engine-max', inputType: 'number', min: 0, step: '0.1' },
    body_type:      { type: 'chips', stateKey: 'bodyTypes', optionsKey: 'bodyTypeOptions', fallbackKey: 'bodyTypes' },
    transmission:   { type: 'chips', stateKey: 'transmissions', optionsKey: 'transmissionOptions', fallbackKey: 'transmissions' },
    fuel:           { type: 'chips', stateKey: 'fuelTypes', optionsKey: 'fuelTypeOptions', fallbackKey: 'fuelTypes' },
    drive_type:     { type: 'chips', stateKey: 'driveTypes', optionsKey: 'driveTypeOptions', fallbackKey: 'driveTypes' },
    color:          { type: 'chips', stateKey: 'colors', optionsKey: 'colorOptions', fallbackKey: 'colors' },
    has_accident:   { type: 'bool_chips', stateKey: 'hasAccident', options: [{value:'',label:'Любое'},{value:'false',label:'✅ Без ДТП'},{value:'true',label:'⚠️ С ДТП'}] },
    flood_history:  { type: 'bool_chips', stateKey: 'floodHistory', options: [{value:'',label:'Любое'},{value:'false',label:'✅ Без затоплений'},{value:'true',label:'🌊 С затоплением'}] },
    insurance_count:{ type: 'range', idMin: 'filter-insurance-min', idMax: 'filter-insurance-max', inputType: 'number', min: 0 },
    owners_count:   { type: 'range', idMin: 'filter-owners-min', idMax: 'filter-owners-max', inputType: 'number', min: 0, max: 20 },
    listed_at:      { type: 'range', idMin: 'filter-listed-after', idMax: 'filter-listed-before', inputType: 'date', step: '1' },
    first_reg_date: { type: 'range', idMin: 'filter-first-reg-after', idMax: 'filter-first-reg-before', inputType: 'date', step: '1' },
    lien_status:    { type: 'chips', stateKey: 'lienStatuses', optionsKey: null, fallbackKey: null, staticOptions: [{value:'clean',label:'Чистый'}] },
    seizure_status: { type: 'chips', stateKey: 'seizureStatuses', optionsKey: null, fallbackKey: null, staticOptions: [{value:'clean',label:'Без ареста'}] },
    options:        { type: 'options_select', id: 'filter-options' },
  };

  function getEnabledFields() {
    const fields = filtersData?.filterFields ?? [];
    return fields.filter(f => f.enabled);
  }

  async function init() {
    try {
      filtersData = await API.getFilters('ru');
      Taxonomy.ingestFilters(filtersData ?? {});
      const sourceKeys = (filtersData?.sources ?? []).map(s => s.key).filter(Boolean);
      if (sourceKeys.length) state.sources = sourceKeys;
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
      rendered.add(name);

      if (name === 'make') { rendered.add('model'); rendered.add('trim'); }

      const section = buildFilterSection(name, ui, field.label);
      if (section) container.appendChild(section);
    }

    // Always add sources at top if not from filterFields
    if (!rendered.has('source')) {
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
    labelSpan.textContent = apiLabel || name;
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
          <div class="filter-select-wrap">
            <select class="filter-select" id="filter-model"><option value="">Любая модель</option></select>
          </div>
          <div class="filter-select-wrap" id="filter-trim-wrap" style="display:none">
            <select class="filter-select" id="filter-trim"><option value="">Любая комплектация</option></select>
          </div>`;
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
    });
  }

  function populateFilters() {
    renderSourceChips();
    renderMakeSelect();
    renderModelSelect();
    renderGenerationSelect();
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
  }

  function renderChipGroup(containerId, items, stateKey) {
    const container = document.getElementById(containerId);
    if (!container) return;

    const options = Taxonomy.normalizeOptionItems(items);
    if (!Array.isArray(options) || options.length === 0) {
      container.innerHTML = '<span class="filter-empty">нет данных</span>';
      return;
    }

    container.innerHTML = options.map(o => `
      <button class="filter-chip${(state[stateKey]||[]).includes(o.value) ? ' selected' : ''}"
              data-value="${o.value}">${o.label}</button>
    `).join('');
    container.querySelectorAll('.filter-chip').forEach(btn => {
      btn.addEventListener('click', () => {
        const val = btn.dataset.value;
        if (!state[stateKey]) state[stateKey] = [];
        if (state[stateKey].includes(val)) {
          state[stateKey] = state[stateKey].filter(x => x !== val);
        } else {
          state[stateKey].push(val);
        }
        btn.classList.toggle('selected', state[stateKey].includes(val));
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
        state[stateKey] = v === '' ? null : v === 'true';
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
    const sources = filtersData?.sources ?? [
      {key:'encar',name:'Encar'},{key:'kbcha',name:'KBChacha'},
    ];
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

  function _destroySelectInstances() {
    for (const [key, inst] of [
      ['optionsSelectInstance',   optionsSelectInstance],
      ['makeSelectInstance',      makeSelectInstance],
      ['modelSelectInstance',     modelSelectInstance],
      ['trimSelectInstance',      trimSelectInstance],
      ['generationSelectInstance',generationSelectInstance],
    ]) {
      if (inst) { try { inst.destroy(); } catch (_) {} }
    }
    optionsSelectInstance    = null;
    makeSelectInstance       = null;
    modelSelectInstance      = null;
    trimSelectInstance       = null;
    generationSelectInstance = null;
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
    const makes = (Taxonomy.normalizeOptionItems(
      filtersData?.makeOptions ?? Object.keys(filtersData?.makes ?? {})
    )).map(o => ({ value: o.value, text: o.label }));

    makeSelectInstance = _tsBase(el, {
      maxItems: 1,
      options:  [{ value: '', text: 'Любая марка' }, ...makes],
      items:    state.make ? [state.make] : [''],
      onChange(val) {
        const v = val || '';
        if (state.make === v) return;
        state.make = v; state.model = ''; state.trim = '';
        renderModelSelect();
        _scheduleContextUpdate(); _scheduleCountUpdate();
      },
    });
  }

  function renderModelSelect() {
    if (modelSelectInstance) { try { modelSelectInstance.destroy(); } catch(_) {} modelSelectInstance = null; }
    if (trimSelectInstance)  { try { trimSelectInstance.destroy();  } catch(_) {} trimSelectInstance  = null; }

    const el = document.getElementById('filter-model');
    if (!el) return;

    const groupsObj = filtersData?.makes?.[state.make] ?? {};
    const models = [...new Set(Object.values(groupsObj).flat())].sort();

    modelSelectInstance = _tsBase(el, {
      maxItems: 1,
      options:  [{ value: '', text: 'Любая модель' }, ...models.map(m => ({ value: m, text: m }))],
      items:    state.model ? [state.model] : [''],
      onChange(val) {
        const v = val || '';
        if (state.model === v) return;
        state.model = v; state.trim = '';
        renderTrimSelect([]);
        if (state.make || state.model) loadTrims();
        _scheduleContextUpdate(); _scheduleCountUpdate();
      },
    });

    if (state.make || state.model) loadTrims();
  }

  function renderGenerationSelect(extraOptions = []) {
    const el = document.getElementById('filter-generation');
    if (!el || typeof TomSelect === 'undefined') return;

    const opts = [...extraOptions];
    if (state.generation && !opts.find(o => o.value === state.generation)) {
      opts.unshift({ value: state.generation, text: state.generation });
    }

    generationSelectInstance = new TomSelect(el, {
      maxItems:    1,
      create:      true,
      persist:     false,
      maxOptions:  null,
      searchField: ['text'],
      placeholder: 'G30, W213, NQ5, CN7...',
      options:     opts,
      items:       state.generation ? [state.generation] : [],
      onChange(val) { state.generation = val || ''; },
      onItemAdd()  { _scheduleCountUpdate(); },
      onItemRemove() { state.generation = ''; _scheduleCountUpdate(); },
      render: {
        no_results: () => '<div class="ts-no-results" style="display:none"></div>',
      },
    });
    generationSelectInstance.wrapper.classList.add('ts-single');
  }

  async function loadTrims() {
    const make = state.make;
    const model = state.model;
    const reqId = ++trimRequestSeq;
    try {
      const data = await API.getTrims(make, model, 'en');
      if (reqId !== trimRequestSeq) return;
      if (make !== state.make || model !== state.model) return;
      const trimOpts = data?.trimOptions ?? [];
      Taxonomy.ingestTrims(trimOpts);
      renderTrimSelect(trimOpts.length ? trimOpts : (data?.trims ?? []));
    } catch (e) {
      if (reqId !== trimRequestSeq) return;
      renderTrimSelect([]);
    }
  }

  function renderTrimSelect(trims) {
    if (trimSelectInstance) { try { trimSelectInstance.destroy(); } catch(_) {} trimSelectInstance = null; }

    const wrap = document.getElementById('filter-trim-wrap');
    const sel  = document.getElementById('filter-trim');
    if (!wrap || !sel) return;

    const trimOptions = Taxonomy.normalizeOptionItems(trims);
    if (!trimOptions || trimOptions.length === 0) {
      wrap.style.display = 'none';
      state.trim = '';
      return;
    }

    // Reset native select so Tom Select starts clean
    sel.innerHTML = '<option value=""></option>';
    wrap.style.display = '';

    const opts = trimOptions.map(o => ({
      value: o.value,
      text:  Taxonomy.label('trim', o.value) || o.label || o.value,
    }));

    trimSelectInstance = _tsBase(sel, {
      maxItems: 1,
      options:  [{ value: '', text: 'Любая комплектация' }, ...opts],
      items:    state.trim ? [state.trim] : [''],
      onChange(val) { state.trim = val || ''; },
    });
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
    // generation and trim are managed by Tom Select instances — state already up-to-date
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
    console.log('[STATE]', JSON.stringify({ t: state.transmissions, f: state.fuelTypes, d: state.driveTypes, bt: state.bodyTypes, ha: state.hasAccident }));
    return {
      make:             state.make              || undefined,
      model:            state.model             || undefined,
      generation:       state.generation        || undefined,
      yearFrom:         state.yearFrom          ? parseInt(state.yearFrom)          : undefined,
      yearTo:           state.yearTo            ? parseInt(state.yearTo)            : undefined,
      priceMin:         state.priceMin          ? parseInt(state.priceMin)          : undefined,
      priceMax:         state.priceMax          ? parseInt(state.priceMax)          : undefined,
      mileageMin:       state.mileageMin        ? parseInt(state.mileageMin)        : undefined,
      mileageMax:       state.mileageMax        ? parseInt(state.mileageMax)        : undefined,
      engineMin:        state.engineMin         ? parseFloat(state.engineMin)       : undefined,
      engineMax:        state.engineMax         ? parseFloat(state.engineMax)       : undefined,
      bodyTypes:        state.bodyTypes.length      ? state.bodyTypes      : undefined,
      transmissions:    state.transmissions.length  ? state.transmissions  : undefined,
      fuelTypes:        state.fuelTypes.length      ? state.fuelTypes      : undefined,
      driveTypes:       state.driveTypes.length     ? state.driveTypes     : undefined,
      colors:           state.colors.length         ? state.colors         : undefined,
      damageTypes:      state.damageTypes.length      ? state.damageTypes      : undefined,
      titleTypes:       state.titleTypes.length       ? state.titleTypes       : undefined,
      lienStatuses:     state.lienStatuses.length     ? state.lienStatuses     : undefined,
      seizureStatuses:  state.seizureStatuses.length  ? state.seizureStatuses  : undefined,
      trim:             state.trim              || undefined,
      hasAccident:      state.hasAccident !== null ? state.hasAccident     : undefined,
      floodHistory:     state.floodHistory !== null ? state.floodHistory   : undefined,
      ownersCountMin:   state.ownersCountMin    ? parseInt(state.ownersCountMin)    : undefined,
      ownersCountMax:   state.ownersCountMax    ? parseInt(state.ownersCountMax)    : undefined,
      insuranceCountMin:state.insuranceCountMin ? parseInt(state.insuranceCountMin) : undefined,
      insuranceCountMax:state.insuranceCountMax ? parseInt(state.insuranceCountMax) : undefined,
      listedAfter:      state.listedAfter       || undefined,
      listedBefore:     state.listedBefore      || undefined,
      firstRegAfter:    state.firstRegAfter     || undefined,
      firstRegBefore:   state.firstRegBefore    || undefined,
      options:          state.options.length   ? state.options  : undefined,
      sources:          state.sources,
      sort:             state.sort,
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
    if (q.model) { state.model = q.model; }
    if (q.trim)  { state.trim  = q.trim;  }
    if (q.generation)       state.generation       = q.generation;
    if (q.bodyTypes)        state.bodyTypes        = q.bodyTypes;
    if (q.transmissions)    state.transmissions    = q.transmissions;
    if (q.fuelTypes)        state.fuelTypes        = q.fuelTypes;
    if (q.driveTypes)       state.driveTypes       = q.driveTypes;
    if (q.colors)           state.colors           = q.colors;
    if (q.damageTypes)      state.damageTypes      = q.damageTypes;
    if (q.titleTypes)       state.titleTypes       = q.titleTypes;
    if (q.lienStatuses)     state.lienStatuses     = q.lienStatuses;
    if (q.seizureStatuses)  state.seizureStatuses  = q.seizureStatuses;
    if (q.sources)          state.sources          = q.sources;
    if (q.sort)             state.sort             = ['date', 'price_asc', 'price_desc'].includes(q.sort) ? q.sort : 'date';
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
    // filter-generation is a Tom Select — value is set via state.generation in renderGenerationSelect()
    setEl('filter-owners-min',    q.ownersCountMin);
    setEl('filter-owners-max',    q.ownersCountMax);
    setEl('filter-insurance-min', q.insuranceCountMin);
    setEl('filter-insurance-max', q.insuranceCountMax);
    setEl('filter-listed-after',  q.listedAfter);
    setEl('filter-listed-before', q.listedBefore);
    setEl('filter-first-reg-after',  q.firstRegAfter);
    setEl('filter-first-reg-before', q.firstRegBefore);
    setEl('sort-select', state.sort);
  }

  function getCardFields() {
    return Array.isArray(filtersData?.cardFields) ? filtersData.cardFields : [];
  }

  function setSort(sort) {
    state.sort = ['date', 'price_asc', 'price_desc'].includes(sort) ? sort : 'date';
  }

  // ── Live count ─────────────────────────────────────────────────────────────

  function _scheduleCountUpdate() {
    _updateResetBtn();
    clearTimeout(_countTimer);
    _countTimer = setTimeout(_updateCount, COUNT_DEBOUNCE_MS);
  }

  function _updateResetBtn() {
    const btn = document.getElementById('filters-reset-btn');
    if (btn) btn.style.display = hasActive() ? '' : 'none';
  }

  function hasActive() {
    return !!(
      state.make || state.model || state.generation || state.trim ||
      state.yearFrom || state.yearTo ||
      state.priceMin || state.priceMax ||
      state.mileageMin || state.mileageMax ||
      state.engineMin || state.engineMax ||
      state.bodyTypes.length || state.transmissions.length ||
      state.fuelTypes.length || state.driveTypes.length ||
      state.colors.length || state.damageTypes.length || state.titleTypes.length ||
      state.lienStatuses.length || state.seizureStatuses.length ||
      state.hasAccident !== null || state.floodHistory !== null ||
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
    TG.haptic('notification', 'success');
  }

  async function _updateCount() {
    const hint  = document.getElementById('search-count-hint');
    const value = document.getElementById('search-count-value');
    if (!hint || !value) return;

    try {
      readFormState();
      const q = getQuery();
      // Don't bother counting when no meaningful filter is set
      const hasFilter = q.make || q.model || q.yearFrom || q.yearTo ||
                        q.priceMin || q.priceMax || q.mileageMin || q.mileageMax ||
                        (q.bodyTypes?.length) || (q.transmissions?.length) ||
                        (q.fuelTypes?.length) || (q.driveTypes?.length) || q.trim;
      if (!hasFilter) {
        hint.style.display = 'none';
        return;
      }

      hint.style.display = 'block';
      value.textContent = '…';

      const data = await API.getCount(q);
      const n = data?.count ?? 0;
      value.textContent = _ruLots(n);
    } catch (_) {
      // Silently ignore count errors — not critical UX
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
    if (!state.make && !state.model) return;
    try {
      const params = {};
      if (state.make)  params.make  = state.make;
      if (state.model) params.model = state.model;
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

      // Update generation Tom Select options
      const genOpts = (ctx.generationOptions ?? []).map(o => ({ value: o.value, text: o.label ?? o.value }));
      if (genOpts.length && generationSelectInstance) {
        generationSelectInstance.clearOptions();
        generationSelectInstance.addOptions(genOpts);
        generationSelectInstance.refreshOptions(false);
      }
    } catch (_) {
      // Context update is best-effort
    }
  }

  return { init, getQuery, getCardFields, setSort, applyQuery, reset, hasActive, notifyChange: _scheduleCountUpdate };
})();
