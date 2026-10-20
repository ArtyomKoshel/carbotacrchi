@if (empty($lines))
    <p class="lot-empty">{{ $empty ?? 'Пусто.' }}</p>
@else
    <pre class="log-lines">@foreach ($lines as $line){{ is_scalar($line) ? $line : json_encode($line, JSON_UNESCAPED_UNICODE) }}
@endforeach</pre>
@endif
