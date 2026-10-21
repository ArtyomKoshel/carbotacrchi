<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Lots\LotFormat;
use App\Models\Translation;
use App\Providers\Filament\AdminPanelProvider;
use App\Services\TranslationCatalog;
use App\Support\JobFormat;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

/**
 * «Проверка переводов»: the review queue for the KR→EN dictionary. AI rows
 * (translate:run) wait for a person: accept, correct or reject; «Без перевода»
 * lists Korean values in lots nobody has translated. J/K move, A/E/D act.
 *
 * Replaces the anomaly queue of the old taxonomy pipeline, whose tables were
 * dropped on 2026-06-01 when parsing moved to catalog lookups.
 */
class TranslationReview extends Page
{
    use WithPagination;

    public const STATUSES = [
        'ai'      => 'Ждут проверки',
        'checked' => 'Проверены',
        'missing' => 'Без перевода',
    ];

    private const PER_PAGE = 25;

    protected string $view = 'filament.pages.translation-review';

    protected static ?string $title = 'Проверка переводов';

    protected static ?string $navigationLabel = 'Проверка переводов';

    protected static string | \BackedEnum | null $navigationIcon = Heroicon::OutlinedLanguage;

    protected static string | \UnitEnum | null $navigationGroup = AdminPanelProvider::GROUP_DATA;

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'translation-review';

    #[Url]
    public string $status = 'ai';

    #[Url]
    public string $category = '';

    #[Url]
    public string $q = '';

    /** Position of the current item on the page */
    public int $index = 0;

    private LengthAwarePaginator | Collection | null $itemsCache = null;

    private ?array $countsCache = null;

    public static function canAccess(): bool
    {
        return AdminPanelProvider::admin()?->canSeePage('taxonomy') ?? false;
    }

