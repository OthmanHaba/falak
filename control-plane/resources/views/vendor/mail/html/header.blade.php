@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
<img src="{{ asset('mail-logo.png') }}" class="logo" width="28" height="28" alt="">{!! $slot !!}
</a>
</td>
</tr>
