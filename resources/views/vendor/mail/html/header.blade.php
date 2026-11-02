@props(['url'])
{{-- Ikon DnD di atas kartu seperti mky-auth-brand di halaman login (tinggi 48px). PNG karena banyak klien email tidak menampilkan SVG. --}}
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
<img src="{{ asset('images/mail/logo.png') }}" class="logo" width="48" height="48" alt="{{ config('app.name') }}">
</a>
</td>
</tr>
