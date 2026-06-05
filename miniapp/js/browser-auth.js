/**
 * BrowserAuth — manages linking a browser session to a Telegram chat.
 *
 * Flow:
 *  1. On init: load or create a browser token from localStorage.
 *  2. If token is already linked (chat_id stored) → done, silent.
 *  3. If not linked: show the "link" bar.
 *  4. User clicks "Привязать Telegram" → call API to get bot_url, open it,
 *     start polling until linked.
 *  5. On linked: store chat_id, update UI, stop polling.
 */
const BrowserAuth = (() => {
  const TOKEN_KEY   = 'carbot_browser_token';
  const CHAT_KEY    = 'carbot_browser_chat_id';
  const USER_KEY    = 'carbot_browser_user';   // {first_name, username}
  const POLL_MS     = 3000;
  const MAX_POLLS   = 100; // ~5 min

  let _token    = null;
  let _chatId   = null;
  let _user     = null;   // {first_name, username}
  let _pollTimer= null;
  let _polls    = 0;
  let _onLinked = null;   // callback

  // ── Persistence ──────────────────────────────────────────────────────────────

  function _load() {
    try {
      _token  = localStorage.getItem(TOKEN_KEY) || null;
      _chatId = parseInt(localStorage.getItem(CHAT_KEY) || '0', 10) || null;
      const raw = localStorage.getItem(USER_KEY);
      _user = raw ? JSON.parse(raw) : null;
    } catch (_) {}
  }

  function _saveToken(token) {
    _token = token;
    try { localStorage.setItem(TOKEN_KEY, token); } catch (_) {}
  }

  function _saveLinked(chatId, user) {
    _chatId = chatId;
    _user   = user;
    try {
      localStorage.setItem(CHAT_KEY, String(chatId));
      localStorage.setItem(USER_KEY, JSON.stringify(user));
    } catch (_) {}
  }

  // ── Public API ────────────────────────────────────────────────────────────────

  /** Returns true when running outside Telegram WebApp. */
  function isBrowserMode() {
    return !window.Telegram?.WebApp?.initData;
  }

  function isLinked() {
    return !!_chatId;
  }

  function getChatId() {
    return _chatId || 0;
  }

  function getUser() {
    return _user || null;
  }

  /**
   * Initialize: load state, render bar if needed.
   * @param {Function} onLinked - called when linking completes
   */
  function init(onLinked) {
    if (!isBrowserMode()) return;

    _load();
    _onLinked = onLinked || null;

    document.body.classList.add('browser-mode');
    _renderBar();
  }

  /**
   * Start the linking flow: fetch bot URL, open Telegram, poll for confirmation.
   */
  async function startLinking() {
    _setBarState('loading');

    try {
      const data = await API.browserAuthInit();
      if (!data?.token || !data?.bot_url) throw new Error('Bad server response');

      _saveToken(data.token);
      window.open(data.bot_url, '_blank');
      _setBarState('waiting');
      _startPolling();
    } catch (e) {
      _setBarState('error', e.message);
    }
  }

  // ── Polling ────────────────────────────────────────────────────────────────

  function _startPolling() {
    _polls = 0;
    _stopPolling();
    _pollTimer = setInterval(_poll, POLL_MS);
  }

  function _stopPolling() {
    if (_pollTimer) { clearInterval(_pollTimer); _pollTimer = null; }
  }

  async function _poll() {
    if (!_token) { _stopPolling(); return; }
    if (++_polls > MAX_POLLS) {
      _stopPolling();
      _setBarState('timeout');
      return;
    }

    try {
      const data = await API.browserAuthStatus(_token);
      if (data?.linked && data?.chat_id) {
        _stopPolling();
        const user = { first_name: data.first_name || '', username: data.username || '' };
        _saveLinked(data.chat_id, user);
        _setBarState('linked');
        if (typeof _onLinked === 'function') _onLinked(data.chat_id);
      }
    } catch (_) {
      // Silently ignore poll errors
    }
  }

  // ── Bar UI ────────────────────────────────────────────────────────────────

  function _renderBar() {
    if (document.getElementById('browser-link-bar')) return; // already rendered

    const bar = document.createElement('div');
    bar.id        = 'browser-link-bar';
    bar.className = 'browser-link-bar';
    bar.innerHTML = _barHtml('idle');
    document.body.appendChild(bar);

    bar.addEventListener('click', e => {
      const btn = e.target.closest('[data-action]');
      if (!btn) return;
      if (btn.dataset.action === 'link')   startLinking();
      if (btn.dataset.action === 'retry')  startLinking();
      if (btn.dataset.action === 'unlink') _unlink();
    });

    if (isLinked()) {
      _setBarState('linked');
    } else if (_token) {
      // Token exists but chat not linked yet — offer re-link
      _setBarState('idle');
    }
  }

  function _barHtml(state) {
    switch (state) {
      case 'idle':
        return `
          <span class="blb-icon">💬</span>
          <span class="blb-text">Привяжите Telegram, чтобы получать уведомления</span>
          <button class="blb-btn" data-action="link">Привязать</button>`;

      case 'loading':
        return `
          <span class="blb-icon">⏳</span>
          <span class="blb-text">Получаем ссылку…</span>`;

      case 'waiting':
        return `
          <span class="blb-icon">📲</span>
          <span class="blb-text">Откройте Telegram и нажмите Start в боте</span>
          <span class="blb-dots"><span></span><span></span><span></span></span>`;

      case 'linked': {
        const name = _user?.first_name || _user?.username || 'Telegram';
        return `
          <span class="blb-icon">✅</span>
          <span class="blb-text">Привязан: <b>${_esc(name)}</b></span>
          <button class="blb-btn blb-btn--unlink" data-action="unlink">Отвязать</button>`;
      }

      case 'timeout':
        return `
          <span class="blb-icon">⏱</span>
          <span class="blb-text">Время ожидания истекло</span>
          <button class="blb-btn" data-action="retry">Повторить</button>`;

      case 'error':
        return `
          <span class="blb-icon">⚠️</span>
          <span class="blb-text">Ошибка привязки</span>
          <button class="blb-btn" data-action="retry">Повторить</button>`;

      default:
        return '';
    }
  }

  function _setBarState(state) {
    const bar = document.getElementById('browser-link-bar');
    if (!bar) return;
    bar.innerHTML = _barHtml(state);
    bar.dataset.state = state;
  }

  function _unlink() {
    _stopPolling();
    _token  = null;
    _chatId = null;
    _user   = null;
    try {
      localStorage.removeItem(TOKEN_KEY);
      localStorage.removeItem(CHAT_KEY);
      localStorage.removeItem(USER_KEY);
    } catch (_) {}
    _setBarState('idle');
  }

  function _esc(v) {
    return String(v ?? '').replace(/[&<>"']/g,
      c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  }

  return { init, isBrowserMode, isLinked, getChatId, getUser, startLinking };
})();
