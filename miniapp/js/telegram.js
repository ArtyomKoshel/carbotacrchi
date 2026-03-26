const TG = (() => {
  const twa = window.Telegram?.WebApp;

  function init() {
    if (twa) {
      twa.ready();
      twa.expand();
    }
  }

  function getUserId() {
    return twa?.initDataUnsafe?.user?.id ?? 0;
  }

  function getInitData() {
    return twa?.initData ?? '';
  }

  function getTheme() {
    return twa?.colorScheme ?? 'dark';
  }

  function haptic(type = 'impact', style = 'light') {
    if (!twa?.HapticFeedback) return;
    if (type === 'impact')       twa.HapticFeedback.impactOccurred(style);
    else if (type === 'notification') twa.HapticFeedback.notificationOccurred(style);
    else if (type === 'selection')    twa.HapticFeedback.selectionChanged();
  }

  function sendToChatAndClose(data) {
    if (twa) {
      twa.sendData(JSON.stringify(data));
    }
  }

  function setMainButton(text, onClick) {
    if (!twa?.MainButton) return;
    twa.MainButton.setText(text);
    twa.MainButton.onClick(onClick);
    twa.MainButton.show();
  }

  function hideMainButton() {
    twa?.MainButton?.hide();
  }

  function back(cb) {
    if (!twa?.BackButton) return;
    twa.BackButton.onClick(cb);
    twa.BackButton.show();
  }

  function hideBack() {
    twa?.BackButton?.hide();
  }

  function close() {
    twa?.close();
  }

  return { init, getUserId, getInitData, getTheme, haptic, sendToChatAndClose, setMainButton, hideMainButton, back, hideBack, close };
})();
