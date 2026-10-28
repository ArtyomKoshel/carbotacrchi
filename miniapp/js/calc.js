/* Landed-cost calculator: the sales team's formula, as their standalone calculator counts it.
   Car price ₩ + dealer parking fee, minus the VAT refund → $ at Bithumb USDT/KRW minus the
   adjustment; + delivery (Korea expenses included) = the transfer; + transfer fee (the part
   in old banknotes pays the extra percent); + customs = the total. Our service is in BYN, on
   its own line. Defaults and the rate come from /api/calculator (set in /admin/calculator);
   what a manager changes for one car is kept for that car in this browser. */
const Calc = (() => {
  const LOTS_KEY = 'carbot_calc_lots';     // { lotId | 'manual': { field: value } }, last 60
  const PREFS_KEY = 'carbot_calc_prefs';   // { template, share }
  const KEEP = 60;
  const FIELDS = [
    // key, label, unit, default key in /api/calculator
    ['price', 'Цена авто', 'млн ₩', null],
    ['vat', 'Возврат НДС', '%', 'vat_refund_pct'],
    ['customs', 'Таможня, СВХ, декларант', '$', 'customs_usd'],
    ['delivery', 'Доставка с расходами в Корее', '$', 'delivery_usd'],
    ['dealerFee', 'Стояночные дилера', 'млн ₩', 'dealer_fee_millions'],
    ['fee', 'Комиссия за перевод', '%', 'transfer_fee_pct'],
    ['service', 'Наши услуги', 'BYN', 'service_byn'],
    ['rate', 'Курс вручную', '₩ за $', null],
  ];

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

  /** Every input of one calculation: team defaults, the lot's price, then what the manager changed. */
  function inputs(id, priceKrw) {
    const d = base?.defaults || {};
    const o = overrides(id);
    const v = {
      price: priceKrw > 0 ? +(priceKrw / 1e6).toFixed(3) : '',
      vat: d.vat_refund_pct, customs: d.customs_usd, delivery: d.delivery_usd, dealerFee: d.dealer_fee_millions,
      fee: d.transfer_fee_pct, service: d.service_byn, rate: '',
      mixed: false, newNotes: 0,
      ...o,
    };
    delete v._t;
    return v;
  }

  /** The numbers, or { error } when a field is wrong; nothing guessed. */
  function compute(v) {
    const d = base?.defaults || {};
    const market = base?.rate?.rate;
    const manual = num(v.rate);
    const n = k => num(v[k]);
    const rate = (manual > 0 ? manual : market) - (num(d.rate_adjustment_krw) || 0);
    const fail = error => ({ error, rate: rate > 0 ? rate : null, manual: manual > 0 });
    if (!(n('price') > 0)) return fail('Введите цену авто в миллионах вон');
    for (const k of ['vat', 'customs', 'delivery', 'dealerFee', 'fee', 'service']) {
      if (!Number.isFinite(n(k)) || n(k) < 0) return fail('Проверьте поле «' + FIELDS.find(f => f[0] === k)[1] + '»');
    }
    if (n('vat') >= 100) return fail('Возврат НДС должен быть меньше 100 %');
    const korea = num(d.korea_expenses_usd) || 0;
    if (n('delivery') < korea) return fail(`Доставка не может быть меньше расходов в Корее (${usd(korea)})`);
    if (!(rate > 0)) return fail('Нет курса: Bithumb не ответил — введите курс вручную');

    const carWon = Math.round((n('price') + n('dealerFee')) * 1e6 * (1 - n('vat') / 100));
    const carUsd = carWon / rate;
    const transfer = carUsd + n('delivery');
    let fee = transfer * n('fee') / 100;
    if (v.mixed) {
      const newer = num(v.newNotes);
      if (!Number.isFinite(newer) || newer < 0) return { error: 'Проверьте сумму в новых купюрах' };
      if (newer > transfer) return { error: `Сумма в новых купюрах больше перевода (${usd(transfer)})` };
      fee = newer * n('fee') / 100 + (transfer - newer) * (n('fee') + (num(d.old_banknotes_extra_pct) || 0)) / 100;
    }
    const beforeCustoms = transfer + fee;
    return {
      rate, manual: manual > 0, carWon, carUsd, transfer, fee, beforeCustoms,
      total: beforeCustoms + n('customs'), customs: n('customs'), service: n('service'),
      korea, toMinsk: Math.max(0, n('delivery') - korea),
    };
  }

  const fmt = (v, digits = 0) => Number.isFinite(v)
    ? new Intl.NumberFormat('ru-RU', { minimumFractionDigits: digits, maximumFractionDigits: digits }).format(v) : '—';
  const usd = v => fmt(Math.round(v)) + ' $';
  const byn = v => fmt(v, Number.isInteger(v) ? 0 : 2) + ' BYN';

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
  function shareLines(lotId, priceKrw) {
    if (!base || !prefs().share) return null;
    const r = compute(inputs(lotId, priceKrw));
    return r.error ? null : message(r, prefs().template);
  }

  /** The lot card's line: «26 833 $ + 800 BYN» with this car's corrections, or null. */
  async function summary(lotId, priceKrw) {
    await load();
    const r = compute(inputs(lotId, priceKrw));
    return r.error ? null : usd(r.total) + (r.service > 0 ? ' + ' + byn(r.service) : '');
  }

  // ── form ────────────────────────────────────────────────────────────────
  const esc = s => Fmt.esc(String(s ?? ''));

  /**
   * The calculator in `el`. ctx: { id: lot id or 'manual', priceKrw, lot: true for one car's page
   * («В сообщение клиенту»), onShare: sends the car to the client (a «Клиенту» button) }.
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
    const v = inputs(ctx.id, ctx.priceKrw);
    const p = prefs();
    const r0 = base.rate || {};
    const adj = num(base.defaults?.rate_adjustment_krw) || 0;
    const field = ([k, label, unit]) => `
      <label class="calc__field">
        <span>${esc(label)}</span>
        <span class="calc__inp"><input class="filter-input" data-k="${k}" inputmode="decimal" autocomplete="off"
          value="${esc(v[k])}" placeholder="${k === 'rate' && r0.rate ? esc(fmt(r0.rate)) : ''}"><i>${esc(unit)}</i></span>
      </label>`;
    const body = `
      <div class="calc__rate">Курс Bithumb ${r0.rate ? `<b>${fmt(r0.rate)} ₩</b> − ${fmt(adj)} = <b data-out="rate"></b>` : '<b>нет</b>'}
        ${r0.stale && r0.rate ? '<span class="calc__stale">последний известный</span>' : ''}</div>
      <div class="calc__fields">${FIELDS.map(field).join('')}</div>
      <label class="calc__check"><input type="checkbox" data-k="mixed"${v.mixed ? ' checked' : ''}> У клиента разные купюры
        <small>на старые купюры комиссия на ${fmt(num(base.defaults?.old_banknotes_extra_pct) || 0, 1).replace(',0', '')} % выше</small></label>
      <label class="calc__field calc__notes"${v.mixed ? '' : ' hidden'}><span>Сумма в новых купюрах</span>
        <span class="calc__inp"><input class="filter-input" data-k="newNotes" inputmode="decimal" value="${esc(v.newNotes)}"><i>$</i></span></label>
      <dl class="calc__out">
        <div><dt>Цена авто</dt><dd data-out="carWon"></dd></div>
        <div><dt>Авто в $</dt><dd data-out="carUsd"></dd></div>
        <div><dt>Авто и доставка</dt><dd data-out="transfer"></dd></div>
        <div><dt>Комиссия за перевод</dt><dd data-out="fee"></dd></div>
        <div><dt>До таможни</dt><dd data-out="beforeCustoms"></dd></div>
        <div class="calc__total"><dt>После таможни</dt><dd data-out="total"></dd></div>
      </dl>
      <div class="calc__error" data-out="error" hidden></div>
      <pre class="calc__msg" data-out="msg"></pre>
      <div class="calc__bar">
        <label class="calc__tpl">Шаблон <select class="filter-select" data-pref="template">${Object.entries(TEMPLATES)
          .map(([k, label]) => `<option value="${k}"${p.template === k ? ' selected' : ''}>${label}</option>`).join('')}</select></label>
        ${ctx.lot ? `<label class="calc__check"><input type="checkbox" data-pref="share"${p.share ? ' checked' : ''}> В сообщение клиенту</label>` : ''}
        <span class="spacer"></span>
        <button class="link-btn" type="button" data-reset>Сбросить</button>
        <button class="btn" type="button" data-copy-calc>Скопировать</button>
        ${ctx.onShare ? `<button class="btn btn--primary" type="button" data-share-calc>${Fmt.icon.share}Клиенту</button>` : ''}
      </div>`;
    el.innerHTML = `<div class="calc card"><div class="calc__body">${body}</div></div>`;

    const $ = s => el.querySelector(s);
    const update = () => {
      const r = compute(v);
      const err = $('[data-out="error"]');
      err.hidden = !r.error;
      err.textContent = r.error || '';
      const set = (k, text) => { const n = $(`[data-out="${k}"]`); if (n) n.textContent = text; };
      set('rate', r.rate ? fmt(r.rate) + ' ₩' + (r.manual ? ' (вручную)' : '') : '—');
      if (r.error) {
        ['carWon', 'carUsd', 'transfer', 'fee', 'beforeCustoms', 'total', 'msg'].forEach(k => set(k, '—'));
        return;
      }
      set('carWon', fmt(r.carWon) + ' ₩');
      set('carUsd', fmt(r.carUsd, 2) + ' $');
      set('transfer', fmt(r.transfer, 2) + ' $');
      set('fee', fmt(r.fee, 2) + ' $');
      set('beforeCustoms', fmt(r.beforeCustoms, 2) + ' $');
      set('total', fmt(r.total, 2) + ' $');
      set('msg', message(r, prefs().template).join('\n'));
    };
    // what differs from the defaults is kept for this car
    const remember = () => {
      const def = inputs('\u0000', ctx.priceKrw);
      const o = {};
      for (const k of [...FIELDS.map(f => f[0]), 'mixed', 'newNotes']) if (String(v[k]) !== String(def[k])) o[k] = v[k];
      saveOverrides(ctx.id, o);
    };

    el.addEventListener('input', e => {
      const k = e.target.dataset.k;
      if (k) { v[k] = e.target.type === 'checkbox' ? e.target.checked : e.target.value; remember(); }
      if (k === 'mixed') $('.calc__notes').hidden = !v.mixed;
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

  return { load, mount, compute, message, shareLines, summary };
})();
