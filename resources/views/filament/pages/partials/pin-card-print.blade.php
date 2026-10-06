{{--
    Tombol "Cetak kartu PIN" untuk printer thermal (58/80 mm) atau printer biasa.
    Kartu dicetak dari iframe sementara di browser; PIN tidak disimpan di server dan tidak masuk riwayat.
    Parameter: $card = ['name' => ..., 'number' => ..., 'pin' => ..., 'election' => ..., 'note' => ?string]
--}}
<div
    x-data="{
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
>
    <template x-ref="card">
        <style>
            @page { margin: 3mm; }
            * { box-sizing: border-box; }
            body { margin: 0; font-family: Arial, Helvetica, sans-serif; color: #000; background: #fff; }
            .card { width: 100%; max-width: 72mm; margin: 0 auto; text-align: center; }
            .site { font-size: 11pt; font-weight: bold; }
            .election { font-size: 10pt; margin-top: 1mm; }
            .rule { border-top: 1px dashed #000; margin: 3mm 0; }
            .name { font-size: 15pt; font-weight: bold; word-wrap: break-word; }
            .number { font-size: 11pt; margin-top: 1mm; }
            .label { font-size: 10pt; font-weight: bold; letter-spacing: 1mm; margin-top: 3mm; }
            .pin { font-family: 'Courier New', monospace; font-size: 40pt; font-weight: 900; letter-spacing: 3mm; line-height: 1.1; padding-left: 3mm; }
            .note { font-size: 10pt; font-weight: bold; margin-top: 2mm; }
            ol { text-align: left; font-size: 10pt; margin: 0; padding-left: 5mm; }
            ol li { margin-bottom: 1mm; }
            .secret { font-size: 10pt; font-weight: bold; margin-top: 2mm; }
            .time { font-size: 8pt; margin-top: 3mm; }
        </style>
        <div class="card">
            <div class="site">{{ config('app.name') }}</div>
            <div class="election">{{ $card['election'] }}</div>
            <div class="rule"></div>
            <div class="name">{{ $card['name'] }}</div>
            <div class="number">No. hadir {{ $card['number'] }}</div>
            <div class="label">PIN</div>
            <div class="pin">{{ $card['pin'] }}</div>
            @if (filled($card['note'] ?? null))
                <div class="note">{{ $card['note'] }}</div>
            @endif
            <div class="rule"></div>
            <ol>
                <li>Scan QR di layar panitia dengan HP.</li>
                <li>Ketik nama Anda, sentuh nama Anda.</li>
                <li>Masukkan PIN di atas, lalu pilih calon.</li>
            </ol>
            <div class="secret">PIN ini rahasia. Jangan berikan ke orang lain.</div>
            <div class="time">Dicetak {{ now()->format('d/m/Y H:i') }}</div>
        </div>
    </template>

    <x-filament::button type="button" size="xl" color="gray" icon="heroicon-o-printer" x-on:click="print()" class="w-full">
        Cetak kartu PIN
    </x-filament::button>
</div>
