@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
@if (trim($slot) === 'Laravel')
<span style="font-size: 20px; font-weight: bold; color: #2d3748;">Decyphers</span>
@else
{{ $slot }}
@endif
</a>
</td>
</tr>
