<x-mail::message>
# {{ $alert->headline() }}

@if ($alert->body !== '')
{{ $alert->body }}
@endif

@if ($alert->context !== [])
<x-mail::table>
| | |
|:--|:--|
@foreach ($alert->context as $key => $value)
| {{ $key }} | {{ is_bool($value) ? ($value ? 'yes' : 'no') : $value }} |
@endforeach
</x-mail::table>
@endif

@if ($alert->url)
<x-mail::button :url="$alert->url">
Open in Kiln
</x-mail::button>
@endif

<small>{{ $alert->severity->label() }} · {{ $alert->type }} · {{ $alert->createdAt->format('Y-m-d H:i:s T') }}</small>
</x-mail::message>
