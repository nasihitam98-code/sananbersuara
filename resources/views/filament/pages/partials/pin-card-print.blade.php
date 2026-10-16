{{--
    Tombol "Cetak kartu PIN" untuk printer thermal 80 mm (muat juga di 58 mm) atau printer biasa.
    Kartu dicetak dari iframe sementara di browser; PIN tidak disimpan di server dan tidak masuk riwayat.
    Parameter: $card = ['name' => ..., 'number' => ..., 'pin' => ..., 'election' => ..., 'note' => ?string]
               $auto = true → langsung dicetak begitu kartu muncul (Meja Pintu), bisa dimatikan per laptop.
--}}
@php($auto = $auto ?? false)
<div
    {{-- Jangan pakai @if di dalam tag ini: Livewire menyisipkan penanda komentar di setiap @if dan tag jadi rusak. --}}
    x-data="{
        autoMode: {{ $auto ? 'true' : 'false' }},
        auto: (() => { try { return localStorage.getItem('rtrw.autoPrint') !== '0'; } catch (e) { return true; } })(),
        remember() {
            try { localStorage.setItem('rtrw.autoPrint', this.auto ? '1' : '0'); } catch (e) {}
        },
        print() {
            const frame = document.createElement('iframe');
            frame.setAttribute('aria-hidden', 'true');
            frame.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;';
            document.body.appendChild(frame);
            const doc = frame.contentWindow.document;
            doc.open();
            doc.write('<!doctype html><html><head><meta charset=utf-8><title>Kartu PIN</title></head><body>' + this.$refs.card.innerHTML + '</body></html>');
            doc.close();
            setTimeout(() => {
                frame.contentWindow.addEventListener('afterprint', () => frame.remove());
                frame.contentWindow.focus();
                frame.contentWindow.print();
            }, 150);
        },
    }"
    x-init="if (autoMode && auto) { $nextTick(() => print()); }"
>
    <template x-ref="card">
        {{-- Struk ringkas (keputusan panitia): nama + PIN. No. hadir kecil tetap dicetak karena HP menampilkannya
             di samping nama untuk membedakan nama kembar. --}}
        <style>
            @page { margin: 3mm; }
            * { box-sizing: border-box; }
            body { margin: 0; font-family: Arial, Helvetica, sans-serif; color: #000; background: #fff; }
            .card { width: 100%; max-width: 72mm; margin: 0 auto; text-align: center; }
            .site { font-size: 11pt; font-weight: bold; }
            .rule { border-top: 1px dashed #000; margin: 3mm 0; }
            .name { font-size: 18pt; font-weight: bold; line-height: 1.15; word-wrap: break-word; }
            .number { font-size: 9pt; margin-top: 1mm; }
            .label { font-size: 10pt; font-weight: bold; letter-spacing: 1mm; margin-top: 4mm; }
            .pin { font-family: 'Courier New', monospace; font-size: 46pt; font-weight: 900; letter-spacing: 3mm; line-height: 1.1; padding-left: 3mm; }
            .note { font-size: 10pt; font-weight: bold; margin-top: 2mm; }
            .secret { font-size: 10pt; font-weight: bold; }
        </style>
        <div class="card">
            <div class="site">{{ config('app.name') }}</div>
            <div class="rule"></div>
            <div class="name">{{ $card['name'] }}</div>
            <div class="number">No. hadir {{ $card['number'] }}</div>
            <div class="label">PIN</div>
            <div class="pin">{{ $card['pin'] }}</div>
            @if (filled($card['note'] ?? null))
                <div class="note">{{ $card['note'] }}</div>
            @endif
            <div class="rule"></div>
            <div class="secret">PIN ini rahasia.</div>
        </div>
    </template>

    <x-filament::button type="button" size="xl" color="gray" icon="heroicon-o-printer" x-on:click="print()" class="w-full">
        {{ $auto ? 'Cetak ulang kartu PIN' : 'Cetak kartu PIN' }}
    </x-filament::button>

    @if ($auto)
        <label class="mt-2 inline-flex cursor-pointer items-center gap-2 text-sm text-gray-600 dark:text-gray-300">
            <input type="checkbox" x-model="auto" x-on:change="remember()" class="rounded border-gray-300 text-primary-600">
            Cetak otomatis setiap selesai mendaftarkan (diingat di laptop ini)
        </label>
    @endif
</div>
