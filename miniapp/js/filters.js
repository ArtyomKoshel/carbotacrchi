const Filters = (() => {
  let filtersData = null;

  const state = {
    sources:       ['copart', 'iai', 'manheim', 'encar', 'kbcha'],
    make:          '',
    model:         '',
    yearFrom:      '',
    yearTo:        '',
    priceMin:      '',
    priceMax:      '',
    mileageMin:    '',
    mileageMax:    '',
    engineMin:     '',
    engineMax:     '',
    bodyTypes:     [],
    transmissions: [],
    fuelTypes:     [],
    driveTypes:    [],
    damageTypes:   [],
    titleTypes:    [],
    vin:           '',
    sort:          'date',
  };

  async function init() {
    try {
      filtersData = await API.getFilters();
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
    renderChipGroup('bodytype-chips',     filtersData?.bodyTypes     ?? [], 'bodyTypes');
    renderChipGroup('transmission-chips', filtersData?.transmissions ?? [], 'transmissions');
    renderChipGroup('fuel-chips',         filtersData?.fuelTypes     ?? [], 'fuelTypes');
    renderChipGroup('drive-chips',        filtersData?.driveTypes    ?? [], 'driveTypes');
    renderChipGroup('damage-chips',       filtersData?.damageTypes   ?? [], 'damageTypes');
    renderChipGroup('title-chips',        filtersData?.titleTypes    ?? [], 'titleTypes');
  }

  function renderChipGroup(containerId, items, stateKey) {
    const container = document.getElementById(containerId);
    if (!container) return;
    container.innerHTML = items.map(item => `
      <button class="filter-chip${state[stateKey].includes(item) ? ' selected' : ''}"
              data-value="${item}">${item}</button>
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
      {key:'copart',name:'Copart'},{key:'iai',name:'IAAI'},{key:'manheim',name:'Manheim'},
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
    const makes = filtersData ? Object.keys(filtersData.makes) : [];
    sel.innerHTML = '<option value="">Любая марка</option>' +
      makes.map(m => `<option value="${m}"${state.make===m?' selected':''}>${m}</option>`).join('');
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
    sel.addEventListener('change', () => { state.model = sel.value; });
  }

  function readFormState() {
    state.yearFrom   = document.getElementById('filter-year-from')?.value   ?? '';
    state.yearTo     = document.getElementById('filter-year-to')?.value     ?? '';
    state.priceMin   = document.getElementById('filter-price-min')?.value   ?? '';
    state.priceMax   = document.getElementById('filter-price-max')?.value   ?? '';
    state.mileageMin = document.getElementById('filter-mileage-min')?.value ?? '';
    state.mileageMax = document.getElementById('filter-mileage-max')?.value ?? '';
    state.engineMin  = document.getElementById('filter-engine-min')?.value  ?? '';
    state.engineMax  = document.getElementById('filter-engine-max')?.value  ?? '';
    state.vin        = document.getElementById('filter-vin')?.value?.trim() ?? '';
  }

  function getQuery() {
    readFormState();
    return {
      make:          state.make          || undefined,
      model:         state.model         || undefined,
      yearFrom:      state.yearFrom      ? parseInt(state.yearFrom)      : undefined,
      yearTo:        state.yearTo        ? parseInt(state.yearTo)        : undefined,
      priceMin:      state.priceMin      ? parseInt(state.priceMin)      : undefined,
      priceMax:      state.priceMax      ? parseInt(state.priceMax)      : undefined,
      mileageMin:    state.mileageMin    ? parseInt(state.mileageMin)    : undefined,
      mileageMax:    state.mileageMax    ? parseInt(state.mileageMax)    : undefined,
      engineMin:     state.engineMin     ? parseFloat(state.engineMin)   : undefined,
      engineMax:     state.engineMax     ? parseFloat(state.engineMax)   : undefined,
      bodyTypes:     state.bodyTypes.length      ? state.bodyTypes      : undefined,
      transmissions: state.transmissions.length  ? state.transmissions  : undefined,
      fuelTypes:     state.fuelTypes.length      ? state.fuelTypes      : undefined,
      driveTypes:    state.driveTypes.length     ? state.driveTypes     : undefined,
      damageTypes:   state.damageTypes.length    ? state.damageTypes    : undefined,
      titleTypes:    state.titleTypes.length     ? state.titleTypes     : undefined,
      vin:           state.vin           || undefined,
      sources:       state.sources,
      sort:          state.sort,
      limit:         40,
    };
  }

  return { init, getQuery };
})();
