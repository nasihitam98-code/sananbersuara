/*
 * Portal publik: animasi muncul saat digulir dan carousel kartu hasil.
 * Tanpa skrip inline (CSP ketat). Semua tetap berfungsi tanpa JavaScript.
 */

document.documentElement.classList.add('js');

function initReveal() {
    const items = [...document.querySelectorAll('.reveal')];

    if (!('IntersectionObserver' in window)) {
        items.forEach((item) => item.classList.add('is-visible'));

        return;
    }

    const observer = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
            if (entry.isIntersecting) {
                entry.target.classList.add('is-visible');
                observer.unobserve(entry.target);
            }
        });
    }, { threshold: 0.12, rootMargin: '0px 0px -40px 0px' });

    items.forEach((item) => observer.observe(item));
}

function initCarousel(root) {
    const track = root.querySelector('[data-carousel-track]');
    const pageSlides = [...root.querySelectorAll('[data-carousel-slide]')];
    const cardSlides = [...root.querySelectorAll('[data-carousel-card]')];
    // Di HP halaman berisi 6 kartu dilepas (display: contents); yang digeser jadi kartunya.
    const items = () => (pageSlides.length > 0 && getComputedStyle(pageSlides[0]).display === 'contents' ? cardSlides : pageSlides);
    const prev = root.querySelector('[data-carousel-prev]');
    const next = root.querySelector('[data-carousel-next]');
    const dots = root.querySelector('[data-carousel-dots]');
    const count = root.querySelector('[data-carousel-count]');

    if (!track || pageSlides.length === 0) {
        return;
    }

    const step = () => items()[0].getBoundingClientRect().width + parseFloat(getComputedStyle(track).columnGap || '0');
    const perView = () => Math.max(1, Math.round(track.clientWidth / step()));
    const pages = () => Math.max(1, items().length - perView() + 1);
    const current = () => Math.min(pages() - 1, Math.round(track.scrollLeft / step()));
    const goTo = (index) => track.scrollTo({ left: index * step(), behavior: 'smooth' });

    const renderDots = () => {
        dots.replaceChildren(...Array.from({ length: pages() }, (_, index) => {
            const dot = document.createElement('button');
            dot.type = 'button';
            dot.className = 'carousel__dot';
            dot.setAttribute('aria-label', `Kartu ${index + 1}`);
            dot.addEventListener('click', () => goTo(index));

            return dot;
        }));
    };

    const update = () => {
        const index = current();
        [...dots.children].forEach((dot, dotIndex) => dot.classList.toggle('is-active', dotIndex === index));
        count.textContent = `${index + 1} / ${pages()}`;
        prev.disabled = index === 0;
        next.disabled = index >= pages() - 1;
        // Semua kartu muat di satu halaman (mis. 2–3 RT di layar lebar): tanpa titik dan "1 / 1".
        if (count.parentElement) {
            count.parentElement.hidden = pages() <= 1;
        }
    };

    prev.addEventListener('click', () => goTo(current() - 1));
    next.addEventListener('click', () => goTo(current() + 1));
    track.addEventListener('scroll', () => requestAnimationFrame(update), { passive: true });
    track.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowRight') {
            event.preventDefault();
            goTo(current() + 1);
        } else if (event.key === 'ArrowLeft') {
            event.preventDefault();
            goTo(current() - 1);
        }
    });

    // Bergeser sendiri pelan-pelan; berhenti saat disentuh/diarahkan mouse atau bila pengguna mengurangi animasi.
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    let paused = false;
    ['mouseenter', 'focusin', 'touchstart'].forEach((type) => root.addEventListener(type, () => { paused = true; }, { passive: true }));
    root.addEventListener('mouseleave', () => { paused = false; });

    // "Pilih RT Anda" tepat di atas carousel: lompat ke kartu RT itu (di laptop ke halaman berisi kartunya),
    // sorot sebentar, lalu sematkan di bawah kartu RW. Sematan diingat di perangkat ini; link #rt-03 juga menyematkan.
    const picker = root.previousElementSibling?.matches('[data-rt-picker]') ? root.previousElementSibling : null;
    const pinned = picker?.previousElementSibling?.matches('[data-rt-pinned]') ? picker.previousElementSibling : null;

    if (picker) {
        const perPage = Number(root.dataset.perPage || 1);
        const chips = [...picker.querySelectorAll('[data-rt-jump]')];

        const jump = (index) => {
            paused = true;
            goTo(items() === cardSlides || perPage === 1 ? index : Math.floor(index / perPage));
            cardSlides.forEach((card, cardIndex) => card.classList.toggle('is-target', cardIndex === index));
            setTimeout(() => cardSlides[index]?.classList.remove('is-target'), 2500);
        };

        const button = (label, onClick) => {
            const element = document.createElement('button');
            element.type = 'button';
            element.className = 'rt-pinned__action';
            element.textContent = label;
            element.addEventListener('click', () => onClick(element));

            return element;
        };

        const render = (code) => {
            const chip = chips.find((item) => sameRt(item.dataset.rtCode, code));
            chips.forEach((item) => item.classList.toggle('is-active', item === chip));

            if (chip) {
                // Di HP deretan tombol bisa lebih lebar dari layar: geser agar RT terpilih terlihat.
                picker.scrollTo({ left: Math.max(0, chip.offsetLeft - picker.offsetLeft - 80), behavior: 'smooth' });
            }

            if (!pinned) {
                return;
            }

            const card = cardSlides.find((item) => sameRt(item.dataset.rt, code))?.querySelector('.race');

            if (!chip || !card) {
                pinned.hidden = true;
                pinned.replaceChildren();

                return;
            }

            const head = document.createElement('div');
            head.className = 'rt-pinned__head';
            const title = document.createElement('span');
            title.className = 'rt-pinned__title';
            title.textContent = `📌 RT Anda: ${chip.textContent.trim()}`;
            const link = `${window.location.origin}${window.location.pathname}#rt-${code}`;
            const copy = button('Salin link RT ini', (element) => {
                const done = () => { element.textContent = 'Link disalin ✓'; };
                (navigator.clipboard?.writeText(link) ?? Promise.reject()).then(done, () => window.prompt('Salin link ini:', link));
            });
            const unpin = button('Lepas', () => pin(null));
            head.append(title, copy, unpin);
            pinned.replaceChildren(head, card.cloneNode(true));
            pinned.hidden = false;
        };

        chips.forEach((chip) => chip.addEventListener('click', () => {
            jump(Number(chip.dataset.rtJump));
            pin(chip.dataset.rtCode);
        }));

        // Satu sematan untuk semua kartu di halaman (quick count dan hasil resmi).
        window.addEventListener('rt-pin', (event) => render(event.detail));
        render(pinnedRt());
    }

    if (!reduceMotion && pages() > 1) {
        setInterval(() => {
            if (!paused && !document.hidden) {
                goTo(current() >= pages() - 1 ? 0 : current() + 1);
            }
        }, 6000);
    }

    window.addEventListener('resize', () => {
        renderDots();
        update();
    });

    renderDots();
    update();
}

