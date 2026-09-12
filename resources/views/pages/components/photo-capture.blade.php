@props(['model', 'label', 'hint' => null, 'title' => null])
<div x-data="{
    photo: '',
    captureModal: false,
    captureMode: 'camera',
    cameraActive: false,
    videoReady: false,
    stream: null,
    init() { this.photo = $wire.get('{{ $model }}') || ''; },
    openCapture() {
        this.captureMode = 'camera';
        this.captureModal = true;
        this.$nextTick(() => this.startCamera());
    },
    closeCapture() {
        this.stopCamera();
        this.captureModal = false;
    },
    async startCamera() {
        this.stopCamera();
        try {
            this.stream = await navigator.mediaDevices.getUserMedia({ video: { width: 640, height: 480, facingMode: 'user' } });
            const video = this.$refs.captureVideo;
            video.srcObject = this.stream;
            video.onloadedmetadata = () => { video.play(); this.videoReady = true; };
            this.cameraActive = true;
        } catch (e) {
            this.cameraActive = false;
            this.captureMode = 'upload';
        }
    },
    stopCamera() {
        if (this.stream) { this.stream.getTracks().forEach(t => t.stop()); this.stream = null; }
        this.cameraActive = false;
        this.videoReady = false;
    },
    downscale(dataUrl) {
        return new Promise((resolve) => {
            const img = new Image();
            img.onload = () => {
                const max = 1024;
                let width = img.width;
                let height = img.height;
                if (width > max || height > max) {
                    const scale = max / Math.max(width, height);
                    width = Math.round(width * scale);
                    height = Math.round(height * scale);
                }
                const canvas = document.createElement('canvas');
                canvas.width = width;
                canvas.height = height;
                canvas.getContext('2d').drawImage(img, 0, 0, width, height);
                resolve(canvas.toDataURL('image/jpeg', 0.82));
            };
            img.onerror = () => resolve(dataUrl);
            img.src = dataUrl;
        });
    },
    async capture() {
        const video = this.$refs.captureVideo;
        const canvas = this.$refs.captureCanvas;
        const side = Math.min(video.videoWidth || 640, video.videoHeight || 480);
        canvas.width = side;
        canvas.height = side;
        canvas.getContext('2d').drawImage(
            video,
            (video.videoWidth - side) / 2, (video.videoHeight - side) / 2, side, side,
            0, 0, side, side
        );
        this.finish(await this.downscale(canvas.toDataURL('image/jpeg', 0.8)));
    },
    handleFile(event) {
        const file = event.target.files[0];
        if (!file) return;
        const reader = new FileReader();
        reader.onload = async (ev) => this.finish(await this.downscale(ev.target.result));
        reader.readAsDataURL(file);
        event.target.value = '';
    },
    finish(dataUrl) {
        this.photo = dataUrl;
        $wire.set('{{ $model }}', dataUrl);
        this.closeCapture();
    },
    clear() {
        this.photo = '';
        $wire.set('{{ $model }}', '');
    },
}">
    <p class="text-sm font-medium text-neutral-700 dark:text-neutral-300">{{ $label }}</p>
    <button type="button" x-on:click="openCapture()" class="mt-1 flex w-full items-center gap-3 rounded-xl border border-neutral-200 bg-white px-3 py-2.5 text-left transition-colors hover:border-neutral-300 hover:bg-neutral-50 dark:border-neutral-700 dark:bg-neutral-900 dark:hover:border-neutral-600">
        <span class="flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded-full bg-neutral-100 text-neutral-400 dark:bg-neutral-800 dark:text-neutral-500">
            <svg x-show="! photo" class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
            <img x-show="photo" :src="photo" alt="" class="h-full w-full object-cover">
        </span>
        <span class="min-w-0 flex-1">
            <span class="block text-sm font-medium text-neutral-900 dark:text-white">{{ __('Capture Photo') }}</span>
            @if ($hint)
                <span x-show="! photo" class="block truncate text-xs text-neutral-400">{{ $hint }}</span>
            @endif
            <span x-show="photo" class="block truncate text-xs font-medium text-emerald-600 dark:text-emerald-400">{{ __('Photo added — tap to retake') }}</span>
        </span>
        <span class="inline-flex shrink-0 items-center gap-0.5 text-xs font-medium text-emerald-600 dark:text-emerald-400">{{ __('Open camera') }}<svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg></span>
    </button>
    <button type="button" x-show="photo" x-on:click="clear()" class="mt-1 text-xs text-neutral-400 hover:text-neutral-600 dark:hover:text-neutral-300">{{ __('Remove photo') }}</button>

    <div x-show="captureModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4" x-on:click.self="closeCapture()">
        <div class="w-full max-w-xl overflow-hidden rounded-2xl bg-white shadow-2xl dark:bg-neutral-900" x-on:click.stop>
            <div class="flex items-center justify-between px-4 py-3">
                <p class="text-sm font-semibold text-neutral-900 dark:text-white">{{ $title ?? $label }}</p>
                <button type="button" x-on:click="closeCapture()" class="flex h-8 w-8 items-center justify-center rounded-full text-neutral-400 transition-colors hover:bg-neutral-100 hover:text-neutral-600 dark:hover:bg-neutral-800 dark:hover:text-neutral-300" aria-label="{{ __('Close') }}">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <div class="px-4">
                <div class="flex rounded-full bg-neutral-100 p-1 text-xs font-medium dark:bg-neutral-800">
                    <button type="button" x-on:click="captureMode = 'camera'; $nextTick(() => startCamera())" :class="captureMode === 'camera' ? 'bg-white text-neutral-900 shadow-sm dark:bg-neutral-700 dark:text-white' : 'text-neutral-500 dark:text-neutral-400'" class="flex-1 rounded-full px-3 py-1.5 transition-colors">{{ __('Camera') }}</button>
                    <button type="button" x-on:click="captureMode = 'upload'; stopCamera()" :class="captureMode === 'upload' ? 'bg-white text-neutral-900 shadow-sm dark:bg-neutral-700 dark:text-white' : 'text-neutral-500 dark:text-neutral-400'" class="flex-1 rounded-full px-3 py-1.5 transition-colors">{{ __('Upload') }}</button>
                </div>
            </div>
            <div class="p-4">
                <div x-show="captureMode === 'camera'">
                    <div class="overflow-hidden rounded-xl bg-black">
                        <video x-ref="captureVideo" autoplay playsinline class="aspect-square w-full object-cover"></video>
                    </div>
                    <p x-show="! cameraActive" class="mt-2 text-center text-xs text-neutral-400">{{ __('Camera unavailable — upload a photo instead.') }}</p>
                    <div class="mt-3 flex gap-2">
                        <flux:button variant="ghost" x-on:click="closeCapture()">{{ __('Cancel') }}</flux:button>
                        <flux:button variant="primary" class="flex-1" x-on:click="capture()" x-bind:disabled="! videoReady">{{ __('Capture') }}</flux:button>
                    </div>
                </div>
                <div x-show="captureMode === 'upload'">
                    <label class="flex cursor-pointer flex-col items-center gap-1.5 rounded-xl border border-dashed border-neutral-300 bg-neutral-50 px-4 py-8 text-center transition-colors hover:border-neutral-400 dark:border-neutral-700 dark:bg-neutral-800/50 dark:hover:border-neutral-600">
                        <svg class="h-8 w-8 text-neutral-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5"/></svg>
                        <span class="text-sm font-medium text-neutral-700 dark:text-neutral-300">{{ __('Tap to choose a photo') }}</span>
                        <span class="text-xs text-neutral-400">JPG or PNG</span>
                        <input type="file" accept="image/*" class="hidden" x-on:change="handleFile">
                    </label>
                    <div class="mt-3 flex gap-2">
                        <flux:button variant="ghost" x-on:click="closeCapture()">{{ __('Cancel') }}</flux:button>
                        <flux:button variant="ghost" x-on:click="captureMode = 'camera'; $nextTick(() => startCamera())">{{ __('Use camera instead') }}</flux:button>
                    </div>
                </div>
                <canvas x-ref="captureCanvas" class="hidden"></canvas>
            </div>
        </div>
    </div>
</div>
