/*
 * Tab proyektor hasil: pengumuman bertahap (peringkat dibuka dari bawah), bar tumbuh, angka berhitung,
 * podium + confetti di akhir. Tanpa skrip inline (CSP ketat); gaya diatur lewat CSSOM, bukan atribut style.
 */

const COLORS = ['#4b44c4', '#0f8f86', '#d6303a', '#f59e0b', '#2453d6', '#16a34a'];

function confetti() {
    for (let i = 0; i < 160; i++) {
        const piece = document.createElement('div');
        piece.setAttribute('aria-hidden', 'true');
        Object.assign(piece.style, {
            position: 'fixed',
            top: '-20px',
            left: `${Math.random() * 100}vw`,
            width: `${6 + Math.random() * 8}px`,
            height: `${10 + Math.random() * 10}px`,
            background: COLORS[i % COLORS.length],
            zIndex: '9999',
            pointerEvents: 'none',
            borderRadius: '2px',
        });
        document.body.appendChild(piece);

        const drift = (Math.random() - 0.5) * 200;
        const spin = 360 + Math.random() * 720;
        piece.animate(
            [{ transform: 'translateY(0) rotate(0deg)' }, { transform: `translateY(110vh) translateX(${drift}px) rotate(${spin}deg)` }],
            { duration: 2500 + Math.random() * 2500, delay: Math.random() * 600, easing: 'cubic-bezier(.2,.6,.4,1)' },
        ).onfinish = () => piece.remove();
    }
}

function animateRow(row) {
    const bar = row.querySelector('[data-bar]');
    const count = row.querySelector('[data-count]');
    const votes = Number(row.dataset.votes);
    const started = performance.now();

    bar.style.width = '0%';
    requestAnimationFrame(() => setTimeout(() => {
        bar.style.width = `${row.dataset.width}%`;
    }, 60));

    const step = (now) => {
        const progress = Math.min(1, (now - started) / 1200);
        count.textContent = String(Math.round(votes * progress));

        if (progress < 1) {
            requestAnimationFrame(step);
        }
    };
    requestAnimationFrame(step);
}

function setupBlock(block) {
    const total = Number(block.dataset.total);
    const hasVotes = Number(block.dataset.top) > 0;
    const rows = [...block.querySelectorAll('[data-row]')];
    const next = block.querySelector('[data-next]');
    const showAll = block.querySelector('[data-show-all]');
    const restart = block.querySelector('[data-restart]');
    const hint = block.querySelector('[data-hint]');
    const podium = block.querySelector('[data-podium]');
    let shown = 0;

    const isShown = (index) => shown > total - 1 - index;

    const render = (animateFrom) => {
        rows.forEach((row) => {
            const index = Number(row.dataset.index);
            const visible = isShown(index);
            const wasVisible = animateFrom !== null && animateFrom > total - 1 - index;
            row.classList.toggle('hidden', !visible);

            if (visible && !wasVisible) {
                animateRow(row);
            }
        });

        const done = shown >= total;
        next.classList.toggle('hidden', done);
        showAll.classList.toggle('hidden', done);
        restart.classList.toggle('hidden', shown === 0);
        hint.classList.toggle('hidden', shown > 0);
        next.textContent = shown === 0 ? 'Mulai pengumuman' : (shown === total - 1 ? 'Tampilkan peringkat teratas' : 'Berikutnya');

        if (podium) {
            podium.classList.toggle('hidden', !done);
        }
    };

    next.addEventListener('click', () => {
        if (shown >= total) {
            return;
        }

        const before = shown;
        shown++;
        render(before);

        if (shown === total && hasVotes) {
            confetti();
        }
    });

    showAll.addEventListener('click', () => {
        const before = shown;
        shown = total;
        render(before);
    });

    restart.addEventListener('click', () => {
        shown = 0;
        render(0);
    });

    render(null);

    return { isDone: () => shown >= total, next: () => next.click() };
}

const blocks = [...document.querySelectorAll('[data-block]')].map(setupBlock);

// Spasi / panah kanan = "Berikutnya" pada blok pertama yang belum selesai (praktis saat layar penuh).
document.addEventListener('keydown', (event) => {
    if (event.key !== ' ' && event.key !== 'ArrowRight') {
        return;
    }

    const current = blocks.find((block) => !block.isDone());

    if (current) {
        event.preventDefault();
        current.next();
    }
});

const fullscreenButton = document.querySelector('[data-fullscreen]');

if (fullscreenButton && document.documentElement.requestFullscreen) {
    fullscreenButton.addEventListener('click', () => {
        document.documentElement.requestFullscreen().catch(() => {});
    });
} else if (fullscreenButton) {
    fullscreenButton.classList.add('hidden');
}
