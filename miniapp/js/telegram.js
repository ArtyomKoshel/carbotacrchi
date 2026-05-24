const TG = (() => {
  const getTwa = () => window.Telegram?.WebApp ?? null;

  function init() {
    const twa = getTwa();
    if (!twa) return;
    twa.ready();
    twa.expand();
    // Bot API 8.0+: true fullscreen (hides header bar); older versions throw — ignore
    if (typeof twa.requestFullscreen === 'function') {
      try { twa.requestFullscreen().catch(() => {}); } catch (_) {}
    }
  }

  function getUserId() {
    const twa = getTwa();
    return twa?.initDataUnsafe?.user?.id ?? 0;
  }

  function getInitData() {
    const twa = getTwa();
    return twa?.initData ?? '';
  }

  function getTheme() {
    const twa = getTwa();
    return twa?.colorScheme ?? 'dark';
  }

  function haptic(type = 'impact', style = 'light') {
    const twa = getTwa();
    if (!twa?.HapticFeedback) return;
    if (type === 'impact')       twa.HapticFeedback.impactOccurred(style);
    else if (type === 'notification') twa.HapticFeedback.notificationOccurred(style);
    else if (type === 'selection')    twa.HapticFeedback.selectionChanged();
  }

  function sendToChatAndClose(data) {
    const twa = getTwa();
    if (twa) {
      twa.sendData(JSON.stringify(data));
    }
  }

  function setMainButton(text, onClick) {
    const twa = getTwa();
    if (!twa?.MainButton) return;
    twa.MainButton.setText(text);
    twa.MainButton.onClick(onClick);
    twa.MainButton.show();
  }

  function hideMainButton() {
    const twa = getTwa();
    twa?.MainButton?.hide();
  }

  function back(cb) {
    const twa = getTwa();
    if (!twa?.BackButton) return;
    twa.BackButton.onClick(cb);
    twa.BackButton.show();
  }

  function hideBack() {
    const twa = getTwa();
    twa?.BackButton?.hide();
  }

  function close() {
    const twa = getTwa();
    twa?.close();
  }

  return { init, getUserId, getInitData, getTheme, haptic, sendToChatAndClose, setMainButton, hideMainButton, back, hideBack, close };
})();
