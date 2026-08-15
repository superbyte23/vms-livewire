<?php

use App\Models\PreRegistration;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Pre-Register Your Visit')] #[Layout('layouts::kiosk')] class extends Component {
    public string $name = '';
    public string $email = '';
    public string $phone = '';
    public string $company = '';
    public string $validIdPhoto = '';
    public string $purpose = '';
    public string $expectedDate = '';

    public function submit()
    {
        $this->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:20',
            'company' => 'nullable|string|max:255',
            'validIdPhoto' => 'nullable|string',
            'purpose' => 'nullable|string|max:255',
            'expectedDate' => 'nullable|date',
        ]);

        $preRegistration = PreRegistration::create([
            'name' => $this->name,
            'email' => $this->email ?: null,
            'phone' => $this->phone ?: null,
            'company' => $this->company ?: null,
            'valid_id_photo' => $this->validIdPhoto ?: null,
            'purpose' => $this->purpose ?: null,
            'expected_date' => $this->expectedDate ?: null,
            'status' => 'pending',
            'qr_code_token' => Str::random(32),
        ]);

        return redirect()->route('pre-register.complete', $preRegistration);
    }
}; ?>

<div class="mx-auto flex min-h-screen w-full max-w-xl flex-col justify-start px-4 py-5 sm:justify-center sm:px-6 sm:py-12">
    <a href="{{ route('home') }}" class="mb-4 inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-700 dark:text-neutral-400 dark:hover:text-neutral-300 sm:mb-8">
        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
        {{ __('Back to kiosk') }}
    </a>

    <div class="rounded-2xl border border-neutral-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 sm:p-8">
        <flux:heading size="xl">{{ __('Pre-Register Your Visit') }}</flux:heading>
            <p class="mt-2 text-sm text-neutral-500 dark:text-neutral-400">
                {{ __('Book your visit ahead of time so check-in at the kiosk is faster.') }}
            </p>

            <div class="mt-4 space-y-3 sm:mt-6 sm:space-y-4">
                <flux:input wire:model="name" label="{{ __('Full Name') }}" type="text" required placeholder="{{ __('e.g. John Doe') }}" />
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 sm:gap-4">
                    <flux:input wire:model="email" label="{{ __('Email') }}" type="email" placeholder="{{ __('e.g. john@example.com') }}" />
                    <flux:input wire:model="phone" label="{{ __('Phone') }}" type="tel" placeholder="{{ __('e.g. +1 555-1234') }}" />
                </div>
                <flux:input wire:model="company" label="{{ __('Company') }}" placeholder="{{ __('e.g. Acme Corp') }}" />

                <div x-data="{
                    idPhoto: @entangle('validIdPhoto'),
                    preview: '',
                    cameraActive: false,
                    videoReady: false,
                    stream: null,
                    handleFile(event) {
                        const file = event.target.files[0];
                        if (!file) return;
                        const reader = new FileReader();
                        reader.onload = (ev) => {
                            this.idPhoto = ev.target.result;
                            this.preview = ev.target.result;
                        };
                        reader.readAsDataURL(file);
                    },
                    async startCamera() {
                        try {
                            this.stream = await navigator.mediaDevices.getUserMedia({ video: { width: 640, height: 480, facingMode: 'environment' } });
                            const video = this.$refs.idVideo;
                            video.srcObject = this.stream;
                            video.onloadedmetadata = () => { video.play(); this.videoReady = true; };
                            this.cameraActive = true;
                        } catch (e) {
                            alert('Camera error: ' + e.message);
                        }
                    },
                    capture() {
                        const video = this.$refs.idVideo;
                        const canvas = this.$refs.idCanvas;
                        canvas.width = video.videoWidth || 640;
                        canvas.height = video.videoHeight || 480;
                        canvas.getContext('2d').drawImage(video, 0, 0);
                        this.idPhoto = canvas.toDataURL('image/jpeg', 0.8);
                        this.preview = this.idPhoto;
                        this.stopCamera();
                    },
                    stopCamera() {
                        if (this.stream) { this.stream.getTracks().forEach(t => t.stop()); this.stream = null; }
                        this.cameraActive = false;
                        this.videoReady = false;
                    },
                    remove() {
                        this.idPhoto = '';
                        this.preview = '';
                    },
                    destroy() { this.stopCamera(); }
                }">
                    <p class="text-sm font-medium text-neutral-700 dark:text-neutral-300">{{ __('Valid ID (photo, optional)') }}</p>
                    <div class="mt-1 rounded-lg border border-dashed border-neutral-300 bg-neutral-50 p-3 text-center dark:border-neutral-700 dark:bg-neutral-800/50 sm:p-4">
                        <div x-show="!cameraActive && !preview">
                            <svg class="mx-auto h-8 w-8 text-neutral-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                            <p class="mt-2 text-sm text-neutral-500 dark:text-neutral-400">{{ __('Take a photo of your ID card') }}</p>
                            <div class="mt-2.5 flex flex-wrap justify-center gap-2">
                                <flux:button variant="primary" size="sm" class="mt-1" x-on:click="startCamera()">
                                    {{ __('Capture ID Photo') }}
                                </flux:button>
                                <flux:button variant="outline" size="sm" class="mt-1" x-on:click="$refs.idFileInput.click()">
                                    {{ __('Upload Photo') }}
                                </flux:button>
                            </div>
                            <input type="file" x-ref="idFileInput" accept="image/*" class="hidden" x-on:change="handleFile">
                        </div>
                        <div x-show="cameraActive">
                            <video x-ref="idVideo" autoplay playsinline class="mx-auto max-h-48 rounded-lg"></video>
                            <div class="mt-3 flex justify-center gap-2">
                                <flux:button variant="primary" x-on:click="capture()" x-bind:disabled="!videoReady">{{ __('Capture') }}</flux:button>
                                <flux:button variant="ghost" x-on:click="stopCamera()">{{ __('Cancel') }}</flux:button>
                            </div>
                        </div>
                        <div x-show="preview">
                            <div class="flex items-center justify-center gap-3">
                                <img :src="preview" alt="{{ __('Valid ID preview') }}" class="h-16 w-16 rounded-lg border border-neutral-300 object-cover">
                                <div class="text-left">
                                    <p class="text-sm font-medium text-emerald-600">{{ __('ID photo captured') }}</p>
                                    <div class="mt-1 flex flex-col gap-1">
                                        <button type="button" x-on:click="remove" class="text-left text-xs text-neutral-400 hover:text-neutral-600 dark:hover:text-neutral-300">{{ __('Remove') }}</button>
                                        <button type="button" x-on:click="startCamera()" class="text-left text-xs text-neutral-400 hover:text-neutral-600 dark:hover:text-neutral-300">{{ __('Retake') }}</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <canvas x-ref="idCanvas" class="hidden"></canvas>
                    </div>
                    <p class="mt-1 text-xs text-neutral-400 dark:text-neutral-500">
                        {{ __('Will be reused for check-in.') }}
                    </p>
                </div>

                <flux:input wire:model="purpose" label="{{ __('Purpose of visit') }}" placeholder="{{ __('e.g. Meeting, Interview, Delivery') }}" />
                <flux:input wire:model="expectedDate" label="{{ __('Expected date (optional)') }}" type="date" />

                <flux:button variant="primary" class="w-full !py-3 text-base" wire:click="submit">
                    {{ __('Submit Pre-Registration') }}
                </flux:button>
            </div>
    </div>
</div>
