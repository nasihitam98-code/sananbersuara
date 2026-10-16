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

initReveal();
document.querySelectorAll('[data-carousel]').forEach(initCarousel);

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
        if (document.visibilityState !== 'visible') {
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
