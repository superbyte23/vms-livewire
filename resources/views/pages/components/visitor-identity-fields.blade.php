<p class="text-sm text-neutral-500 dark:text-neutral-400">{{ __('Not on the list? Enter your details to register.') }}</p>
                    <flux:input wire:model.live="firstname" label="{{ __('First Name') }}" type="text" required placeholder="{{ __('e.g. John') }}" />
                    <flux:input wire:model.live="middlename" label="{{ __('Middle Name') }}" type="text" placeholder="{{ __('e.g. Michael') }}" />
                    <flux:input wire:model.live="lastname" label="{{ __('Last Name') }}" type="text" required placeholder="{{ __('e.g. Doe') }}" />
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 sm:gap-4">
                        <flux:input wire:model.live="email" label="{{ __('Email') }}" type="email" placeholder="{{ __('e.g. john@example.com') }}" />
                        <flux:input wire:model.live="phone" label="{{ __('Phone or Mobile') }}" type="tel" placeholder="{{ __('e.g. +1 555-1234') }}" />
                    </div>
                    <flux:input wire:model.live="company" label="{{ __('Company') }}" placeholder="{{ __('e.g. Acme Corp') }}" />
                    <flux:textarea wire:model.live="address" label="{{ __('Address') }}" placeholder="{{ __('e.g. 123 Main St, Apt 4B') }}" rows="2" />
                <div x-data="{
                    profilePhoto: @entangle('profilePhoto'),
                    idPhoto: @entangle('validIdPhoto'),
                    captureModal: false,
                    captureTarget: null,
                    captureMode: 'camera',
                    cameraActive: false,
                    videoReady: false,
                    stream: null,
                    openCapture(target) {
                        this.captureTarget = target;
                        this.captureMode = 'camera';
                        this.captureModal = true;
                        this.$nextTick(() => this.startCamera());
                    },
                    closeCapture() {
                        this.stopCamera();
                        this.captureModal = false;
                        this.captureTarget = null;
                    },
                    async startCamera() {
                        this.stopCamera();
                        try {
                            this.stream = await navigator.mediaDevices.getUserMedia({ video: { width: 640, height: 480, facingMode: this.captureTarget === 'idPhoto' ? 'environment' : 'user' } });
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
                        if (this.captureTarget === 'idPhoto') { this.idPhoto = dataUrl; } else { this.profilePhoto = dataUrl; }
                        this.closeCapture();
                    },
                }">
                    <p class="text-sm font-medium text-neutral-700 dark:text-neutral-300">{{ __('Profile photo (optional)') }}</p>
                    <button type="button" x-on:click="openCapture('photo')" class="mt-1 flex w-full items-center gap-3 rounded-xl border border-neutral-200 bg-white px-3 py-2.5 text-left transition-colors hover:border-neutral-300 hover:bg-neutral-50 dark:border-neutral-700 dark:bg-neutral-900 dark:hover:border-neutral-600">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded-full bg-neutral-100 text-neutral-400 dark:bg-neutral-800 dark:text-neutral-500">
                            <svg x-show="! profilePhoto" class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                            <img x-show="profilePhoto" :src="profilePhoto" alt="" class="h-full w-full object-cover">
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="block text-sm font-medium text-neutral-900 dark:text-white">{{ __('Capture Photo') }}</span>
                            <span x-show="! profilePhoto" class="block truncate text-xs text-neutral-400">{{ __('Selfie for your visitor record') }}</span>
                            <span x-show="profilePhoto" class="block truncate text-xs font-medium text-emerald-600 dark:text-emerald-400">{{ __('Photo added — tap to retake') }}</span>
                        </span>
                        <span class="inline-flex shrink-0 items-center gap-0.5 text-xs font-medium text-emerald-600 dark:text-emerald-400">{{ __('Open camera') }}<svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg></span>
                    </button>
                    <button type="button" x-show="profilePhoto" x-on:click="profilePhoto = ''" class="mt-1 text-xs text-neutral-400 hover:text-neutral-600 dark:hover:text-neutral-300">{{ __('Remove photo') }}</button>

                    <p class="mt-3 text-sm font-medium text-neutral-700 dark:text-neutral-300">{{ __('Government ID photo (optional)') }}</p>
                    <button type="button" x-on:click="openCapture('idPhoto')" class="mt-1 flex w-full items-center gap-3 rounded-xl border border-neutral-200 bg-white px-3 py-2.5 text-left transition-colors hover:border-neutral-300 hover:bg-neutral-50 dark:border-neutral-700 dark:bg-neutral-900 dark:hover:border-neutral-600">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-neutral-100 text-neutral-400 dark:bg-neutral-800 dark:text-neutral-500">
                            <svg x-show="! idPhoto" class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M10 6H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V8a2 2 0 00-2-2h-5m-4 0V5a2 2 0 114 0v1m-4 0a2 2 0 104 0m-5 8a2 2 0 100-4 2 2 0 000 4zm0 0c1.306 0 2.417.835 2.83 2M9 14a3.001 3.001 0 00-2.83 2"/></svg>
                            <img x-show="idPhoto" :src="idPhoto" alt="" class="h-full w-full object-cover">
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="block text-sm font-medium text-neutral-900 dark:text-white">{{ __('Capture ID Photo') }}</span>
                            <span x-show="! idPhoto" class="block truncate text-xs text-neutral-400">{{ __('Photo of your ID card') }}</span>
                            <span x-show="idPhoto" class="block truncate text-xs font-medium text-emerald-600 dark:text-emerald-400">{{ __('Photo added — tap to retake') }}</span>
                        </span>
                        <span class="inline-flex shrink-0 items-center gap-0.5 text-xs font-medium text-emerald-600 dark:text-emerald-400">{{ __('Open camera') }}<svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg></span>
                    </button>
                    <button type="button" x-show="idPhoto" x-on:click="idPhoto = ''" class="mt-1 text-xs text-neutral-400 hover:text-neutral-600 dark:hover:text-neutral-300">{{ __('Remove photo') }}</button>

                    <div x-show="captureModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4" x-on:click.self="closeCapture()">
                        <div class="w-full max-w-xl overflow-hidden rounded-2xl bg-white shadow-2xl dark:bg-neutral-900" x-on:click.stop>
                            <div class="flex items-center justify-between px-4 py-3">
                                <p class="text-sm font-semibold text-neutral-900 dark:text-white" x-text="captureTarget === 'idPhoto' ? '{{ __('Government ID photo') }}' : '{{ __('Profile photo') }}'"></p>
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
