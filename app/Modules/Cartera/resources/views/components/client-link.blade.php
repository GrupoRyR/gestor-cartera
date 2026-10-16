{{-- Name of a client as a link to its file (<x-cartera::client-link :id="$client->id" :name="$client->name" />). --}}
@props(['id', 'name'])

<a {{ $attributes->merge(['href' => route('cartera.clientes.show', $id)])->class('font-semibold text-brand-ink underline underline-offset-2 [overflow-wrap:anywhere]') }}>{{ $name }}</a>
