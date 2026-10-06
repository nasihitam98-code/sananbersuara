/* Tombol cetak berita acara (tanpa skrip inline, sesuai CSP). */
document.querySelectorAll('[data-print]').forEach((button) => {
    button.addEventListener('click', () => window.print());
});
