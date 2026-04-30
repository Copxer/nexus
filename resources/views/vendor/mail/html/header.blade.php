@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
@if (trim($slot) === config('app.name') || trim($slot) === 'Laravel')
<img src="{{ asset('nexus-logo.png') }}" class="logo" alt="{{ config('app.name') }}" style="height: 40px; width: auto;">
@else
{!! $slot !!}
@endif
</a>
</td>
</tr>
