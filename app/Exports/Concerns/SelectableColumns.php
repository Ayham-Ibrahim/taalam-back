<?php

namespace App\Exports\Concerns;

use Illuminate\Validation\Rule;

/**
 * أعمدة تصدير يختار الأدمن منها ما يريد — تعريف واحد لكل عمود (مفتاح ثابت ⇐ عنوان + قيمة)
 * يخدم ثلاثة أشياء: قائمة الأعمدة التي تعرضها الواجهة، التحقق من الطلب، وبناء الملف.
 * الأعمدة تخرج دائماً بترتيب تعريفها لا بترتيب إرسالها؛ وبلا اختيار تُصدَّر كلها.
 *
 * @template T
 */
final class SelectableColumns
{
    /** @var list<string> */
    private array $selected;

    /**
     * @param  array<string, array{0: string, 1: callable(T): mixed}>  $definitions  المفتاح ⇐ [العنوان، دالة القيمة]
     * @param  list<string>|null  $selected
     */
    public function __construct(private readonly array $definitions, ?array $selected = null)
    {
        $keys = array_keys($definitions);
        $this->selected = $selected ? array_values(array_intersect($keys, $selected)) : $keys;
    }

    /** قائمة الأعمدة للواجهة: [{key, label}] */
    public function options(): array
    {
        return array_map(fn (string $key) => ['key' => $key, 'label' => $this->definitions[$key][0]], array_keys($this->definitions));
    }

    /** قواعد التحقق لباراميتر columns[] — مفاتيح معروفة فقط */
    public function rules(): array
    {
        return [
            'columns' => ['nullable', 'array', 'min:1'],
            'columns.*' => ['string', 'distinct', Rule::in(array_keys($this->definitions))],
        ];
    }

    /** هل اختير العمود؟ — لتجنّب تحميل علاقات/حسابات أعمدة لم تُطلب */
    public function wants(string $key): bool
    {
        return in_array($key, $this->selected, true);
    }

    public function headings(): array
    {
        return array_map(fn (string $key) => $this->definitions[$key][0], $this->selected);
    }

    /** @param  T  $item */
    public function row(mixed $item): array
    {
        return array_map(fn (string $key) => ($this->definitions[$key][1])($item), $this->selected);
    }

    /** صف إجمالي بطول الأعمدة المختارة — النص في أول خلية */
    public function totalsRow(string $label): array
    {
        return [$label, ...array_fill(0, count($this->selected) - 1, '')];
    }
}
