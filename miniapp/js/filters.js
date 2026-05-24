const Filters = (() => {
  let filtersData = null;

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
    trim:             '',
    hasAccident:      null,
    ownersCountMin:   '',
    ownersCountMax:   '',
    insuranceCountMin:'',
    insuranceCountMax:'',
    vin:              '',
    sort:             'date',
  };

  async function init() {
    try {
      filtersData = await API.getFilters('ru');
      Taxonomy.ingestFilters(filtersData ?? {});
      const sourceKeys = (filtersData?.sources ?? []).map(s => s.key).filter(Boolean);
      if (sourceKeys.length) {
        state.sources = sourceKeys;
      }
      render();
    } catch (e) {
      console.error('Filters load failed', e);
      render();
    }
  }

  function render() {
    renderSourceChips();
    renderMakeSelect();
    renderModelSelect();
    renderChipGroup('bodytype-chips',     filtersData?.bodyTypeOptions     ?? filtersData?.bodyTypes     ?? [], 'bodyTypes');
    renderChipGroup('transmission-chips', filtersData?.transmissionOptions ?? filtersData?.transmissions ?? [], 'transmissions');
    renderChipGroup('fuel-chips',         filtersData?.fuelTypeOptions     ?? filtersData?.fuelTypes     ?? [], 'fuelTypes');
    renderChipGroup('drive-chips',        filtersData?.driveTypeOptions    ?? filtersData?.driveTypes    ?? [], 'driveTypes');
    renderChipGroup('damage-chips',       filtersData?.damageTypes   ?? [], 'damageTypes');
    renderChipGroup('title-chips',        filtersData?.titleTypes    ?? [], 'titleTypes');
    renderChipGroup('color-chips',        filtersData?.colorOptions  ?? filtersData?.colors ?? [], 'colors');
    renderAccidentChips();
  }

  function renderChipGroup(containerId, items, stateKey) {
    const container = document.getElementById(containerId);
    if (!container) return;

    const options = Taxonomy.normalizeOptionItems(items);

    const section = container.closest('.filter-section');
    const divider = section && section.nextElementSibling?.classList?.contains('filter-divider')
      ? section.nextElementSibling
      : null;
    const visible = Array.isArray(options) && options.length > 0;

    if (section) section.style.display = visible ? '' : 'none';
    if (divider) divider.style.display = visible ? '' : 'none';
    if (!visible) {
      state[stateKey] = [];
      container.innerHTML = '';
      return;
    }

    container.innerHTML = options.map(o => `
      <button class="filter-chip${state[stateKey].includes(o.value) ? ' selected' : ''}"
              data-value="${o.value}">${o.label}</button>
    `).join('');
    container.querySelectorAll('.filter-chip').forEach(btn => {
      btn.addEventListener('click', () => {
        const val = btn.dataset.value;
        if (state[stateKey].includes(val)) {
          state[stateKey] = state[stateKey].filter(x => x !== val);
        } else {
          state[stateKey].push(val);
        }
        btn.classList.toggle('selected', state[stateKey].includes(val));
        TG.haptic('selection');
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
      });
    });
  }

  function renderMakeSelect() {
    const sel = document.getElementById('filter-make');
    if (!sel) return;
    const makeOptions = Taxonomy.normalizeOptionItems(filtersData?.makeOptions ?? Object.keys(filtersData?.makes ?? {}));
    sel.innerHTML = '<option value="">Любая марка</option>' +
      makeOptions.map(o => `<option value="${o.value}"${state.make===o.value?' selected':''}>${o.label}</option>`).join('');
    sel.addEventListener('change', () => {
      state.make  = sel.value;
      state.model = '';
      renderModelSelect();
    });
  }

  function renderModelSelect() {
    const sel = document.getElementById('filter-model');
    if (!sel) return;
    const models = (filtersData?.makes?.[state.make]) ?? [];
    sel.innerHTML = '<option value="">Любая модель</option>' +
      models.map(m => `<option value="${m}"${state.model===m?' selected':''}>${m}</option>`).join('');
    sel.addEventListener('change', () => {
      state.model = sel.value;
      state.trim  = '';
      renderTrimSelect([]);
      if (state.make || state.model) loadTrims();
    });
    if (state.make || state.model) loadTrims();
  }

  async function loadTrims() {
    try {
      const data = await API.getTrims(state.make, state.model, 'ru');
      renderTrimSelect(data?.trims ?? []);
    } catch (e) {
      renderTrimSelect([]);
    }
  }

  function renderTrimSelect(trims) {
    const wrap = document.getElementById('filter-trim-wrap');
    const sel  = document.getElementById('filter-trim');
    if (!wrap || !sel) return;
    const trimOptions = Taxonomy.normalizeOptionItems(trims);
    if (!trimOptions || trimOptions.length === 0) {
      wrap.style.display = 'none';
      sel.innerHTML = '<option value="">Любая комплектация</option>';
      state.trim = '';
      return;
    }
    wrap.style.display = '';
    sel.innerHTML = '<option value="">Любая комплектация</option>' +
      trimOptions.map(o => `<option value="${o.value}"${state.trim===o.value?' selected':''}>${o.label}</option>`).join('');
    sel.addEventListener('change', () => { state.trim = sel.value; });
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
    state.generation       = document.getElementById('filter-generation')?.value?.trim() ?? '';
    state.trim             = document.getElementById('filter-trim')?.value?.trim() ?? '';
    state.ownersCountMin   = document.getElementById('filter-owners-min')?.value   ?? '';
    state.ownersCountMax   = document.getElementById('filter-owners-max')?.value   ?? '';
    state.insuranceCountMin= document.getElementById('filter-insurance-min')?.value ?? '';
    state.insuranceCountMax= document.getElementById('filter-insurance-max')?.value ?? '';
    state.vin              = document.getElementById('filter-vin')?.value?.trim()  ?? '';
  }

  function getQuery() {
    readFormState();
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
      damageTypes:      state.damageTypes.length    ? state.damageTypes    : undefined,
      titleTypes:       state.titleTypes.length     ? state.titleTypes     : undefined,
      trim:             state.trim              || undefined,
      hasAccident:      state.hasAccident !== null ? state.hasAccident     : undefined,
      ownersCountMin:   state.ownersCountMin    ? parseInt(state.ownersCountMin)    : undefined,
      ownersCountMax:   state.ownersCountMax    ? parseInt(state.ownersCountMax)    : undefined,
      insuranceCountMin:state.insuranceCountMin ? parseInt(state.insuranceCountMin) : undefined,
      insuranceCountMax:state.insuranceCountMax ? parseInt(state.insuranceCountMax) : undefined,
      vin:              state.vin              || undefined,
      sources:          state.sources,
      sort:             state.sort,
      limit:            40,
    };
  }

  function renderAccidentChips() {
    const container = document.getElementById('accident-chips');
    if (!container) return;
    const options = [
      { value: '',      label: 'Любое' },
      { value: 'false', label: '\u2705 Без ДТП' },
      { value: 'true',  label: '\u26a0\ufe0f С ДТП' },
    ];
    const current = state.hasAccident === null ? '' : String(state.hasAccident);
    container.innerHTML = options.map(o =>
      `<button class="filter-chip${current === o.value ? ' selected' : ''}" data-value="${o.value}">${o.label}</button>`
    ).join('');
    container.querySelectorAll('.filter-chip').forEach(btn => {
      btn.addEventListener('click', () => {
        const v = btn.dataset.value;
        state.hasAccident = v === '' ? null : v === 'true';
        container.querySelectorAll('.filter-chip').forEach(b =>
          b.classList.toggle('selected', b.dataset.value === v));
        TG.haptic('selection');
      });
    });
  }

  function applyQuery(q) {
    if (!q) return;
    const setEl = (id, val) => { if (val !== undefined && val !== null && val !== '') { const el = document.getElementById(id); if (el) el.value = val; } };
    if (q.make)  { state.make  = q.make;  }
    if (q.model) { state.model = q.model; }
    if (q.trim)  { state.trim  = q.trim;  }
    renderMakeSelect();
    renderModelSelect();
    setEl('filter-year-from',     q.yearFrom);
    setEl('filter-year-to',       q.yearTo);
    setEl('filter-price-min',     q.priceMin);
    setEl('filter-price-max',     q.priceMax);
    setEl('filter-mileage-min',   q.mileageMin);
    setEl('filter-mileage-max',   q.mileageMax);
    setEl('filter-engine-min',    q.engineMin);
    setEl('filter-engine-max',    q.engineMax);
    setEl('filter-generation',    q.generation);
    setEl('filter-owners-min',    q.ownersCountMin);
    setEl('filter-owners-max',    q.ownersCountMax);
    setEl('filter-insurance-min', q.insuranceCountMin);
    setEl('filter-insurance-max', q.insuranceCountMax);
    setEl('filter-vin',           q.vin);
    if (q.generation)       state.generation       = q.generation;
    if (q.bodyTypes)        state.bodyTypes        = q.bodyTypes;
    if (q.transmissions)    state.transmissions    = q.transmissions;
    if (q.fuelTypes)        state.fuelTypes        = q.fuelTypes;
    if (q.driveTypes)       state.driveTypes       = q.driveTypes;
    if (q.colors)           state.colors           = q.colors;
    if (q.sources)          state.sources          = q.sources;
    if (q.ownersCountMin !== undefined)    state.ownersCountMin    = String(q.ownersCountMin ?? '');
    if (q.ownersCountMax !== undefined)    state.ownersCountMax    = String(q.ownersCountMax ?? '');
    if (q.insuranceCountMin !== undefined) state.insuranceCountMin = String(q.insuranceCountMin ?? '');
    if (q.insuranceCountMax !== undefined) state.insuranceCountMax = String(q.insuranceCountMax ?? '');
    if (q.hasAccident !== undefined && q.hasAccident !== null) state.hasAccident = q.hasAccident;
    render();
  }

  function getCardFields() {
    return Array.isArray(filtersData?.cardFields) ? filtersData.cardFields : [];
  }

  function setSort(sort) {
    state.sort = ['date', 'price_asc', 'price_desc'].includes(sort) ? sort : 'date';
  }

  return { init, getQuery, getCardFields, setSort, applyQuery };
})();