    public function mount(): void
    {
        if (!isset(self::STATUSES[$this->status])) {
            $this->status = 'ai';
        }
        if ($this->status === 'missing' && !isset(TranslationCatalog::CATEGORIES[$this->category])) {
            $this->category = 'model';
        }
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['status', 'category', 'q'], true)) {
            if ($this->status === 'missing' && !isset(TranslationCatalog::CATEGORIES[$this->category])) {
                $this->category = 'model';
            }
            $this->index = 0;
            $this->resetPage();
            $this->forgetLists();
        }
    }

    private function catalog(): TranslationCatalog
    {
        return app(TranslationCatalog::class);
    }

    /** @return LengthAwarePaginator<Translation>|Collection<int, object> */
    public function items(): LengthAwarePaginator | Collection
    {
        return $this->itemsCache ??= (function () {
            if ($this->status === 'missing') {
                return $this->catalog()->untranslated($this->category, 200)
                    ->when($this->q !== '', fn ($c) => $c->filter(fn ($r) => str_contains(mb_strtolower($r->kr), mb_strtolower($this->q))))
                    ->values();
            }

            return Translation::query()
                ->when($this->status === 'ai', fn ($q) => $q->where('source', 'ai'), fn ($q) => $q->where('source', '!=', 'ai'))
                ->when($this->category !== '', fn ($q) => $q->where('category', $this->category))
                ->when($this->q !== '', fn ($q) => $q->where(fn ($w) => $w
                    ->where('kr', 'like', '%' . $this->q . '%')
                    ->orWhere('en', 'like', '%' . $this->q . '%')))
                ->orderByRaw("FIELD(category, 'make', 'model_group', 'model', 'badge_group', 'badge', 'trim', 'color', 'seat_color')")->orderBy('kr')
                ->paginate(self::PER_PAGE);
        })();
    }

    /** Lists change after an action; read them again for the render. */
    private function forgetLists(): void
    {
        $this->itemsCache = null;
        $this->countsCache = null;
    }

    /** @return array<string, int> */
    public function counts(): array
    {
        return $this->countsCache ??= (fn () => [
            'ai'      => Translation::where('source', 'ai')->when($this->category !== '', fn ($q) => $q->where('category', $this->category))->count(),
            'checked' => Translation::where('source', '!=', 'ai')->when($this->category !== '', fn ($q) => $q->where('category', $this->category))->count(),
        ])();
    }

    /** The item under the cursor: a Translation, or {kr, n} on «Без перевода». */
    public function current(): ?object
    {
        $items = $this->items();
        $list = $items instanceof LengthAwarePaginator ? collect($items->items()) : $items;
        if ($list->isEmpty()) {
            return null;
        }
        $this->index = max(0, min($this->index, $list->count() - 1));

        return $list[$this->index];
    }

    public function currentCategory(): ?string
    {
        $item = $this->current();

        return $item instanceof Translation ? $item->category : ($item ? $this->category : null);
    }

    /** @return array{usage: int, examples: Collection} */
    public function context(): array
    {
        $item = $this->current();
        if (!$item) {
            return ['usage' => 0, 'examples' => collect()];
        }
        $category = $this->currentCategory();

        return [
            'usage'    => $item instanceof Translation ? $this->catalog()->usage($category, $item->kr) : (int) $item->n,
            'examples' => $this->catalog()->examples($category, $item->kr),
        ];
    }

    public function select(int $i): void
    {
        $this->index = $i;
    }

    public function next(): void
    {
        $items = $this->items();
        $count = $items instanceof LengthAwarePaginator ? count($items->items()) : $items->count();
        if ($this->index < $count - 1) {
            $this->index++;
        } elseif ($items instanceof LengthAwarePaginator && $items->hasMorePages()) {
            $this->nextPage();
            $this->index = 0;
            $this->forgetLists();
        }
    }

    public function previous(): void
    {
        if ($this->index > 0) {
            $this->index--;
        }
    }

    /** A: confirm the AI translation as it is. */
    public function acceptCurrent(): void
    {
        $item = $this->current();
        if (!$item instanceof Translation || $item->source !== 'ai') {
            return;
        }
        $this->catalog()->accept($item);
        $this->forgetLists();
        Notification::make()->title("Принято: {$item->kr} → {$item->en}")->success()->send();
        // the row leaves the queue; the cursor stays on the same position
    }

    public function editAction(): Action
    {
        return Action::make('edit')
            ->label(fn () => $this->current() instanceof Translation ? 'Исправить' : 'Перевести')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->color('gray')
            ->modalHeading(fn () => ($this->current() instanceof Translation ? 'Исправить перевод: ' : 'Перевести: ') . ($this->current()?->kr ?? ''))
            ->modalDescription(function () {
                $item = $this->current();
                if (!$item) {
                    return null;
                }
                $n = $item instanceof Translation ? $this->catalog()->affected($item) : (int) $item->n;
                $where = TranslationCatalog::label($this->currentCategory());

                return "Лотов, где поле «{$where}» сейчас со старым переводом или пустое: {$this->lots($n)}. Они получат новое значение. Перевод будет отмечен как проверенный.";
            })
            ->fillForm(fn () => $this->current() instanceof Translation
                ? ['en' => $this->current()->en, 'ru' => $this->current()->ru]
                : ['en' => null, 'ru' => null])
            ->schema([
                TextInput::make('en')->label('По-английски')->required()->maxLength(200)->autofocus(),
                TextInput::make('ru')->label('По-русски')->maxLength(200)->helperText('Необязательно; сейчас нигде не показывается.'),
            ])
            ->modalSubmitActionLabel('Сохранить')
            ->action(function (array $data) {
                $item = $this->current();
                if ($item instanceof Translation) {
                    $n = $this->catalog()->update($item, trim($data['en']), $data['ru'] ?: null);
                } elseif ($item) {
                    [, $n] = $this->catalog()->create($this->category, $item->kr, trim($data['en']), $data['ru'] ?: null);
                } else {
                    return;
                }
                $this->forgetLists();
                Notification::make()->title('Сохранено')->body('Обновлено лотов: ' . $this->lots($n) . '.')->success()->send();
            });
    }

    public function rejectAction(): Action
    {
        return Action::make('reject')
            ->label('Отклонить')
            ->icon(Heroicon::OutlinedXMark)
            ->color('gray')
            ->requiresConfirmation()
            ->visible(fn () => $this->current() instanceof Translation)
            ->modalHeading(fn () => 'Отклонить перевод «' . ($this->current()?->en ?? '') . '»?')
            ->modalDescription(function () {
                $item = $this->current();
                if (!$item instanceof Translation) {
                    return null;
                }
                if (!TranslationCatalog::keepsKorean($item->category)) {
                    return 'Перевод удалится из словаря. В лотах цвет уже записан по-английски и останется как есть.';
                }

                return 'Перевод удалится из словаря. Лотов, где это поле станет пустым: ' . $this->lots($this->catalog()->affected($item))
                    . '. В поиске у них будет корейское значение, пока следующий translate:run не переведёт заново.';
            })
            ->modalSubmitActionLabel('Отклонить')
            ->modalSubmitAction(fn (Action $action) => $action->color('danger'))
            ->action(function () {
                $item = $this->current();
                if ($item instanceof Translation) {
                    $n = $this->catalog()->reject($item);
                    $this->forgetLists();
                    Notification::make()->title('Перевод отклонён')->body('Очищено лотов: ' . $this->lots($n) . '.')->success()->send();
                }
            });
    }

    public function acceptPageAction(): Action
    {
        return Action::make('acceptPage')
            ->label('Принять все на странице')
            ->icon(Heroicon::OutlinedCheck)
            ->color('gray')
            ->requiresConfirmation()
            ->visible(fn () => $this->status === 'ai' && $this->items() instanceof LengthAwarePaginator && $this->items()->count() > 0)
            ->modalHeading(fn () => 'Принять ' . $this->items()->count() . ' ' . JobFormat::plural($this->items()->count(), 'перевод', 'перевода', 'переводов') . '?')
            ->modalDescription('Все переводы на этой странице будут отмечены как проверенные, без изменений. В лотах ничего не меняется. Просмотрите список перед подтверждением.')
            ->modalSubmitActionLabel('Принять все')
            ->action(function () {
                $ids = collect($this->items()->items())->pluck('id');
                $n = Translation::whereIn('id', $ids)->where('source', 'ai')->update(['source' => 'manual', 'updated_at' => now()]);
                $this->index = 0;
                $this->forgetLists();
                Notification::make()->title('Принято: ' . $n)->success()->send();
            });
    }

    private function lots(int $n): string
    {
        return LotFormat::number($n);
    }
}
