/*
 * Layar proyektor (QR + partisipasi live). Hanya jumlah sudah/belum memilih; tidak ada angka per calon.
 * Tanpa skrip inline (CSP ketat).
 */

const body = document.body;
const statusUrl = body.dataset.statusUrl;
const pollMs = 3000;

const el = (name) => document.querySelector(`[data-${name}]`);

const headlines = {
    open: 'VOTING DIBUKA',
    paused: 'Voting dijeda sebentar',
    finished: 'Pemilihan selesai',
    waiting: 'Menunggu voting dibuka',
};

let endsAt = null;

function renderTimer() {
    const timer = el('timer');

    if (endsAt === null) {
        timer.classList.add('hidden');

        return;
    }

    const left = Math.max(0, Math.round((endsAt - Date.now()) / 1000));
    timer.textContent = `${String(Math.floor(left / 60)).padStart(2, '0')}:${String(left % 60).padStart(2, '0')}`;
    timer.classList.remove('hidden');
    timer.classList.toggle('screen__timer--low', left <= 30);
}

function hintFor(status) {
    switch (status.phase) {
        case 'open':
            return status.not_voted > 0 ? 'Belum memilih? Scan QR sekarang dan siapkan PIN Anda.' : 'Semua yang hadir sudah memilih. Terima kasih!';
        case 'paused':
            return 'Mohon tunggu. Halaman di HP akan berubah sendiri saat dilanjutkan.';
        case 'finished':
            return 'Terima kasih atas partisipasi Anda. Tunggu pengumuman hasil dari panitia.';
        default:
            return status.had_waves && status.not_voted > 0
                ? `${status.not_voted} orang belum memilih. Tunggu sesi berikutnya dibuka panitia.`
                : 'Siapkan kertas PIN Anda. Halaman di HP berubah sendiri saat voting dibuka.';
    }
}

function render(status) {
    body.dataset.phase = status.phase;
    el('headline').textContent = headlines[status.phase] ?? headlines.waiting;
    el('wave').textContent = status.wave_name ?? '';
    el('voted').textContent = status.voted;
    el('attendees').textContent = status.attendees;
    el('percent').textContent = status.percent;
    el('bar-fill').style.width = `${Math.min(100, status.percent)}%`;
    el('bar').setAttribute('aria-valuenow', String(status.percent));
    el('hint').textContent = hintFor(status);
    el('qr-card').classList.toggle('hidden', status.phase === 'finished');

    endsAt = status.remaining === null ? null : Date.now() + status.remaining * 1000;
    renderTimer();
}

async function poll() {
    try {
        const response = await fetch(`${statusUrl}?t=${Date.now()}`, { headers: { Accept: 'application/json' }, cache: 'no-store' });

        if (response.ok) {
            render(await response.json());
        }
    } catch {
        // Jaringan putus sebentar: coba lagi pada putaran berikutnya.
    }

    setTimeout(poll, pollMs);
}

if (statusUrl) {
    setInterval(renderTimer, 1000);
    poll();
}
