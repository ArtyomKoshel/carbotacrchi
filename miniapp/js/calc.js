/* Landed-cost calculator: the sales team's formula, as their standalone calculator counts it.
   Car price ₩ + dealer parking fee, minus the VAT refund → $ at Bithumb USDT/KRW minus the
   adjustment; + delivery (Korea expenses included) = the transfer; + transfer fee (the part
   in old banknotes pays the extra percent); + customs = the total. Our service is in BYN, on
   its own line. Customs in Belarus is counted from the car (engine type, volume, age) by the
   rates customs.gov.by/calc uses, the duty in € and the fees in BYN turned into $ at the NBRB
   rates. Defaults, rates and duty tables come from /api/calculator (set in /admin/calculator);
   what a manager changes for one car is kept for that car in this browser. */
const Calc = (() => {
  const LOTS_KEY = 'carbot_calc_lots';     // { lotId | 'manual': { field: value } }, last 60
  const PREFS_KEY = 'carbot_calc_prefs';   // { template, share }
  const KEEP = 60;
  const FIELDS = [
    // key, label, unit
    ['price', 'Цена авто', 'млн ₩'],
    ['vat', 'Возврат НДС', '%'],
    ['delivery', 'Доставка всего', '$'], // to Minsk, Korea expenses included
    ['dealerFee', 'Стояночные дилера', 'млн ₩'],
    ['fee', 'Комиссия за перевод', '%'],
    ['service', 'Наши услуги', 'BYN'],
  ];
  // the rates on top, each can be set by hand for one calculation: Bithumb ₩ per $ (before
  // the adjustment), NBRB BYN per $ and per €
  const RATES = [['rate', 'Bithumb', '₩ за $', '₩'], ['usdByn', 'НБРБ', 'BYN за $', '$'], ['eurByn', 'НБРБ', 'BYN за €', '€']];
  const ENGINES = { ice: 'Бензин / дизель', hybrid: 'Параллельный гибрид', ev: 'Электро' };
  // as customs.gov.by: up to 3 years inclusive; over 3 up to 5 inclusive; over 5
  const AGES = { lt3: 'до 3 лет', mid: '3–5 лет', old: 'старше 5 лет' };
  // everything a manager can change, kept per car when it differs from the default
  const KEPT = [...FIELDS.map(f => f[0]), ...RATES.map(r => r[0]), 'mixed', 'newNotes', 'engine', 'cc', 'age', 'benefit', 'customs'];

  let base = null;
  let pending = null;

  async function load() {
    if (base && Date.now() - base.t < 5 * 60e3) return base;
    pending ??= API.getCalculator().then(d => (base = { ...d, t: Date.now() })).finally(() => { pending = null; });
    return pending;
  }

  const read = key => { try { return JSON.parse(localStorage.getItem(key) || '{}') || {}; } catch (_) { return {}; } };
  const write = (key, v) => { try { localStorage.setItem(key, JSON.stringify(v)); } catch (_) {} };
  const prefs = () => {
    const p = { share: false, ...read(PREFS_KEY) };
    p.template = TEMPLATES[p.template] ? p.template : (p.simple ? 'simple' : 'full'); // before templates: { simple }
    return p;
  };
  const setPref = (k, v) => write(PREFS_KEY, { ...prefs(), [k]: v });

  function overrides(id) { return read(LOTS_KEY)[id] || {}; }
  function saveOverrides(id, o) {
    const all = read(LOTS_KEY);
    delete all[id];
    if (Object.keys(o).length) all[id] = { ...o, _t: Date.now() };
    const ids = Object.keys(all).sort((a, b) => (all[b]._t || 0) - (all[a]._t || 0));
    ids.slice(KEEP).forEach(k => delete all[k]);
    write(LOTS_KEY, all);
  }

  const num = v => {
    if (typeof v === 'number') return v;
    const n = Number(String(v ?? '').replace(/\s/g, '').replace(',', '.'));
    return String(v ?? '').trim() === '' ? NaN : n;
  };

  // ── the car → customs inputs ───────────────────────────────────────────
  /** Electric and hydrogen cars pay as electric; Encar's hybrids are parallel ones. */
  const engineOf = fuel => (fuel === 'electric' || fuel === 'hydrogen') ? 'ev'
    : (fuel === 'hybrid' || fuel === 'plugin_hybrid') ? 'hybrid' : 'ice';

  /**
   * The age bracket. Customs counts from the production date, Encar gives none: the first
   * registration stands in for it (else the model year), and the manager can change it.
   */
  function ageOf(car) {
    const src = car?.firstRegDate || (car?.year ? `${car.year}-07-01` : null);
    const d = src ? new Date(String(src).slice(0, 10)) : null;
    if (!d || Number.isNaN(+d)) return { age: 'mid', from: null };
    const plus = y => { const x = new Date(d); x.setFullYear(x.getFullYear() + y); return x; };
    const now = new Date();
    return { age: now <= plus(3) ? 'lt3' : now <= plus(5) ? 'mid' : 'old', from: car.firstRegDate ? 'reg' : 'year' };
  }

  /** Every input of one calculation: team defaults, the car, then what the manager changed. */
  function inputs(id, car) {
    const d = base?.defaults || {};
    const v = {
      price: car?.price > 0 ? +(car.price / 1e6).toFixed(3) : '',
      vat: d.vat_refund_pct, delivery: d.delivery_usd, dealerFee: d.dealer_fee_millions,
      fee: d.transfer_fee_pct, service: d.service_byn, rate: '', usdByn: '', eurByn: '',
      mixed: false, newNotes: 0,
      engine: car ? engineOf(car.fuel) : 'ice',
      cc: car?.engineVolume > 0 ? Math.round(car.engineVolume * 1000) : '',
      age: car ? ageOf(car).age : 'mid',
      benefit: false,
      customs: '', // $ by hand, over what is counted
      ...overrides(id),
    };
    delete v._t;
    return v;
  }

  /**
   * Customs in Belarus for the car, in $: { total, dutyUsd, vatUsd, feesUsd, … } or { error }.
   * priceUsd: the listing price in $ — the customs value for a duty counted from the value is
   * that minus valuation_discount_usd, and the car then goes through the valuation (its fee).
   */
  function customs(v, priceUsd) {
    const d = base?.defaults || {};
    const c = base?.customs;
    // NBRB rates, or the ones the manager set for this calculation
    const nb = { ...(base?.nbrb || {}) };
    if (num(v.usdByn) > 0) nb.usd = num(v.usdByn);
    if (num(v.eurByn) > 0) nb.eur = num(v.eurByn);
    if (!c) return { error: 'Нет ставок таможни' };
    if (!(nb.usd > 0 && nb.eur > 0)) return { error: 'Нет курсов НБРБ — введите их вверху или таможню вручную' };
    const usdPerEur = nb.eur / nb.usd;
    const byValue = v.engine === 'ev' || v.age === 'lt3';
    const valueUsd = byValue ? Math.max(0, priceUsd - (num(d.valuation_discount_usd) || 0)) : null;
    const valueEur = byValue ? valueUsd / usdPerEur : null;
    const cc = num(v.cc);
    let duty = 0;
    let vat = 0;
    let basis = '';
    if (v.engine === 'ev') {
      duty = valueEur * c.electric_pct / 100;
      basis = `${c.electric_pct} % от таможенной стоимости`;
      if (v.age === 'old') vat = valueEur * c.electric_old_vat_pct / 100; // on the value, not the duty (Arena Drive)
    } else {
      if (!(cc > 0)) return { error: 'Укажите объём двигателя, см³' };
      if (v.age === 'lt3') {
        const [, pct, min] = c.new.find(([lim]) => lim === null || valueEur <= lim);
        const fromValue = valueEur * pct / 100;
        const fromCc = cc * min;
        duty = Math.max(fromValue, fromCc);
        basis = fromValue >= fromCc ? `${pct} % от таможенной стоимости` : `не менее ${fmt(min, 1)} € за см³`;
      } else {
        const [, perCc] = c[v.age].find(([lim]) => lim === null || cc <= lim);
        duty = cc * perCc;
        basis = `${fmt(perCc, 1)} € за см³ × ${fmt(cc)} см³`;
      }
    }
    const dutyFull = duty;
    if (v.benefit) duty *= 1 - (num(d.benefit_pct) || 0) / 100; // the benefit is off the duty only
    const fees = [
      // the recycling fee is lower for a car up to 3 years old
      ['Таможенный сбор', num(d.customs_fee_byn) || 0],
      ['Утилизационный сбор', num(v.age === 'lt3' ? (d.recycling_fee_new_byn ?? d.recycling_fee_byn) : d.recycling_fee_byn) || 0],
      ['СВХ', num(d.svh_byn) || 0], ['Декларант', num(d.declarant_byn) || 0],
      ...(byValue ? [['Оценка', num(d.valuation_byn) || 0]] : []),
    ];
    const feesByn = fees.reduce((s, [, x]) => s + x, 0);
    const dutyUsd = duty * usdPerEur;
    const vatUsd = vat * usdPerEur;
    const feesUsd = feesByn / nb.usd;
    return {
      total: dutyUsd + vatUsd + feesUsd, dutyEur: duty, dutyFullEur: dutyFull, dutyUsd, vatEur: vat, vatUsd,
      fees, feesByn, feesUsd, valueUsd, valueEur, basis, byValue, nbrb: nb,
    };
  }

  /** The numbers, or { error } when a field is wrong; nothing guessed. */
  function compute(v) {
    const d = base?.defaults || {};
    const market = base?.rate?.rate;
    const manual = num(v.rate);
    const n = k => num(v[k]);
    const rate = (manual > 0 ? manual : market) - (num(d.rate_adjustment_krw) || 0);
    const fail = (error, cx = null) => ({ error, rate: rate > 0 ? rate : null, manual: manual > 0, cx });
    if (!(n('price') > 0)) return fail('Введите цену авто в миллионах вон');
    for (const k of ['vat', 'delivery', 'dealerFee', 'fee', 'service']) {
      if (!Number.isFinite(n(k)) || n(k) < 0) return fail('Проверьте поле «' + FIELDS.find(f => f[0] === k)[1] + '»');
    }
    if (n('vat') >= 100) return fail('Возврат НДС должен быть меньше 100 %');
    const korea = num(d.korea_expenses_usd) || 0;
    if (n('delivery') < korea) return fail(`Доставка не может быть меньше расходов в Корее (${usd(korea)})`);
    if (!(rate > 0)) return fail('Нет курса: Bithumb не ответил — введите курс вручную');

    const cx = customs(v, n('price') * 1e6 / rate);
    const byHand = n('customs') > 0;
    if (cx.error && !byHand) return fail(cx.error, cx);

    const carWon = Math.round((n('price') + n('dealerFee')) * 1e6 * (1 - n('vat') / 100));
    const carUsd = carWon / rate;
    const transfer = carUsd + n('delivery');
    let fee = transfer * n('fee') / 100;
    if (v.mixed) {
      const newer = num(v.newNotes);
      if (!Number.isFinite(newer) || newer < 0) return fail('Проверьте сумму в новых купюрах', cx);
      if (newer > transfer) return fail(`Сумма в новых купюрах больше перевода (${usd(transfer)})`, cx);
      fee = newer * n('fee') / 100 + (transfer - newer) * (n('fee') + (num(d.old_banknotes_extra_pct) || 0)) / 100;
    }
    const beforeCustoms = transfer + fee;
    const customsUsd = byHand ? n('customs') : cx.total;
    return {
      rate, manual: manual > 0, carWon, carUsd, transfer, fee, beforeCustoms, cx, customsByHand: byHand,
      total: beforeCustoms + customsUsd, customs: customsUsd, service: n('service'),
      korea, toMinsk: Math.max(0, n('delivery') - korea),
    };
  }

  const fmt = (v, digits = 0) => Number.isFinite(v)
    ? new Intl.NumberFormat('ru-RU', { minimumFractionDigits: digits, maximumFractionDigits: digits }).format(v) : '—';
  const usd = v => fmt(Math.round(v)) + ' $';
  const byn = v => fmt(v, Number.isInteger(v) ? 0 : 2) + ' BYN';
  const eur = v => fmt(Math.round(v)) + ' €';

  // The sales team's message templates: «Полный» and «Простой» as in their calculator,
  // «Короткий» as Stepan sends it (no VAT note, no service, no «торг», a blank line before the total)
  const TEMPLATES = { full: 'Полный', simple: 'Простой', short: 'Короткий' };

  /** The text for the client, line by line; '' is a blank line. */
  function message(r, template) {
    const company = base?.company || '';
    const lines = ['🧾 Расчёт стоимости:'];
    if (template === 'simple') lines.push(`Автомобиль и все расходы по доставке до Минска — ${usd(r.transfer)}`);
    else lines.push(
      template === 'short' ? `Автомобиль — ${usd(r.carUsd)}` : `Автомобиль с учётом возврата НДС — ${usd(r.carUsd)}`,
      `Расходы в Корее — ${usd(r.korea)}`,
      `Доставка до Минска, оформление и страховка — ${usd(r.toMinsk)}`,
    );
    if (r.fee > 0) lines.push(`Комиссия за перевод — ${usd(r.fee)}`);
    lines.push(`Таможня, СВХ и декларант — ${usd(r.customs)}`);
    if (template === 'short') return [...lines, '', `Итого: ${usd(r.total)}`];
    if (r.service > 0) lines.push(`Услуги ${company} — ${byn(r.service)}`.replace('  ', ' '));
    lines.push(`Итого: ${usd(r.total)}${r.service > 0 ? ' + ' + byn(r.service) : ''}`, 'Возможен торг с дилером');
    return lines;
  }

  /** Lines to add to the lot sent to the client, when the manager asked for it; else null. */
  function shareLines(lot) {
    if (!base || !prefs().share) return null;
    const r = compute(inputs(lot.id, lot));
    return r.error ? null : message(r, prefs().template);
  }

  /** The lot card's line: «26 833 $ + 800 BYN» with this car's corrections, or null. */
  async function summary(lot) {
    await load();
    const r = compute(inputs(lot.id, lot));
    return r.error ? null : usd(r.total) + (r.service > 0 ? ' + ' + byn(r.service) : '');
  }

  // ── form ────────────────────────────────────────────────────────────────
  const esc = s => Fmt.esc(String(s ?? ''));

  /** «Пошлина 22 320 € (3,6 € за см³ × 6 200 см³) = 24 830 $» and the rest of the customs sum. */
  function customsLines(r, v) {
    const cx = r.cx;
    if (!cx || cx.error) return cx?.error ? `<p class="calc__error">${esc(cx.error)}</p>` : '';
    const rows = [];
    if (cx.byValue) rows.push(['Таможенная стоимость', `${usd(cx.valueUsd)} = ${eur(cx.valueEur)}`,
      `цена авто минус ${usd(num(base.defaults?.valuation_discount_usd) || 0)} — до оценки на таможне`]);
    rows.push(['Пошлина', `${eur(cx.dutyEur)} = ${usd(cx.dutyUsd)}`,
      cx.basis + (v.benefit ? `; льгота −${fmt(num(base.defaults?.benefit_pct) || 0)} % (без льготы ${eur(cx.dutyFullEur)})` : '')]);
    if (cx.vatEur > 0) rows.push(['НДС', `${eur(cx.vatEur)} = ${usd(cx.vatUsd)}`, `${base.customs.electric_old_vat_pct} % от таможенной стоимости`]);
    rows.push(['Сборы', `${byn(cx.feesByn)} = ${usd(cx.feesUsd)}`, cx.fees.map(([k, x]) => `${k} ${fmt(x)}`).join(' · ')]);
    rows.push(['Таможня всего', `<b>${usd(cx.total)}</b>`, r.customsByHand ? 'в расчёте — сумма вручную' : '']);
    return `<dl class="calc__cx">${rows.map(([k, val, note]) => `<div><dt>${k}</dt><dd>${val}${note ? `<small>${esc(note)}</small>` : ''}</dd></div>`).join('')}</dl>
`;
  }

  /**
   * The calculator in `el`. ctx: { id: lot id or 'manual', car: the lot (price, fuel,
   * engineVolume, firstRegDate, year) or null, lot: true for one car's page («В сообщение
   * клиенту»), onShare: sends the car to the client (a «Клиенту» button) }.
   */
  async function mount(el, ctx) {
    el.innerHTML = `<div class="calc__state">Загружаю курс…</div>`;
    try { await load(); } catch (e) {
      el.innerHTML = `<div class="calc__state">Калькулятор не загрузился: ${esc(e.message)}
        <button class="link-btn" type="button" data-retry>Повторить</button></div>`;
      el.querySelector('[data-retry]').addEventListener('click', () => mount(el, ctx));
      return;
    }
    if (!el.isConnected) return;
    const v = inputs(ctx.id, ctx.car);
    const p = prefs();
    const r0 = base.rate || {};
    const adj = num(base.defaults?.rate_adjustment_krw) || 0;
    const ageFrom = ctx.car ? ageOf(ctx.car) : { from: null };
    const field = ([k, label, unit], extra = '') => `
      <label class="calc__field"${extra}>
        <span>${esc(label)}</span>
        <span class="calc__inp"><input class="filter-input" data-k="${k}" inputmode="decimal" autocomplete="off"
          value="${esc(v[k])}" placeholder="${k === 'rate' && r0.rate ? esc(fmt(r0.rate)) : k === 'customs' ? 'считается сама' : ''}"><i>${esc(unit)}</i></span>
      </label>`;
    const select = (k, label, options) => `
      <label class="calc__field">
        <span>${esc(label)}</span>
        <select class="filter-select" data-k="${k}">${Object.entries(options)
          .map(([key, text]) => `<option value="${key}"${v[k] === key ? ' selected' : ''}>${esc(text)}</option>`).join('')}</select>
      </label>`;
    // the production date is on the plate under the driver's door; Encar has none, so the first
    // registration stands in for it (Korean makes usually match, imports are often registered later)
    const ageNote = ageFrom.from === 'reg' ? `Выбрано по первой регистрации ${Fmt.month(ctx.car.firstRegDate)}. Дата выпуска — на шильде под дверью водителя; у иномарок регистрация часто позже.`
      : ageFrom.from === 'year' ? `Выбрано по году ${ctx.car.year}. Дата выпуска — на шильде под дверью водителя.` : '';
    const ageButtons = `<div class="calc__seg" role="radiogroup" aria-label="Возраст с даты выпуска">${Object.entries(AGES).map(([key, text]) =>
      `<label><input type="radio" name="age-${esc(ctx.id)}" data-k="age" value="${key}"${v.age === key ? ' checked' : ''}><span>${esc(text)}</span></label>`).join('')}</div>`;
    const f = k => field(FIELDS.find(x => x[0] === k));
    const inputsHtml = `
      <section class="calc__sec">
        <h4>Машина</h4>
        <div class="calc__fields">${f('price')}${f('vat')}${f('dealerFee')}</div>
      </section>
      <section class="calc__sec">
        <h4>Доставка и перевод</h4>
        <div class="calc__fields">${f('delivery')}${f('fee')}${f('service')}
          <label class="calc__field calc__notes"${v.mixed ? '' : ' hidden'}><span>В новых купюрах</span>
            <span class="calc__inp"><input class="filter-input" data-k="newNotes" inputmode="decimal" value="${esc(v.newNotes)}"><i>$</i></span></label>
        </div>
        <label class="calc__check"><input type="checkbox" data-k="mixed"${v.mixed ? ' checked' : ''}> У клиента разные купюры
          <small>на старые купюры комиссия на ${fmt(num(base.defaults?.old_banknotes_extra_pct) || 0, 1).replace(',0', '')} % выше</small></label>
      </section>
      <section class="calc__sec">
        <h4>Таможня в РБ</h4>
        <div class="calc__field"><span>Возраст с даты выпуска</span>${ageButtons}</div>
        <div class="calc__fields">
          ${select('engine', 'Двигатель', ENGINES)}
          ${field(['cc', 'Объём', 'см³'], v.engine === 'ev' ? ' hidden data-cc' : ' data-cc')}
          ${field(['customs', 'Таможня вручную', '$'])}
        </div>
        <label class="calc__check"><input type="checkbox" data-k="benefit"${v.benefit ? ' checked' : ''}> Льгота
          <small>минус ${fmt(num(base.defaults?.benefit_pct) || 0)} % от пошлины, сборы не меняются</small></label>
        ${ageNote ? `<p class="calc__hint">${esc(ageNote)}</p>` : ''}
      </section>`;
    const resultsHtml = `
      <dl class="calc__out">
        <div><dt>Цена авто</dt><dd data-out="carWon"></dd></div>
        <div><dt>Авто в $</dt><dd data-out="carUsd"></dd></div>
        <div><dt>Авто и доставка</dt><dd data-out="transfer"></dd></div>
        <div><dt>Комиссия за перевод</dt><dd data-out="fee"></dd></div>
        <div><dt>Таможня</dt><dd data-out="customs"></dd></div>
        <div class="calc__total"><dt>Итого</dt><dd data-out="total"></dd></div>
      </dl>
      <div class="calc__error" data-out="error" hidden></div>
      <div data-out="cx"></div>
      <pre class="calc__msg" data-out="msg"></pre>
      <div class="calc__bar">
        <label class="calc__tpl">Шаблон <select class="filter-select" data-pref="template">${Object.entries(TEMPLATES)
          .map(([k, label]) => `<option value="${k}"${p.template === k ? ' selected' : ''}>${label}</option>`).join('')}</select></label>
        ${ctx.lot ? `<label class="calc__check"><input type="checkbox" data-pref="share"${p.share ? ' checked' : ''}> В сообщение клиенту</label>` : ''}
      </div>
      <div class="calc__bar">
        <button class="link-btn" type="button" data-reset>Сбросить</button>
        <span class="spacer"></span>
        <button class="btn" type="button" data-copy-calc>Скопировать</button>
        ${ctx.onShare ? `<button class="btn btn--primary" type="button" data-share-calc>${Fmt.icon.share}Клиенту</button>` : ''}
      </div>`;
    // the rates on top: what the sources give as the placeholder, a number typed over it counts instead
    const nb = base.nbrb || {};
    const live = { rate: r0.rate, usdByn: nb.usd, eurByn: nb.eur };
    const ratesHtml = `<div class="calc__rates card">${RATES.map(([k, source, unit, sym]) => `
      <label class="calc__ratebox${String(v[k] ?? '').trim() ? ' is-own' : ''}">
        <span class="calc__ratehead"><i class="calc__cur" aria-hidden="true">${sym}</i>${esc(source)} <small>${esc(unit)}</small><em>свой</em></span>
        <input class="filter-input" data-k="${k}" inputmode="decimal" autocomplete="off" value="${esc(v[k])}"
          placeholder="${live[k] ? esc(fmt(live[k], k === 'rate' ? 0 : 4)) : 'нет'}">
        ${k === 'rate' ? `<small>− ${fmt(adj)} ₩ = <b data-out="rate"></b></small>` : ''}
      </label>`).join('')}
      <p class="calc__hint">${r0.stale && r0.rate ? 'Bithumb не ответил — последний известный курс. ' : ''}НБРБ${nb.date ? ' на ' + esc(Fmt.day(nb.date)) : ''}${nb.stale ? ' — последние известные' : ''}. Свой курс — впишите в поле, пустое поле — курс источника.</p>
    </div>`;
    el.innerHTML = `<div class="calc">${ratesHtml}<div class="calc__inputs card">${inputsHtml}</div><div class="calc__results card">${resultsHtml}</div></div>`;

    const $ = s => el.querySelector(s);
    const update = () => {
      const r = compute(v);
      const err = $('[data-out="error"]');
      err.hidden = !r.error;
      err.textContent = r.error || '';
      const set = (k, text) => { const n = $(`[data-out="${k}"]`); if (n) n.textContent = text; };
      set('rate', r.rate ? fmt(r.rate) + ' ₩' + (r.manual ? ' (вручную)' : '') : '—');
      $('[data-out="cx"]').innerHTML = customsLines(r, v);
      if (r.error) {
        ['carWon', 'carUsd', 'transfer', 'fee', 'customs', 'total', 'msg'].forEach(k => set(k, '—'));
        return;
      }
      set('carWon', fmt(r.carWon) + ' ₩');
      set('carUsd', fmt(r.carUsd, 2) + ' $');
      set('transfer', fmt(r.transfer, 2) + ' $');
      set('fee', fmt(r.fee, 2) + ' $');
      set('customs', fmt(r.customs, 2) + ' $' + (r.customsByHand ? ' (вручную)' : ''));
      set('total', fmt(r.total, 2) + ' $');
      set('msg', message(r, prefs().template).join('\n'));
    };
    // what differs from the defaults is kept for this car
    const remember = () => {
      const def = inputs('\u0000', ctx.car);
      const o = {};
      for (const k of KEPT) if (String(v[k]) !== String(def[k])) o[k] = v[k];
      saveOverrides(ctx.id, o);
    };

    el.addEventListener('input', e => {
      const k = e.target.dataset.k;
      if (k) { v[k] = e.target.type === 'checkbox' ? e.target.checked : e.target.value; remember(); }
      if (k === 'mixed') $('.calc__notes').hidden = !v.mixed;
      if (k === 'engine') $('[data-cc]').hidden = v.engine === 'ev';
      if (RATES.some(r => r[0] === k)) e.target.closest('.calc__ratebox').classList.toggle('is-own', String(v[k]).trim() !== '');
      const pk = e.target.dataset.pref;
      if (pk) setPref(pk, e.target.type === 'checkbox' ? e.target.checked : e.target.value);
      update();
    });
    $('[data-reset]').addEventListener('click', () => { saveOverrides(ctx.id, {}); mount(el, ctx); });
    $('[data-share-calc]')?.addEventListener('click', () => ctx.onShare());
    $('[data-copy-calc]').addEventListener('click', async () => {
      const text = $('[data-out="msg"]').textContent;
      if (!text || text === '—') return;
      try { await navigator.clipboard.writeText(text); App.toast('Расчёт скопирован'); } catch (_) { App.toast('Не удалось скопировать'); }
    });
    update();
  }

  return { load, mount, compute, customs, inputs, message, shareLines, summary };
})();
