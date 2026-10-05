/*
 * Skrip halaman pemilih. Tanpa skrip inline (CSP ketat) dan tanpa menyimpan data pemilih di browser.
 */

const body = document.body;
const statusUrl = body.dataset.statusUrl;
const statusFallbackUrl = body.dataset.statusFallbackUrl;
const pollMs = Number(body.dataset.pollSeconds || 3) * 1000;
const page = body.dataset.page;
let useFallback = false;

/** Menampilkan hitung mundur dari detik tersisa (dikoreksi tiap polling). */
function startTimer(remaining) {
    const box = document.querySelector('[data-timer]');

    if (!box || remaining === null || remaining === undefined) {
        return;
    }

    const value = box.querySelector('[data-timer-value]');
    let endsAt = Date.now() + remaining * 1000;

    box.classList.remove('hidden');

    const render = () => {
        const left = Math.max(0, Math.round((endsAt - Date.now()) / 1000));
        const minutes = String(Math.floor(left / 60)).padStart(2, '0');
        const seconds = String(left % 60).padStart(2, '0');
        value.textContent = `${minutes}:${seconds}`;
        box.classList.toggle('timer--low', left <= 30);
    };

    render();
    setInterval(render, 1000);

    return (newRemaining) => {
        endsAt = Date.now() + newRemaining * 1000;
    };
}

/**
 * Membaca status. Jalur utama: file statis (tanpa PHP). Sisa waktu dihitung dari ends_at dan
 * jam server (header Date), sehingga tetap benar walaupun jam HP salah.
 * Jika file belum ada, pakai endpoint cadangan yang sudah menghitung sisa waktu.
 */
async function fetchStatus() {
    const url = useFallback ? statusFallbackUrl : `${statusUrl}?t=${Date.now()}`;
    const response = await fetch(url, { headers: { Accept: 'application/json' }, cache: 'no-store' });

    if (!response.ok) {
        if (!useFallback && response.status === 404) {
            useFallback = true;

            return fetchStatus();
        }

        throw new Error('status');
    }

    const status = await response.json();

    if (useFallback) {
        return status;
    }

    const serverDate = Date.parse(response.headers.get('Date') || '');
    const serverNow = Math.floor((Number.isNaN(serverDate) ? Date.now() : serverDate) / 1000);

    status.remaining = status.ends_at === null ? status.paused_remaining : Math.max(0, status.ends_at - serverNow);

    if (status.state === 'open' && status.ends_at !== null && status.remaining === 0) {
        status.state = 'waiting';
    }

    return status;
}

function pollStatus(onStatus) {
    const tick = async () => {
        try {
            onStatus(await fetchStatus());
        } catch (error) {
            // Jaringan lambat: coba lagi di putaran berikutnya.
        }

        setTimeout(tick, pollMs + Math.floor(Math.random() * 600));
    };

    setTimeout(tick, pollMs);
}

function initTimerFromBody() {
    const remaining = body.dataset.remaining === '' ? null : Number(body.dataset.remaining);

    return startTimer(remaining);
}

