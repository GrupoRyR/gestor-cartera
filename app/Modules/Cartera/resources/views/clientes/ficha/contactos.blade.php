{{-- Contacts of the client, as SAP has them. $file. --}}
<x-ui.card :title="__('cartera::ficha.contactos.titulo')" :description="__('cartera::ficha.contactos.descripcion')">
    @forelse ($detail->contacts as $contact)
        <div class="border-b border-line py-3 first:pt-0 last:border-b-0 last:pb-0">
            <p class="flex flex-wrap items-center gap-2 font-semibold">
                <span class="[overflow-wrap:anywhere]">{{ $contact->name ?? __('cartera::ficha.contactos.sin_nombre') }}</span>
                @if ($contact->isPrimary)
                    <x-ui.chip tone="info" :dot="false">{{ __('cartera::ficha.contactos.principal') }}</x-ui.chip>
                @endif
            </p>
            @if ($contact->position)
                <p class="text-sm text-fg-muted">{{ $contact->position }}</p>
            @endif
            @if ($contact->email)
                <p class="text-sm [overflow-wrap:anywhere]">{{ $contact->email }}</p>
            @endif
            @if ($contact->phone)
                <p class="text-sm">
                    <a href="tel:{{ preg_replace('/[^\d+]/', '', $contact->phone) }}" class="font-semibold text-brand-ink underline">{{ $contact->phone }}<span data-visually-hidden> · {{ __('cartera::ficha.contactos.llamar', ['nombre' => $contact->name ?? $client->name]) }}</span></a>
                </p>
            @endif
        </div>
    @empty
        <p class="text-fg-muted">{{ __('cartera::ficha.contactos.vacio') }}</p>
    @endforelse
</x-ui.card>
