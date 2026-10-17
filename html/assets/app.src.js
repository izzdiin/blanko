// Blanko app.js — didaftar dengan defer, tanpa jQuery.
document.addEventListener('DOMContentLoaded', () => {
  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('./sw.js').catch(() => {});
  }

  // PWA install
  let deferredPrompt = null;
  const installBtn = document.getElementById('pwa-install-btn');
  const isStandalone = window.matchMedia('(display-mode: standalone)').matches
    || (window.navigator.standalone === true);
  const isIOS = /iphone|ipad|ipod/i.test(navigator.userAgent);
  const isAndroid = /android/i.test(navigator.userAgent);

  function showBtn() { if (installBtn && !isStandalone) installBtn.hidden = false; }
  function hideBtn() { if (installBtn) installBtn.hidden = true; }

  window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault();
    deferredPrompt = e;
    showBtn();
  });

  window.addEventListener('appinstalled', () => {
    deferredPrompt = null;
    hideBtn();
  });

  // Tampilkan tombol untuk semua mobile (fallback jika beforeinstallprompt tidak fire)
  if (!isStandalone && (isIOS || isAndroid)) showBtn();

  if (installBtn) {
    installBtn.addEventListener('click', async () => {
      // 1. Coba native prompt (Chrome/Edge)
      if (deferredPrompt) {
        try {
          deferredPrompt.prompt();
          const choice = await deferredPrompt.userChoice;
          if (choice && choice.outcome === 'accepted') hideBtn();
        } catch (err) { /* abaikan */ }
        deferredPrompt = null;
        return;
      }
      // 2. Fallback: panduan manual
      if (isIOS) {
        alert('Cara install di iPhone:\n\n1. Ketuk ikon Bagikan (Share) di Safari\n2. Pilih "Tambahkan ke Layar Utama"\n3. Ketuk "Tambah"');
      } else if (isAndroid) {
        alert('Cara install di Android:\n\n1. Ketuk menu ⋮ (titik tiga) di Chrome\n2. Pilih "Install aplikasi" atau "Tambahkan ke Layar Utama"\n3. Ketuk "Install"');
      } else {
        alert('Untuk menginstall: buka menu browser, lalu pilih "Install" atau "Tambahkan ke Layar Utama".');
      }
    });
  }
});