/* RT yang disematkan warga: dari link #rt-03 (dibagikan Admin RT), atau yang terakhir dipilih di perangkat ini. */
const pinStorageKey = 'rtrw.pinnedRt';

/* Kode RT dibandingkan tanpa nol di depan: link #rt-3 dan #rt-03 sama-sama menyematkan RT 03. */
function sameRt(a, b) {
    const normalize = (value) => String(value ?? '').replace(/^0+(?=.)/, '');

    return a != null && b != null && normalize(a) === normalize(b);
}

function pinnedRt() {
    const fromLink = window.location.hash.match(/^#rt-([\w-]+)$/)?.[1];

    if (fromLink) {
        try { localStorage.setItem(pinStorageKey, fromLink); } catch {}

        return fromLink;
    }

    try { return localStorage.getItem(pinStorageKey); } catch { return null; }
}

function pin(code) {
    try {
        if (code) {
            localStorage.setItem(pinStorageKey, code);
        } else {
            localStorage.removeItem(pinStorageKey);
        }
    } catch {}

    // Link #rt-.. di alamat ikut diganti/dihapus, agar saat halaman dimuat ulang tidak kembali ke RT dari link lama.
    if (window.location.hash.startsWith('#rt-')) {
        history.replaceState(null, '', window.location.pathname + window.location.search + (code ? `#rt-${code}` : ''));
    }

    window.dispatchEvent(new CustomEvent('rt-pin', { detail: code }));
}

initReveal();
document.querySelectorAll('[data-carousel]').forEach(initCarousel);

// Link #rt-.. dibuka saat portal sudah terbuka (halaman tidak dimuat ulang): sematkan RT itu juga.
window.addEventListener('hashchange', () => {
    const fromLink = window.location.hash.match(/^#rt-([\w-]+)$/)?.[1];

    if (fromLink) {
        pin(fromLink);
    }
});

/* Angka berhitung naik saat terlihat (format Indonesia: koma desimal). */
function countUp(element) {
    const target = Number(element.dataset.countTo);
    const decimals = String(element.dataset.countTo).includes('.') ? 1 : 0;
    const started = performance.now();

    const step = (now) => {
        const progress = Math.min(1, (now - started) / 1300);
        const eased = 1 - (1 - progress) ** 3;
        element.textContent = (target * eased).toFixed(decimals).replace('.', ',');

        if (progress < 1) {
            requestAnimationFrame(step);
        }
    };

    requestAnimationFrame(step);
}

function initCountUp() {
    const items = [...document.querySelectorAll('[data-count-to]')];

    if (!('IntersectionObserver' in window) || window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        return;
    }

    const observer = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
            if (entry.isIntersecting) {
                countUp(entry.target);
                observer.unobserve(entry.target);
            }
        });
    }, { threshold: 0.5 });

    items.forEach((item) => observer.observe(item));
}

