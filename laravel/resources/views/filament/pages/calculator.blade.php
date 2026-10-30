@php
    $r = $this->rate();
    $fmt = fn (?float $v) => $v === null ? '—' : number_format($v, 0, '.', ' ') . ' ₩';
    $at = $r['at'] ? \Illuminate\Support\Carbon::parse($r['at'])->timezone(config('app.timezone'))->format('d.m H:i') : '—';
@endphp

<x-filament-panels::page>
    <p class="panel-intro">
        Калькулятор стоимости авто «под ключ» в мини-аппе: в карточке лота и на вкладке «Калькулятор».
        Здесь — значения, с которых начинается каждый расчёт; менеджер может поменять любое для одной машины.
        Курс берётся с Bithumb (USDT/KRW) раз в {{ intdiv((int) config('calculator.rate_cache_seconds'), 60) }} мин, одинаковый для всех.
        Таможня считается сама по типу двигателя, объёму и возрасту машины (ставки как в customs.gov.by/calc);
        пошлина в евро и сборы в рублях переводятся в доллары по курсам НБРБ.
    </p>

    <x-filament::section heading="Курс" compact>
        <dl class="balances">
            <div class="balance">
                <dt class="health-label">Bithumb USDT/KRW</dt>
                <dd class="health-value-sm">{{ $fmt($r['rate']) }}</dd>
            </div>
            <div class="balance">
                <dt class="health-label">В расчёте (минус вычет)</dt>
                <dd class="health-value-sm">{{ $fmt($r['used']) }}</dd>
            </div>
            <div class="balance">
                <dt class="health-label">Получен</dt>
                <dd class="health-value-sm {{ $r['stale'] ? 'is-warn' : '' }}">
                    {{ $at }}{{ $r['stale'] ? ' · Bithumb не ответил, это последний известный курс' : '' }}
                </dd>
            </div>
        </dl>
    </x-filament::section>

    @php($n = $this->nbrb())
    <x-filament::section heading="Курсы НБРБ (для таможни)" compact>
        <dl class="balances">
            <div class="balance">
                <dt class="health-label">1 USD</dt>
                <dd class="health-value-sm">{{ $n['usd'] ? number_format($n['usd'], 4, ',', ' ') . ' BYN' : '—' }}</dd>
            </div>
            <div class="balance">
                <dt class="health-label">1 EUR</dt>
                <dd class="health-value-sm">{{ $n['eur'] ? number_format($n['eur'], 4, ',', ' ') . ' BYN' : '—' }}</dd>
            </div>
            <div class="balance">
                <dt class="health-label">На дату</dt>
                <dd class="health-value-sm {{ $n['stale'] ? 'is-warn' : '' }}">
                    {{ $n['date'] ? \Illuminate\Support\Carbon::parse($n['date'])->format('d.m.Y') : '—' }}{{ $n['stale'] ? ' · Нацбанк не ответил, это последние известные курсы' : '' }}
                </dd>
            </div>
        </dl>
    </x-filament::section>

    {{ $this->defaultsForm }}
</x-filament-panels::page>
