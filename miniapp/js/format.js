/** Shared formatting: one wording for a lot in the list, the table, the lot page and the compare table. */
const Fmt = (() => {
  const PLACEHOLDER = '/miniapp/img/placeholder.svg';

  function esc(v) {
    return String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }

  /** 18900000 → "18 900 000" (thin no-break spaces keep the number on one line). */
  function num(n) {
    if (n === null || n === undefined || n === '' || isNaN(Number(n))) return '—';
    return Math.round(Number(n)).toLocaleString('ru-RU').replace(/\s/g, ' ');
  }

  const won = n => (n ? '₩' + num(n) : '—');
  const km = n => (n || n === 0 ? num(n) + ' км' : '—');

  function plural(n, one, few, many) {
    const m10 = n % 10, m100 = n % 100;
    if (m10 === 1 && m100 !== 11) return one;
    if (m10 >= 2 && m10 <= 4 && (m100 < 10 || m100 >= 20)) return few;
    return many;
  }
  const lots = n => `${num(n)} ${plural(Number(n) || 0, 'лот', 'лота', 'лотов')}`;

  /** "2021-03-02" → "03.2021"; "2026-09-10" (day) → "10.09.2026". */
  function month(d) {
    const m = String(d ?? '').match(/^(\d{4})-(\d{2})/);
    return m ? `${m[2]}.${m[1]}` : (d || '—');
  }
  function day(d) {
    const m = String(d ?? '').match(/^(\d{4})-(\d{2})-(\d{2})/);
    return m ? `${m[3]}.${m[2]}.${m[1]}` : (d || '—');
  }
  function shortDay(d) {
    const m = String(d ?? '').match(/^\d{4}-(\d{2})-(\d{2})/);
    return m ? `${m[2]}.${m[1]}` : '—';
  }

  const label = (field, v) => (v ? Taxonomy.label(field, v) : '');
  const lower = s => (s ? s.charAt(0).toLowerCase() + s.slice(1) : s);

  function make(lot) { return lot.makeEn || lot.make || ''; }
  function model(lot) { return lot.modelEn || lot.modelGroupEn || lot.model || ''; }
  /** "2021 Hyundai Sonata (DN8)" */
  function title(lot) {
    const c = lot.catalog; // on the reference catalog: its names, also for lots not translated yet
    if (c?.model) return [lot.year, c.make, c.model, c.generation].filter(Boolean).join(' ');
    return [lot.year, make(lot), model(lot)].filter(Boolean).join(' ');
  }
  /**
   * badge / trim line under the title: "2.0 Premium"; a lot on the reference catalog says it
   * the catalog's way: "530i xDrive · M Sport Pro · Special Edition" (the generation is in the title).
   */
  function trim(lot) {
    const c = lot.catalog;
    if (c && (c.modification || c.complectation)) {
      return [c.modification, c.complectation, ...(c.editions ?? [])].filter(Boolean).join(' · ');
    }
    const t = lot.trim ? Taxonomy.label('trim', lot.trim) : '';
    return [lot.badgeEn, (t && t !== lot.trim ? t : (lot.trimEn || lot.trim))].filter(Boolean).join(' · ');
  }
  function engine(lot) {
    const v = Number(lot.engineVolume);
    return v >= 0.5 ? `${v.toFixed(1).replace(/\.0$/, '.0')} л` : '';
  }
  /** "Сеул" from "서울 강남구 …" or "Seoul …": the first word is enough in a list */
  function city(lot) {
    const loc = String(lot.location ?? '').trim();
    return loc && loc !== 'Korea' ? loc.split(/\s+/)[0] : '';
  }
  function photos(lot) {
    const list = Array.isArray(lot.photos) && lot.photos.length ? lot.photos : (lot.imageUrl ? [lot.imageUrl] : []);
    return list;
  }
  function cover(lot) { return photos(lot)[0] || lot.imageUrl || PLACEHOLDER; }
  function photoCount(lot) { return lot.photoCount ?? photos(lot).length; }

  /**
   * What a picker reads first: accident, owners, insurance cases, flood/total loss.
   * @return {{level: 'ok'|'warn'|'bad'|'none', short: string, flags: {text: string, cls: string}[]}}
   */
  function history(lot) {
    const flags = [];
    let level = 'none';
    const raise = l => { const order = { none: 0, ok: 1, warn: 2, bad: 3 }; if (order[l] > order[level]) level = l; };
    if (lot.hasAccident === true) { flags.push({ text: 'ДТП', cls: 'bad' }); raise('bad'); }
    else if (lot.hasAccident === false) { flags.push({ text: 'Без ДТП', cls: 'ok' }); raise('ok'); }
    if (lot.floodHistory) { flags.push({ text: 'Затопление', cls: 'bad' }); raise('bad'); }
    if (lot.totalLossHistory) { flags.push({ text: 'Тотал', cls: 'bad' }); raise('bad'); }
    // ownersCount is Encar's ownerChangeCnt: changes of owner, 0 = still the first owner
    if (lot.ownersCount != null) {
      flags.push({ text: owners(lot.ownersCount), cls: lot.ownersCount >= 3 ? 'warn' : '' });
      if (lot.ownersCount >= 3) raise('warn');
    }
    if (lot.insuranceCount) {
      flags.push({ text: `${lot.insuranceCount} страх.`, cls: lot.insuranceCount >= 2 ? 'warn' : '' });
      if (lot.insuranceCount >= 2) raise('warn');
    }
    if (lot.lienStatus && lot.lienStatus !== 'clean') { flags.push({ text: 'Залог', cls: 'warn' }); raise('warn'); }
    if (lot.seizureStatus && lot.seizureStatus !== 'clean') { flags.push({ text: 'Арест', cls: 'bad' }); raise('bad'); }
    const short = lot.hasAccident === true ? 'ДТП'
      : lot.floodHistory ? 'затопление'
      : lot.totalLossHistory ? 'тотал'
      : lot.insuranceCount ? `${lot.insuranceCount} ${plural(lot.insuranceCount, 'страховой', 'страховых', 'страховых')}`
      : lot.hasAccident === false ? 'чистая' : '—';
    return { level, short, flags };
  }

  /** Encar counts changes of owner: 0 → "1-й владелец", 2 → "2 смены владельца". */
  function owners(changes) {
    const n = Number(changes);
    return n === 0 ? '1-й владелец' : `${n} ${plural(n, 'смена', 'смены', 'смен')} владельца`;
  }

  /** "45 210 км · бензин · Сеул" */
  function sub(lot) {
    return [km(lot.mileage), lower(label('fuel', lot.fuel)), city(lot)].filter(s => s && s !== '—').join(' · ');
  }
  /** "AT · передний · 2.0 л" */
  function drive(lot) {
    return [label('transmission', lot.transmission), lower(label('drive_type', lot.driveType)), engine(lot)].filter(Boolean).join(' · ');
  }

  const icon = {
    heart: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1-1.1a5.5 5.5 0 0 0-7.8 7.8L12 21.2l8.8-8.8a5.5 5.5 0 0 0 0-7.8z"/></svg>',
    compare: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 3v18M16 3v18M3 8h5M16 16h5"/></svg>',
    share: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 12v7a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-7M16 6l-4-4-4 4M12 2v13"/></svg>',
    close: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6L6 18M6 6l12 12"/></svg>',
    back: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 18l-6-6 6-6"/></svg>',
    next: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 18l6-6-6-6"/></svg>',
    out: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 3h7v7M10 14L21 3M21 14v5a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5"/></svg>',
    search: '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>',
    lock: '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>',
  };

  return { owners, PLACEHOLDER, esc, num, won, km, plural, lots, month, day, shortDay, label, lower, make, model, title, trim, engine, city, photos, cover, photoCount, history, sub, drive, icon };
})();
