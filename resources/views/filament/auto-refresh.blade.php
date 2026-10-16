{{--
    Pembaruan otomatis semua halaman panel: tiap 10 detik komponen Livewire di area utama (halaman, widget, tabel,
    relation manager) diambil ulang, agar beberapa panitia yang membuka halaman yang sama melihat data terbaru.
    Dilewati selama ada yang sedang dikerjakan: mengetik, jendela/menu terbuka, berkas sedang dipilih, atau tab tidak
    dilihat. Halaman yang sudah punya wire:poll sendiri ditandai data-auto-refresh="off".
--}}
<script>
    (() => {
        const intervalMs = 10000;
        const visible = (element) => element.offsetParent !== null || element.getClientRects().length > 0;

        const isBusy = () => {
            if (document.visibilityState !== 'visible') {
                return true;
            }

            const active = document.activeElement;

            if (active && active.matches('input, textarea, select, [contenteditable="true"]')) {
                return true;
            }

            return document.querySelector('.fi-modal-open, .filepond--item') !== null
                || [...document.querySelectorAll('.fi-dropdown-panel')].some(visible);
        };

        setInterval(() => {
            if (! window.Livewire || isBusy()) {
                return;
            }

            window.Livewire.all()
                .filter((component) => component.el.closest('.fi-main') && ! component.el.querySelector('[data-auto-refresh="off"]'))
                .forEach((component) => component.$wire.$refresh());
        }, intervalMs);
    })();
</script>