/* Halaman awal: tunggu dibuka, lalu pindah ke pencarian nama. */
if (page === 'start') {
    const initialState = body.dataset.state;
    const updateTimer = initTimerFromBody();

    pollStatus((status) => {
        if (status.state !== initialState) {
            window.location.replace(window.location.href);
        } else if (updateTimer && status.remaining !== null) {
            updateTimer(status.remaining);
        }
    });

    const input = document.querySelector('[data-search-input]');
    const list = document.querySelector('[data-search-results]');
    const empty = document.querySelector('[data-search-empty]');
    const hint = document.querySelector('[data-search-hint]');

    if (input) {
        let timer = null;
        let latest = 0;

        input.addEventListener('input', () => {
            clearTimeout(timer);
            timer = setTimeout(async () => {
                const query = input.value.trim();
                const requestId = ++latest;

                list.replaceChildren();
                empty.classList.add('hidden');

                if (query.replace(/\s/g, '').length < 3) {
                    hint.classList.remove('hidden');

                    return;
                }

                hint.classList.add('hidden');

                try {
                    const url = new URL(input.dataset.searchUrl, window.location.origin);
                    url.searchParams.set('q', query);
                    const response = await fetch(url, { headers: { Accept: 'application/json' }, cache: 'no-store' });

                    if (requestId !== latest) {
                        return;
                    }

                    if (response.status === 429) {
                        empty.textContent = 'Terlalu banyak pencarian. Tunggu sebentar lalu coba lagi.';
                        empty.classList.remove('hidden');

                        return;
                    }

                    const data = await response.json();

                    if (!response.ok) {
                        window.location.replace(window.location.href);

                        return;
                    }

                    if (data.results.length === 0) {
                        empty.textContent = 'Nama tidak ditemukan. Jika Anda belum memilih, hubungi panitia.';
                        empty.classList.remove('hidden');

                        return;
                    }

                    for (const result of data.results) {
                        const item = document.createElement('li');
                        const link = document.createElement('a');
                        const name = document.createElement('span');
                        const detail = document.createElement('span');

                        link.className = 'result';
                        link.href = input.dataset.pinUrl.replace('__ID__', encodeURIComponent(result.id));
                        name.className = 'result__name';
                        name.textContent = result.name;
                        detail.className = 'result__detail';
                        detail.textContent = result.detail;

                        link.append(name, detail);
                        item.append(link);
                        list.append(item);
                    }
                } catch (error) {
                    empty.textContent = 'Koneksi bermasalah. Coba lagi.';
                    empty.classList.remove('hidden');
                }
            }, 350);
        });
    }
}

/* Halaman PIN: timer saja, dan cegah kirim ganda. */
if (page === 'pin') {
    initTimerFromBody();
    preventDoubleSubmit();
}

/* Surat suara: pilih -> review -> konfirmasi. */
if (page === 'ballot') {
    const updateTimer = initTimerFromBody();

    pollStatus((status) => {
        if (updateTimer && status.remaining !== null) {
            updateTimer(status.remaining);
        }
    });

    const form = document.querySelector('[data-ballot-form]');
    const choose = document.querySelector('[data-step="choose"]');
    const review = document.querySelector('[data-step="review"]');
    const next = document.querySelector('[data-next]');
    const back = document.querySelector('[data-back]');
    const nextBar = document.querySelector('[data-bar="choose"]');
    const reviewBar = document.querySelector('[data-bar="review"]');

    form.addEventListener('change', () => {
        next.disabled = !form.querySelector('input[name="candidate"]:checked');
    });

    next.addEventListener('click', () => {
        const checked = form.querySelector('input[name="candidate"]:checked');

        if (!checked) {
            return;
        }

        const source = checked.closest('.candidate');
        const photo = review.querySelector('[data-review-photo]');

        photo.replaceChildren();

        const image = source.dataset.photoLarge;

        if (image) {
            const img = document.createElement('img');
            img.src = image;
            img.alt = `Foto ${source.dataset.name}`;
            photo.append(img);
        } else {
            const initials = document.createElement('span');
            initials.className = 'photo__initials';
            initials.textContent = source.dataset.initials;
            photo.append(initials);
        }

        const badge = document.createElement('span');
        badge.className = 'badge';
        badge.textContent = source.dataset.number;
        photo.append(badge);

        review.querySelector('[data-review-number]').textContent = `Nomor ${source.dataset.number}`;
        review.querySelector('[data-review-name]').textContent = source.dataset.name;

        choose.classList.add('hidden');
        nextBar.classList.add('hidden');
        review.classList.remove('hidden');
        reviewBar.classList.remove('hidden');
        window.scrollTo({ top: 0 });
        review.querySelector('h1').focus();
    });

    back.addEventListener('click', () => {
        review.classList.add('hidden');
        reviewBar.classList.add('hidden');
        choose.classList.remove('hidden');
        nextBar.classList.remove('hidden');
    });

    preventDoubleSubmit();
}

/* Selesai: untuk HP pinjaman (gelombang bantuan) kembali ke awal otomatis. */
if (page === 'done' && body.dataset.autoReturn === '1') {
    setTimeout(() => window.location.replace(body.dataset.startUrl), 8000);
}

function preventDoubleSubmit() {
    document.querySelectorAll('form[data-once]').forEach((form) => {
        form.addEventListener('submit', () => {
            form.querySelectorAll('button[type="submit"]').forEach((button) => {
                button.disabled = true;
                button.textContent = 'Menyimpan...';
            });
        });
    });
}
