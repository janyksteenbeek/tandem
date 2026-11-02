@props(['url', 'logo' => null])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
@if ($logo)
<img src="{{ $logo }}" class="logo" width="44" height="44" alt="">
@endif
<span class="header-name">{!! trim($slot) !!}</span>
</a>
</td>
</tr>
