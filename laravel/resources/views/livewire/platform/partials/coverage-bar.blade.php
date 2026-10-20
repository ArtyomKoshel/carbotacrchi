@php $pct = (float) $getState(); @endphp
<div class="coverage-cell">
    <div class="coverage-track"><div class="coverage-fill" style="width: {{ min(100, max(0, $pct)) }}%"></div></div>
    <span class="coverage-value">{{ number_format($pct, 1, ',', '') }} %</span>
</div>
