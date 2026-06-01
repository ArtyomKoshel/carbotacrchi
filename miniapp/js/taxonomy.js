const Taxonomy = (() => {
  const labels = {
    body_type: {},
    transmission: {},
    fuel: {},
    drive_type: {},
    trim: {},
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
    indexField('body_type', data?.bodyTypeOptions ?? data?.bodyTypes ?? []);
    indexField('transmission', data?.transmissionOptions ?? data?.transmissions ?? []);
    indexField('fuel', data?.fuelTypeOptions ?? data?.fuelTypes ?? []);
    indexField('drive_type', data?.driveTypeOptions ?? data?.driveTypes ?? []);
    indexField('trim', data?.trimOptions ?? []);
  }

  function ingestTrims(trimOptions) {
    indexField('trim', trimOptions ?? []);
  }

  function label(field, value) {
    const raw = String(value ?? '').trim();
    if (!raw) return '';
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
