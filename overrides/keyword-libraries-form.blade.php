@extends('admin.layouts.app')

@php
    $formAction = $isEdit
        ? route('admin.keyword-libraries.update', ['libraryId' => (int) $libraryId])
        : url(rtrim((string) config('geoflow.admin_base_path', '/geo_admin'), '/').'/keyword-libraries/batch-create');
    $contextValue = in_array($context ?? 'index', ['index', 'detail'], true) ? ($context ?? 'index') : 'index';
    $returnUrl = $isEdit && $contextValue === 'detail'
        ? route('admin.keyword-libraries.detail', ['libraryId' => (int) $libraryId])
        : route('admin.keyword-libraries.index');
    $normalizeScalar = static function (mixed $value, string $fallback = ''): string {
        return is_string($value) || is_int($value) || is_float($value)
            ? (string) $value
            : $fallback;
    };
    $nameFallback = $normalizeScalar($libraryForm['name'] ?? '');
    $descriptionFallback = $normalizeScalar($libraryForm['description'] ?? '');
    $nameValue = $normalizeScalar(old('name', $nameFallback), $nameFallback);
    $descriptionValue = $normalizeScalar(old('description', $descriptionFallback), $descriptionFallback);
@endphp

@section('content')
    <div class="mx-auto max-w-4xl px-4 sm:px-0">
        <header class="mb-6 flex items-start gap-4 sm:mb-8">
            <a href="{{ $returnUrl }}" aria-label="{{ __('admin.common.back') }}" class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-white text-gray-500 shadow ring-1 ring-gray-200 transition-[color,background-color,transform] duration-150 [@media(hover:hover)]:hover:bg-gray-50 [@media(hover:hover)]:hover:text-gray-800 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2">
                <i data-lucide="arrow-left" class="h-5 w-5"></i>
            </a>
            <div class="min-w-0">
                <h1 class="text-balance break-words text-2xl font-bold text-gray-900">{{ $isEdit ? __('admin.button.edit').' · '.$nameFallback : __('admin.keyword_libraries.modal_create') }}</h1>
                <p class="mt-1 max-w-3xl text-pretty text-sm leading-6 text-gray-600">{{ __('admin.keyword_libraries.subtitle') }}</p>
            </div>
        </header>

        <form method="POST" action="{{ $formAction }}" class="overflow-hidden rounded-xl bg-white shadow" data-library-entry-form data-processing-label="{{ __('admin.message.processing') }}">
            @csrf
            @if ($isEdit)
                @method('PUT')
                <input type="hidden" name="context" value="{{ $contextValue }}">
            @endif

            <div class="border-b border-gray-200 px-5 py-5 sm:px-6">
                <h2 class="text-lg font-semibold text-gray-900">{{ __('admin.common.basic_info') }}</h2>
                <p class="mt-1 text-sm leading-6 text-gray-600">{{ __('admin.keyword_libraries.subtitle') }}</p>
            </div>

            <div class="space-y-6 px-5 py-6 sm:px-6">
                {{-- 【本地扩展】新建时用「品牌 + 类别」生成库名，避免手填导致命名不一致。
                     命名约定：品牌｜类 中文名（如「冰溪｜B 品类场景」），
                     声量对标靠这个格式认品牌和维度，手写容易写歪。编辑时不给改。 --}}
                @if (! $isEdit)
                    @php
                        $brandList = \App\Support\GeoAudit\GeoShareOfVoice::brands();
                        // 【本地扩展】加了 E 泛需求词：不带品牌、不带性别的纯场景问法。
                        // 这一层是最大的入口——用户第一次搜的时候根本不会提品牌名。
                        $catDefs = ['A' => '品牌认知', 'B' => '品类场景', 'C' => '产品深挖', 'D' => '竞品对比', 'E' => '泛需求词'];
                    @endphp
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <label for="lib_brand" class="block text-sm font-semibold text-gray-800">品牌</label>
                            {{-- 用 input + datalist 而不是 select：新客户不在已配置品牌里时也要能建库，
                                 下拉是选不出来的。datalist 兼顾"输入新品牌"和"快速选已有品牌"。 --}}
                            <input id="lib_brand" name="brand" list="lib_brand_list" required maxlength="50"
                                   autocomplete="off" placeholder="输入品牌名，或从已有品牌里选"
                                   class="mt-2 block min-h-11 w-full rounded-lg border-gray-300 px-3 py-2.5 text-sm text-gray-900 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            <datalist id="lib_brand_list">
                                @foreach ($brandList as $b)
                                    <option value="{{ $b['name'] ?? '' }}"></option>
                                @endforeach
                            </datalist>
                            <p class="mt-1.5 text-xs leading-5 text-gray-500">新品牌可以直接输入。若要在「声量对标」里分析它，需要先在品牌档案里配置它的别名和竞品。</p>
                        </div>
                        <div>
                            <label for="lib_cat" class="block text-sm font-semibold text-gray-800">问题类别</label>
                            <select id="lib_cat" name="category" class="mt-2 block min-h-11 w-full rounded-lg border-gray-300 px-3 py-2.5 text-sm text-gray-900 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <option value="ALL" data-label="" selected>全部四个（推荐）</option>
                                @foreach ($catDefs as $ck => $cname)
                                    <option value="{{ $ck }}" data-label="{{ $cname }}">只建 {{ $ck }} {{ $cname }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <p class="text-xs leading-5 text-gray-500">
                        一个词库只装一类问题。用户在 AI 里的问法分四种：
                        <b>A 品牌认知</b>（已经知道你，在验证你）、<b>B 品类场景</b>（有需求但知道品类）、<b>E 泛需求词</b>（纯场景、不提品牌不提性别，最大的入口）、
                        <b>C 产品深挖</b>（锁定品牌，在比参数）、<b>D 竞品对比</b>（在做最后选择）。
                    </p>
                @endif

                @if ($isEdit)
                <div>
                    <label for="name" class="block text-sm font-semibold text-gray-800">
                        {{ __('admin.keyword_libraries.field_name') }}
                    </label>
                    <input id="name" type="text" name="name" required maxlength="100" autocomplete="off" value="{{ $nameValue }}" @error('name') aria-invalid="true" aria-describedby="keyword-library-name-error" @enderror class="mt-2 block min-h-11 w-full rounded-lg border-gray-300 px-3 py-2.5 text-sm text-gray-900 shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="{{ __('admin.keyword_libraries.placeholder_name') }}">
                    @error('name')
                        <p id="keyword-library-name-error" class="mt-2 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>
                @else
                <div class="rounded-lg bg-blue-50 px-4 py-3 ring-1 ring-blue-100">
                    <div class="text-sm font-semibold text-blue-900">将要创建</div>
                    <p id="lib_preview" class="mt-1 text-sm leading-6 text-blue-800"></p>
                </div>
                @endif
                <div>
                    <label for="description" class="block text-sm font-semibold text-gray-800">{{ __('admin.keyword_libraries.field_description') }}</label>
                    <textarea id="description" name="description" rows="5" @error('description') aria-invalid="true" aria-describedby="keyword-library-description-error" @enderror class="mt-2 block w-full resize-y rounded-lg border-gray-300 px-3 py-3 text-sm leading-6 text-gray-900 shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="{{ __('admin.keyword_libraries.placeholder_description') }}">{{ $descriptionValue }}</textarea>
                    @error('description')
                        <p id="keyword-library-description-error" class="mt-2 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>
                @error('context')
                    <p class="text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div class="flex flex-wrap items-center justify-start gap-3 border-t border-gray-200 bg-gray-50 px-5 py-4 sm:justify-end sm:px-6">
                <p class="min-h-5 basis-full text-sm text-gray-600 sm:mr-auto sm:basis-auto" role="status" aria-live="polite" aria-atomic="true" data-library-entry-status></p>
                <a href="{{ $returnUrl }}" class="inline-flex min-h-10 items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 transition-[color,background-color,transform] duration-150 [@media(hover:hover)]:hover:bg-gray-100 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2">
                    {{ __('admin.button.cancel') }}
                </a>
                <button type="submit" class="inline-flex min-h-10 items-center justify-center rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition-[background-color,opacity,transform] duration-150 [@media(hover:hover)]:hover:bg-blue-700 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 disabled:cursor-wait disabled:opacity-65 disabled:active:scale-100" data-library-entry-submit>
                    <span data-library-entry-submit-label>{{ __('admin.button.save') }}</span>
                </button>
            </div>
        </form>
    </div>
@endsection

@if (! $isEdit)
@push('scripts')
<script>
(function () {
    var b = document.getElementById('lib_brand');
    var c = document.getElementById('lib_cat');
    if (!b || !c) return;

    var pv = document.getElementById('lib_preview');
    function sync() {
        if (!pv) return;
        var v = c.value;
        if (v === 'ALL') {
            pv.innerHTML = b.value + ' 的四个库：<br>'
                + ['A 品牌认知', 'B 品类场景', 'C 产品深挖', 'D 竞品对比', 'E 泛需求词']
                    .map(function (x) { return '　· ' + b.value + '｜' + x; }).join('<br>');
        } else {
            var cat = c.options[c.selectedIndex];
            var label = cat ? (cat.getAttribute('data-label') || '') : '';
            pv.textContent = b.value + '｜' + v + ' ' + label;
        }
    }
    b.addEventListener('change', sync);
    c.addEventListener('change', sync);
    sync();
})();
</script>
@endpush
@endif
