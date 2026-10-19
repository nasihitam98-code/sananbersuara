/*
 * Layar tetap menyala selama halaman ini terbuka (laptop bilik, layar QR, layar hasil): tanpa ini laptop bisa
 * meredup/tidur dan berhenti menerima izin. Browser melepas kunci saat tab disembunyikan, jadi diminta ulang
 * ketika tab terlihat lagi. Browser tanpa dukungan (atau tanpa HTTPS) diam saja; atur juga Power di Windows.
 */
export function keepScreenAwake() {
    if (!('wakeLock' in navigator)) {
        return;
    }

    const request = async () => {
        if (document.visibilityState !== 'visible') {
            return;
        }

        try {
            await navigator.wakeLock.request('screen');
        } catch {
            // Ditolak (mis. hemat baterai): layar mengikuti pengaturan Windows.
        }
    };

    request();
    document.addEventListener('visibilitychange', request);
}