/* Portal satu halaman: menu aktif mengikuti bagian yang sedang dibaca. */
function initScrollSpy() {
    const nav = document.querySelector('[data-scrollspy]');

    if (!nav || !('IntersectionObserver' in window)) {
        return;
    }

    const tabs = [...nav.querySelectorAll('[data-section]')];
    const sections = tabs.map((tab) => document.getElementById(tab.dataset.section)).filter(Boolean);

    if (sections.length === 0) {
        return;
    }

    const activate = (id) => {
        tabs.forEach((tab) => {
            const active = tab.dataset.section === id;
            tab.classList.toggle('is-active', active);

            if (active) {
                tab.setAttribute('aria-current', 'location');
                tab.scrollIntoView({ block: 'nearest', inline: 'center', behavior: 'smooth' });
            } else {
                tab.removeAttribute('aria-current');
            }
        });
    };

    const visible = new Map();
    const observer = new IntersectionObserver((entries) => {
        entries.forEach((entry) => visible.set(entry.target.id, entry.isIntersecting ? entry.intersectionRatio : 0));
        const current = sections.find((section) => (visible.get(section.id) ?? 0) > 0);

        if (current) {
            activate(current.id);
        }
    }, { rootMargin: '-80px 0px -55% 0px', threshold: [0, 0.01] });

    sections.forEach((section) => observer.observe(section));
}

initCountUp();
initScrollSpy();

/*
 * Data terbaru tanpa menekan refresh: tiap 60 detik halaman ini diambil ulang di latar belakang; bila isinya berubah
 * (mis. pemilihan dimulai atau hasil diumumkan), halaman dimuat ulang. Dilewati saat tab tidak sedang dilihat.
 */
function initAutoRefresh() {
    if (!('fetch' in window)) {
        return;
    }

    const intervalMs = 60000;
    let baseline = null;

    const check = async () => {
        // Jangan muat ulang saat warga sedang membaca jendela visi & misi.
        if (document.visibilityState !== 'visible' || document.querySelector('dialog[open]')) {
            return;
        }

        try {
            const response = await fetch(window.location.href, { cache: 'no-store', headers: { Accept: 'text/html' } });

            if (!response.ok) {
                return;
            }

            const html = await response.text();

            if (baseline !== null && html !== baseline) {
                window.location.reload();

                return;
            }

            baseline = html;
        } catch {
            // Jaringan putus sebentar: coba lagi di putaran berikutnya.
        }
    };

    check();
    setInterval(check, intervalMs);
}

initAutoRefresh();

/*
 * Jendela visi & misi: tombol "Detail" di kartu calon/quick count. Lewat delegasi agar kartu RT yang disematkan
 * (salinan kartu) ikut berfungsi. Klik di luar jendela atau tombol Tutup menutupnya.
 */
document.addEventListener('click', (event) => {
    const opener = event.target.closest('[data-profile-open]');

    if (opener) {
        event.preventDefault();
        document.getElementById(opener.dataset.profileOpen)?.showModal();

        return;
    }

    if (event.target.closest('[data-profile-close]')) {
        event.target.closest('dialog')?.close();

        return;
    }

    if (event.target instanceof HTMLDialogElement && event.target.classList.contains('profile-dialog')) {
        event.target.close();
    }
});
