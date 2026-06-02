const Chat = (() => {
  let isLoading = false;
  let typingCounter = 0;

  function init() {
    const input   = document.getElementById('chat-input');
    const sendBtn = document.getElementById('chat-send-btn');

    input?.addEventListener('keydown', e => {
      if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); }
    });
    input?.addEventListener('input', _autoResize);

    sendBtn?.addEventListener('click', send);

    document.getElementById('chat-example-1')?.addEventListener('click', () =>
      _fillAndSend('Хочу Hyundai Sonata 2020–2022, до $15 000, без аварий'));
    document.getElementById('chat-example-2')?.addEventListener('click', () =>
      _fillAndSend('Kia K5 до 2023 года, автомат, до 20 000$'));
  }

  function _autoResize() {
    const el = document.getElementById('chat-input');
    if (!el) return;
    el.style.height = 'auto';
    el.style.height = Math.min(el.scrollHeight, 96) + 'px';
  }

  function _fillAndSend(text) {
    const input = document.getElementById('chat-input');
    if (!input) return;
    input.value = text;
    _autoResize();
    send();
  }

  async function send() {
    const input = document.getElementById('chat-input');
    const text  = input?.value.trim();
    if (!text || isLoading) return;

    document.getElementById('chat-intro')?.remove();

    input.value = '';
    input.style.height = 'auto';

    _appendUserMsg(text);
    const typingId = _appendTyping();
    isLoading = true;
    _setSendEnabled(false);

    try {
      const result = await API.searchChat(text);
      _removeEl(typingId);

      if (result?.query) {
        _appendQueryResult(result.message ?? 'Нашёл параметры:', result.query);
      } else {
        _appendBotMsg(result?.message ?? 'Не удалось распознать запрос. Попробуйте переформулировать.');
      }
    } catch (_) {
      _removeEl(typingId);
      _appendBotMsg('Произошла ошибка. Попробуйте ещё раз.');
    } finally {
      isLoading = false;
      _setSendEnabled(true);
    }

    _scrollToBottom();
  }

  function _appendUserMsg(text) {
    _append('chat-msg--user', `<div class="chat-bubble">${_esc(text)}</div>`);
    _scrollToBottom();
  }

  function _appendBotMsg(text) {
    _append('chat-msg--bot', `<div class="chat-bubble">${_esc(text)}</div>`);
    _scrollToBottom();
  }

  function _appendQueryResult(message, query) {
    const rows = _buildQueryRows(query);
    const id   = `qcard-${Date.now()}`;

    const div = _append('chat-msg--bot', `
      <div class="chat-bubble">
        ${_esc(message)}
        <div class="chat-query-card" id="${id}">
          ${rows}
          <div class="chat-query-actions">
            <button class="chat-query-search-btn">Найти →</button>
          </div>
        </div>
      </div>`);

    div?.querySelector('.chat-query-search-btn')?.addEventListener('click', () => {
      Filters.applyQuery(query);
      App.setSearchMode('filters');
      App.searchWithQuery(query);
    });

    _scrollToBottom();
  }

  function _buildQueryRows(q) {
    const row = (label, value) =>
      `<div class="chat-query-row"><span class="q-label">${label}</span><span class="q-value">${value}</span></div>`;

    const parts = [];

    if (q.make || q.model) {
      parts.push(row('Модель', _esc([q.make, q.model].filter(Boolean).join(' '))));
    }
    if (q.yearFrom || q.yearTo) {
      const yr = [q.yearFrom, q.yearTo].filter(Boolean).join(' – ');
      parts.push(row('Год', _esc(yr)));
    }
    if (q.priceMin || q.priceMax) {
      const pr = [
        q.priceMin ? `от $${Number(q.priceMin).toLocaleString()}` : '',
        q.priceMax ? `до $${Number(q.priceMax).toLocaleString()}` : '',
      ].filter(Boolean).join(' ');
      parts.push(row('Цена', pr));
    }
    if (q.mileageMax) {
      parts.push(row('Пробег до', `${Number(q.mileageMax).toLocaleString()} km`));
    }
    if (q.transmissions?.length) {
      parts.push(row('КПП', _esc(q.transmissions.join(', '))));
    }
    if (q.hasAccident === false) {
      parts.push(row('История ДТП', '<span style="color:var(--success)">Без аварий</span>'));
    }
    if (q.sources?.length) {
      parts.push(row('Аукционы', _esc(q.sources.join(', '))));
    }

    return parts.join('') || row('Параметры', 'Без ограничений');
  }

  function _appendTyping() {
    const id  = `typing-${++typingCounter}`;
    const div = _append('chat-msg--bot', `
      <div class="chat-bubble chat-bubble--typing">
        <div class="typing-dots"><span></span><span></span><span></span></div>
      </div>`);
    if (div) div.id = id;
    _scrollToBottom();
    return id;
  }

  function _append(cls, html) {
    const msgs = document.getElementById('chat-messages');
    if (!msgs) return null;
    const div = document.createElement('div');
    div.className = `chat-msg ${cls}`;
    div.innerHTML = html;
    msgs.appendChild(div);
    return div;
  }

  function _removeEl(id) {
    if (id) document.getElementById(id)?.remove();
  }

  function _setSendEnabled(on) {
    const btn = document.getElementById('chat-send-btn');
    if (btn) btn.disabled = !on;
  }

  function _scrollToBottom() {
    const msgs = document.getElementById('chat-messages');
    if (msgs) msgs.scrollTop = msgs.scrollHeight;
  }

  function _esc(v) {
    return String(v ?? '').replace(/[&<>"']/g,
      c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  }

  return { init, send };
})();
