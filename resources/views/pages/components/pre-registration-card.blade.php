@props([
    'token' => '',
    'name' => '',
    'company' => '',
    'purpose' => '',
    'date' => '',
])

@if ($token)
    <div
        x-data="{
            downloading: false,
            async downloadCard() {
                if (this.downloading) return;
                this.downloading = true;
                try {
                    const card = this.$refs.card;
                    const getText = (selector) => (card.querySelector(selector)?.textContent || '').trim();
                    const fontFamily = getComputedStyle(card).fontFamily || 'system-ui, sans-serif';
                    const width = 340;
                    const height = 480;
                    const dpr = Math.max(1, window.devicePixelRatio || 1);
                    const canvas = document.createElement('canvas');
                    canvas.width = width * dpr;
                    canvas.height = height * dpr;
                    const ctx = canvas.getContext('2d');
                    ctx.scale(dpr, dpr);

                    ctx.fillStyle = '#ffffff';
                    ctx.fillRect(0, 0, width, height);
                    ctx.textAlign = 'center';
                    ctx.textBaseline = 'alphabetic';

                    ctx.fillStyle = '#a3a3a3';
                    ctx.font = '600 12px ' + fontFamily;
                    ctx.fillText(getText('.card-system').toUpperCase(), width / 2, 46);

                    ctx.fillStyle = '#171717';
                    ctx.font = 'bold 21px ' + fontFamily;
                    ctx.fillText(getText('.card-title'), width / 2, 76);

                    ctx.strokeStyle = '#e5e5e5';
                    ctx.lineWidth = 1;
                    ctx.beginPath();
                    ctx.moveTo(24, 96);
                    ctx.lineTo(width - 24, 96);
                    ctx.stroke();

                    let y = 138;
                    ctx.fillStyle = '#171717';
                    ctx.font = '600 17px ' + fontFamily;
                    ctx.fillText(getText('.card-name'), width / 2, y);
                    y += 27;

                    const companyText = getText('.card-company');
                    const purposeText = getText('.card-purpose');
                    const dateText = getText('.card-date');
                    ctx.font = '14px ' + fontFamily;
                    if (companyText) { ctx.fillStyle = '#737373'; ctx.fillText(companyText, width / 2, y); y += 23; }
                    if (purposeText) { ctx.fillStyle = '#737373'; ctx.fillText(purposeText, width / 2, y); y += 23; }
                    if (dateText) { ctx.fillStyle = '#a3a3a3'; ctx.font = '12px ' + fontFamily; ctx.fillText(dateText, width / 2, y); y += 23; }

                    const img = new Image();
                    img.src = this.$refs.qrImg.src;
                    await new Promise((resolve, reject) => {
                        img.onload = resolve;
                        img.onerror = () => reject(new Error('QR image failed to load'));
                    });
                    const qrSize = 196;
                    ctx.drawImage(img, (width - qrSize) / 2, 218, qrSize, qrSize);

                    ctx.fillStyle = '#a3a3a3';
                    ctx.font = '11px ' + fontFamily;
                    ctx.fillText(getText('.card-footer'), width / 2, 448);

                    const systemSlug = getText('.card-system').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '') || 'app';
                    const safeName = getText('.card-name').replace(/[^a-z0-9]+/gi, '-').replace(/^-+|-+$/g, '') || 'visitor';
                    const link = document.createElement('a');
                    link.download = systemSlug + '-pre-registration-' + safeName + '.png';
                    link.href = canvas.toDataURL('image/png');
                    link.click();
                } catch (e) {
                    alert('{{ __("Could not download the card") }}: ' + (e.message || e));
                } finally {
                    this.downloading = false;
                }
            }
        }"
        class="mx-auto inline-block"
    >
        {{-- Downloadable card (always light theme for clean export) --}}
        <div x-ref="card" class="w-full max-w-[320px] rounded-2xl border border-neutral-200 bg-white text-center text-neutral-900 shadow-sm">
            <div class="border-b border-neutral-100 px-6 py-4">
                <p class="card-system text-[11px] font-semibold uppercase tracking-[0.2em] text-neutral-400">{{ config('app.name') }}</p>
                <p class="card-title mt-1 text-lg font-bold">{{ __('Pre-Registration Pass') }}</p>
            </div>

            <div class="space-y-1 px-6 py-4">
                <p class="card-name text-base font-semibold">{{ $name }}</p>
                @if ($company)
                    <p class="card-company text-sm text-neutral-500">{{ $company }}</p>
                @endif
                @if ($purpose)
                    <p class="card-purpose text-sm text-neutral-500">{{ $purpose }}</p>
                @endif
                @if ($date)
                    <p class="card-date text-xs text-neutral-400">{{ $date }}</p>
                @endif
            </div>

            <div class="border-t border-neutral-100 px-6 pb-5 pt-4">
                <img x-ref="qrImg" src="{{ route('qr.code', $token) }}" alt="{{ __('Pre-Registration QR Code') }}" class="mx-auto h-48 w-48">
                <p class="card-footer mt-3 text-[11px] text-neutral-400">{{ __('Scan at the kiosk to check in') }}</p>
            </div>
        </div>

        <div class="mt-4 flex justify-center">
            <flux:button variant="primary" x-on:click="downloadCard()" x-bind:disabled="downloading">
                {{ __('Download Card') }}
            </flux:button>
        </div>
    </div>
@endif
