const Taxonomy = (() => {
  const labels = {
    body_type: {},
    transmission: {},
    fuel: {},
    drive_type: {},
    trim: {},
    color: {},
    seat_color: {},
  };

  function normalizeOptionItems(items = []) {
    return (items ?? []).map((item) => {
      if (item && typeof item === 'object' && 'value' in item) {
        const value = String(item.value ?? '').trim();
        const label = String(item.label ?? value).trim();
        return { value, label: label || value };
      }
      const value = String(item ?? '').trim();
      return { value, label: value };
    }).filter(o => o.value !== '');
  }

  function indexField(field, items) {
    const map = labels[field];
    if (!map) return;

    for (const o of normalizeOptionItems(items)) {
      map[o.value] = o.label;
    }
  }

  function ingestFilters(data) {
    indexField('body_type',    data?.bodyTypeOptions    ?? data?.bodyTypes    ?? []);
    indexField('transmission', data?.transmissionOptions ?? data?.transmissions ?? []);
    indexField('fuel',         data?.fuelTypeOptions    ?? data?.fuelTypes     ?? []);
    indexField('drive_type',   data?.driveTypeOptions   ?? data?.driveTypes    ?? []);
    indexField('trim',         data?.trimOptions        ?? []);
    indexField('color',        data?.colorOptions       ?? data?.colors        ?? []);
  }

  function ingestTrims(trimOptions) {
    indexField('trim', trimOptions ?? []);
  }

  // Korean seat color descriptions → Russian (closed set, display-only)
  const SEAT_COLOR_RU = {
    '검정색 계열': 'Чёрный',
    '갈색 계열':   'Коричневый',
    '베이지색 계열':'Бежевый',
    '흰색 계열':   'Белый',
    '은색 계열':   'Серебристый',
    '회색 계열':   'Серый',
    '노란색 계열': 'Жёлтый',
    '빨간색 계열': 'Красный',
  };

  function label(field, value) {
    const raw = String(value ?? '').trim();
    if (!raw) return '';
    if (field === 'seat_color') return SEAT_COLOR_RU[raw] ?? raw;
    return labels[field]?.[raw] ?? raw;
  }

  function options(field, items) {
    const list = normalizeOptionItems(items);
    if (field && labels[field]) {
      return list.map(o => ({ value: o.value, label: label(field, o.value) }));
    }
    return list;
  }

  return {
    ingestFilters,
    ingestTrims,
    label,
    options,
    normalizeOptionItems,
  };
})();
