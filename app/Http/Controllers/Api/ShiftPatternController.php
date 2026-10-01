<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ShiftPattern;
use App\Services\ShiftService;
use App\Support\CurrentStore;
use App\Support\ShiftSegments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** 13 §5 #91〜#93 勤務の区分（owner が作る・直す。消さずに使わない区分は is_active で隠す） */
class ShiftPatternController extends Controller
{
    public function __construct(
        private readonly CurrentStore $currentStore,
        private readonly ShiftService $shifts,
    ) {}

    /** #91 区分の一覧（使わない区分も含む） */
    public function index(): JsonResponse
    {
        return response()->json([
            'patterns' => ShiftPattern::query()->orderByDesc('is_active')->orderBy('name')->orderBy('id')->get()
                ->map(fn (ShiftPattern $p): array => self::pattern($p))->values()->all(),
        ]);
    }

    /** #92 区分を作る */
    public function store(Request $request): JsonResponse
    {
        return response()->json(self::pattern($this->shifts->createPattern($this->input($request, null))), 201);
    }

    /** #93 区分を直す */
    public function update(Request $request, ShiftPattern $shiftPattern): JsonResponse
    {
        return response()->json(self::pattern($this->shifts->updatePattern($shiftPattern, $this->input($request, $shiftPattern))));
    }

    /** @return array<string, mixed> */
    public static function pattern(ShiftPattern $p): array
    {
        $times = $p->times();

        return [
            'id' => $p->id,
            'name' => $p->name,
            'segments' => $p->segments,
            'is_active' => $p->is_active,
            'start_time' => $times['start_time'],
            'end_time' => $times['end_time'],
            'break_minutes' => $times['break_minutes'],
        ];
    }

    /** @return array{name: string, segments: list<array{start: string, end: string}>, is_active: bool} */
    private function input(Request $request, ?ShiftPattern $current): array
    {
        $validator = validator($request->all(), [
            'name' => ['required', 'string', 'max:20', Rule::unique('shift_patterns', 'name')
                ->where('store_id', $this->currentStore->requireId())
                ->ignore($current?->id)],
            'segments' => ['required', 'array'],
            'is_active' => ['required', 'boolean'],
        ], attributes: ['name' => '区分の名前', 'segments' => '時間帯', 'is_active' => '使う']);
        $validator->after(function (Validator $v) use ($request): void {
            if ($v->errors()->has('segments')) {
                return;
            }
            $problem = ShiftSegments::problem($request->input('segments'));
            if ($problem !== null) {
                $v->errors()->add('segments', $problem);
            }
        });
        $validator->validate();

        /** @var list<array{start: string, end: string}> $segments */
        $segments = $request->input('segments');

        return [
            'name' => trim($request->string('name')->toString()),
            'segments' => $segments,
            'is_active' => $request->boolean('is_active'),
        ];
    }
}
