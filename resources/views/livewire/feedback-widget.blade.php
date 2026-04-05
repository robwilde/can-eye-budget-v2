<div x-data="{
    fp: null,
    capturing: false,
    init() {
        if (window.FeedbackPlus) {
            this.fp = new FeedbackPlus();
        }
    },
    capture() {
        if (!this.fp || this.capturing) return;
        this.capturing = true;

        this.fp.capture(300).then(bitmap => {
            this.fp.showEditDialog(
                bitmap,
                (canvas) => {
                    const base64 = canvas.toDataURL('image/png');
                    $wire.setScreenshotAndOpen(
                        base64,
                        window.location.href,
                        navigator.userAgent,
                        window.innerWidth + 'x' + window.innerHeight
                    );
                    this.fp.closeEditDialog();
                    this.capturing = false;
                },
                () => {
                    this.fp.closeEditDialog();
                    this.capturing = false;
                }
            );
        }).catch(() => {
            this.capturing = false;
        });
    }
}">
    <button
        type="button"
        x-on:click="capture()"
        class="fixed bottom-6 right-6 z-50 flex h-12 w-12 items-center justify-center rounded-full bg-orange-500 text-white shadow-lg transition hover:bg-orange-600 hover:shadow-xl focus:outline-none focus:ring-2 focus:ring-orange-400 focus:ring-offset-2 dark:bg-orange-600 dark:hover:bg-orange-500"
        title="Report feedback"
    >
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="h-6 w-6">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" />
        </svg>
    </button>

    <flux:modal wire:model="showModal" class="md:w-xl">
        <form wire:submit="submit" class="space-y-6">
            <flux:heading size="lg">{{ __('Send Feedback') }}</flux:heading>

            <div class="flex gap-2">
                <flux:button
                    type="button"
                    size="sm"
                    :variant="$category === 'bug' ? 'primary' : 'ghost'"
                    wire:click="$set('category', 'bug')"
                >
                    {{ __("\u{1F41B} Bug") }}
                </flux:button>
                <flux:button
                    type="button"
                    size="sm"
                    :variant="$category === 'feature-request' ? 'primary' : 'ghost'"
                    wire:click="$set('category', 'feature-request')"
                >
                    {{ __("\u{1F4A1} Feature Request") }}
                </flux:button>
                <flux:button
                    type="button"
                    size="sm"
                    :variant="$category === 'question' ? 'primary' : 'ghost'"
                    wire:click="$set('category', 'question')"
                >
                    {{ __("\u{2753} Question") }}
                </flux:button>
            </div>

            <flux:textarea
                wire:model="description"
                :label="__('Description')"
                :placeholder="__('Describe the issue or suggestion...')"
                rows="4"
            />

            @if($screenshot)
                <div>
                    <flux:text class="mb-2 text-sm">{{ __('Screenshot preview') }}</flux:text>
                    <div class="max-h-64 overflow-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
                        <img src="{{ $screenshot }}" alt="Screenshot" class="w-full" />
                    </div>
                </div>
            @endif

            <div class="flex justify-end gap-2">
                <flux:button type="button" variant="ghost" wire:click="$set('showModal', false)">
                    {{ __('Cancel') }}
                </flux:button>
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="submit">{{ __('Submit Feedback') }}</span>
                    <span wire:loading wire:target="submit">{{ __('Submitting...') }}</span>
                </flux:button>
            </div>
        </form>
    </flux:modal>
</div>
