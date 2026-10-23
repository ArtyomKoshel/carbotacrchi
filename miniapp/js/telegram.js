/**
 * Telegram WebApp, when the page runs inside Telegram (initData present); every call is a
 * no-op in a browser. Uses Telegram's own controls: BackButton, MainButton, SecondaryButton
 * (Bot API 7.10), shareMessage (8.0), haptics. No forced fullscreen: Telegram's header stays,
 * so its close and menu buttons never cover ours.
 */
const TG = (() => {
  const twa = () => window.Telegram?.WebApp ?? null;
  const inside = () => !!twa()?.initData;
  const atLeast = v => { try { return !!twa()?.isVersionAtLeast?.(v); } catch (_) { return false; } };

  let backHandler = null;
  let mainHandler = null;
  let secondHandler = null;

  function init() {
    const t = twa();
    if (!inside()) return false;
    t.ready();
    t.expand();
    if (atLeast('7.7')) { try { t.disableVerticalSwipes(); } catch (_) {} } // a long list scrolls instead of closing the app
    if (atLeast('6.1')) { try { t.setHeaderColor('secondary_bg_color'); } catch (_) {} }
    if (atLeast('7.10')) { try { t.setBottomBarColor('bottom_bar_bg_color'); } catch (_) {} }
    return true;
  }

  function getUserId() { return twa()?.initDataUnsafe?.user?.id ?? 0; }
  function getInitData() { return twa()?.initData ?? ''; }
  function startParam() { return twa()?.initDataUnsafe?.start_param ?? ''; }

  function haptic(type = 'impact', style = 'light') {
    const h = inside() ? twa()?.HapticFeedback : null;
    if (!h) return;
    try {
      if (type === 'impact') h.impactOccurred(style);
      else if (type === 'notification') h.notificationOccurred(style);
      else h.selectionChanged();
    } catch (_) {}
  }

  /** The back button in Telegram's header; null hides it. */
  function setBack(fn) {
    const b = inside() ? twa()?.BackButton : null;
    if (!b) return;
    if (backHandler) b.offClick(backHandler);
    backHandler = fn;
    if (fn) { b.onClick(fn); b.show(); } else b.hide();
  }

  function _button(b, text, fn, prev, opts = {}) {
    if (prev) b.offClick(prev);
    if (!fn) { b.hide(); return null; }
    b.setParams({ text, is_active: opts.active !== false, is_visible: true, ...(opts.position ? { position: opts.position } : {}) });
    b.onClick(fn);
    if (opts.progress) b.showProgress(false); else b.hideProgress();
    return fn;
  }

  /** Telegram's bottom button; returns false when Telegram has none (the page shows its own). */
  function setMain(text, fn, opts) {
    const b = inside() ? twa()?.MainButton : null;
    if (!b) return false;
    mainHandler = _button(b, text, fn, mainHandler, opts);
    return true;
  }
  function setSecondary(text, fn, opts) {
    const b = inside() && atLeast('7.10') ? twa()?.SecondaryButton : null;
    if (!b) return false;
    secondHandler = _button(b, text, fn, secondHandler, { position: 'left', ...opts });
    return true;
  }
  const hasSecondary = () => inside() && atLeast('7.10') && !!twa()?.SecondaryButton;

  /** Telegram's chat picker for a message the bot prepared (Bot API 8.0). */
  function share(preparedId) {
    const t = twa();
    if (!inside() || !atLeast('8.0') || typeof t.shareMessage !== 'function') return Promise.resolve(false);
    return new Promise(resolve => { try { t.shareMessage(preparedId, ok => resolve(!!ok)); } catch (_) { resolve(false); } });
  }
  const canShare = () => inside() && atLeast('8.0') && typeof twa()?.shareMessage === 'function';

  function openLink(url) {
    const t = twa();
    if (inside() && t.openLink) t.openLink(url); else window.open(url, '_blank', 'noopener');
  }

  return { init, inside, getUserId, getInitData, startParam, haptic, setBack, setMain, setSecondary, hasSecondary, share, canShare, openLink };
})();
