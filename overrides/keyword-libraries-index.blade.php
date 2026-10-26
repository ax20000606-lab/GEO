@extends('admin.layouts.app')

@section('content')
    <style>
        .kg-chev { transition: transform .15s ease; }
        details[open] .kg-chev { transform: rotate(90deg); }
    </style>
    <div class="mx-auto max-w-7xl px-4 sm:px-0" data-materials-standalone>
        <header class="mb-6 flex flex-col gap-4 sm:mb-8 sm:flex-row sm:items-center sm:justify-between">
            <div class="min-w-0 flex-1">
                <div class="sr-only">
                    <h1>{{ __('admin.keyword_libraries.heading') }}</h1>
                    <p>{{ __('admin.keyword_libraries.subtitle') }}</p>
                </div>
                <x-admin.v3.materials-subnav active="keywords" />
            </div>
            {{-- 【本地扩展】AI 生成关键词入口。
                 放在「创建词库」旁边，因为它本质上也是"往词库里加东西"的一种方式，
                 区别只是人写还是 AI 出。跳转目标是自己渲染的 geo-tools 页面。 --}}
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ url(rtrim((string) config('geoflow.admin_base_path', '/geo_admin'), '/').'/keyword-gen') }}" class="inline-flex min-h-10 w-fit items-center justify-center rounded-lg border border-violet-300 bg-violet-50 px-4 py-2 text-sm font-semibold text-violet-700 shadow-sm transition-[background-color,transform] duration-150 [@media(hover:hover)]:hover:bg-violet-100 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-violet-500 focus-visible:ring-offset-2">
                    <i data-lucide="sparkles" class="mr-2 h-4 w-4"></i>
                    AI 生成关键词
                </a>
                <a href="{{ route('admin.keyword-libraries.create') }}" class="inline-flex min-h-10 w-fit items-center justify-center rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition-[background-color,transform] duration-150 [@media(hover:hover)]:hover:bg-blue-700 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2">
                    <i data-lucide="plus" class="mr-2 h-4 w-4"></i>
                    {{ __('admin.keyword_libraries.create') }}
                </a>
            </div>
        </header>

        <div class="mb-8 grid grid-cols-2 gap-3 sm:gap-4 md:grid-cols-3 lg:gap-6 [&>*:last-child]:col-span-2 md:[&>*:last-child]:col-span-1">
            <div class="bg-white shadow rounded-lg p-5">
                <div class="flex items-center">
                    <i data-lucide="folder" class="h-6 w-6 text-blue-600"></i>
                    <div class="ml-4">
                        <div class="text-sm text-gray-500">{{ __('admin.keyword_libraries.total') }}</div>
                        <div class="text-lg font-medium text-gray-900">{{ (int) ($stats['total_libraries'] ?? 0) }}</div>
                    </div>
                </div>
            </div>
            <div class="bg-white shadow rounded-lg p-5">
                <div class="flex items-center">
                    <i data-lucide="key" class="h-6 w-6 text-green-600"></i>
                    <div class="ml-4">
                        <div class="text-sm text-gray-500">{{ __('admin.keyword_libraries.total_keywords') }}</div>
                        <div class="text-lg font-medium text-gray-900">{{ (int) ($stats['total_keywords'] ?? 0) }}</div>
                    </div>
                </div>
            </div>
            <div class="bg-white shadow rounded-lg p-5">
                <div class="flex items-center">
                    <i data-lucide="trending-up" class="h-6 w-6 text-purple-600"></i>
                    <div class="ml-4">
                        <div class="text-sm text-gray-500">{{ __('admin.common.avg_per_library') }}</div>
                        <div class="text-lg font-medium text-gray-900">{{ (float) ($stats['avg_keywords'] ?? 0) }}</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="bg-white shadow rounded-lg">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">{{ __('admin.keyword_libraries.list_title') }}</h3>
            </div>
            @if (empty($libraries))
                <div class="px-6 py-10 text-center">
                    <i data-lucide="folder-plus" class="w-12 h-12 mx-auto text-gray-400 mb-4"></i>
                    <h3 class="text-lg font-medium text-gray-900 mb-2">{{ __('admin.keyword_libraries.empty') }}</h3>
                    <p class="text-gray-500 mb-4">{{ __('admin.keyword_libraries.empty_desc') }}</p>
                    <a href="{{ route('admin.keyword-libraries.create') }}" class="inline-flex min-h-10 items-center rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition-[background-color,transform] duration-150 [@media(hover:hover)]:hover:bg-blue-700 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2">
                        <i data-lucide="plus" class="w-4 h-4 mr-2"></i>
                        {{ __('admin.keyword_libraries.create') }}
                    </a>
                </div>
            @else
                {{-- 【本地扩展】按品牌分组显示。
                     不分组的话，「冰溪｜A」「冰溪｜B」「海洋至尊｜A」全平铺在一起，
                     品牌一多就分不清谁是谁的库。 --}}
                @php
                    $brandNames = array_map(static fn ($b) => (string) ($b['name'] ?? ''), \App\Support\GeoAudit\GeoShareOfVoice::brands());
                    $grouped = [];
                    foreach ($libraries as $lib) {
                        $libName = (string) ($lib['name'] ?? '');
                        $owner = '未分组';
                        // 先按品牌档案里的名字匹配
                        foreach ($brandNames as $bn) {
                            if ($bn !== '' && str_contains($libName, $bn)) {
                                $owner = $bn;
                                break;
                            }
                        }
                        // 兜底：库名格式是「品牌｜类 中文名」，直接取竖线前面那段。
                        // 这样还没进品牌档案的新客户（比如刚建的瑞贝卡）也能正确分组，
                        // 不会全挤进「未分组」。
                        if ($owner === '未分组' && str_contains($libName, '｜')) {
                            $prefix = trim(explode('｜', $libName)[0]);
                            if ($prefix !== '') {
                                $owner = $prefix;
                            }
                        }
                        $grouped[$owner][] = $lib;
                    }
                    $ordered = [];
                    foreach ($brandNames as $bn) {
                        if ($bn !== '' && isset($grouped[$bn])) {
                            $ordered[$bn] = $grouped[$bn];
                            unset($grouped[$bn]);
                        }
                    }
                    $ordered += $grouped;
                @endphp
                @foreach ($ordered as $owner => $ownerLibs)
                @php
                    $ownerTotal = array_sum(array_map(static fn ($l) => (int) ($l['actual_count'] ?? 0), $ownerLibs));
                @endphp
                <details class="group border-b border-gray-200">
                    <summary class="flex cursor-pointer list-none items-center justify-between bg-gray-50 px-6 py-3 transition-colors [@media(hover:hover)]:hover:bg-gray-100 [&::-webkit-details-marker]:hidden">
                        <div class="flex min-w-0 items-center gap-2">
                            <i data-lucide="chevron-right" class="kg-chev h-4 w-4 shrink-0 text-gray-400"></i>
                            <span class="truncate text-sm font-semibold text-gray-900">{{ $owner }}</span>
                            <span class="shrink-0 text-xs text-gray-500">{{ count($ownerLibs) }} 个库 · {{ $ownerTotal }} 条问题</span>
                        </div>
                        <span class="shrink-0 text-xs text-gray-400 group-open:hidden">展开</span>
                        <span class="hidden shrink-0 text-xs text-gray-400 group-open:inline">收起</span>
                    </summary>
                    <div class="divide-y divide-gray-200">
                    @foreach ($ownerLibs as $library)
                        <div class="px-6 py-6">
                            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-center space-x-3">
                                        <h4 class="text-lg font-medium text-gray-900">
                                            <a href="{{ route('admin.keyword-libraries.detail', ['libraryId' => (int) $library['id']]) }}" class="hover:text-blue-600">
                                                {{ $library['name'] }}
                                            </a>
                                        </h4>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-800">
                                            {{ __('admin.keyword_libraries.keyword_count', ['count' => (int) $library['actual_count']]) }}
                                        </span>
                                    </div>
                                    @if ($library['description'] !== '')
                                        <p class="mt-1 text-sm text-gray-600">{{ $library['description'] }}</p>
                                    @endif
                                    <div class="mt-2 flex items-center space-x-4 text-sm text-gray-500">
                                        <span>
                                            {{ __('admin.keyword_libraries.created_at', ['value' => $library['created_at'] ? \Illuminate\Support\Carbon::parse($library['created_at'])->format('Y-m-d H:i') : '-']) }}
                                        </span>
                                        <span>
                                            {{ __('admin.keyword_libraries.updated_at', ['value' => $library['updated_at'] ? \Illuminate\Support\Carbon::parse($library['updated_at'])->format('Y-m-d H:i') : '-']) }}
                                        </span>
                                    </div>
                                </div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <a href="{{ route('admin.keyword-libraries.import.create', ['libraryId' => (int) $library['id']]) }}" class="inline-flex min-h-10 items-center rounded-lg border border-gray-300 bg-white px-3 py-2 text-xs font-semibold text-gray-700 transition-[background-color,transform] duration-150 [@media(hover:hover)]:hover:bg-gray-50 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2">
                                        <i data-lucide="upload" class="w-4 h-4 mr-1"></i>
                                        {{ __('admin.button.import') }}
                                    </a>
                                    <a href="{{ route('admin.keyword-libraries.edit', ['libraryId' => (int) $library['id']]) }}" class="inline-flex min-h-10 items-center rounded-lg border border-gray-300 bg-white px-3 py-2 text-xs font-semibold text-gray-700 transition-[background-color,transform] duration-150 [@media(hover:hover)]:hover:bg-gray-50 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2">
                                        <i data-lucide="pencil" class="mr-1 h-4 w-4"></i>
                                        {{ __('admin.button.edit') }}
                                    </a>
                                    <a href="{{ route('admin.keyword-libraries.detail', ['libraryId' => (int) $library['id']]) }}" class="inline-flex min-h-10 items-center rounded-lg border border-gray-300 bg-white px-3 py-2 text-xs font-semibold text-gray-700 transition-[background-color,transform] duration-150 [@media(hover:hover)]:hover:bg-gray-50 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2">
                                        <i data-lucide="eye" class="w-4 h-4 mr-1"></i>
                                        {{ __('admin.button.view') }}
                                    </a>
                                    <form method="POST" action="{{ route('admin.keyword-libraries.delete', ['libraryId' => (int) $library['id']]) }}" class="inline-block" data-material-delete-form data-admin-confirm-form data-admin-confirm-tone="danger" data-admin-confirm-title="{{ __('admin.keyword_libraries.confirm_delete', ['name' => $library['name']]) }}" data-admin-confirm-message="{{ __('admin.action_dialog.generic_impact') }}" data-admin-confirm-label="{{ __('admin.button.delete') }}">
                                        @csrf
                                        <button type="submit" disabled aria-disabled="true" data-material-delete-submit class="inline-flex min-h-10 items-center rounded-lg bg-red-600 px-3 py-2 text-xs font-semibold text-white transition-[background-color,opacity,transform] duration-150 [@media(hover:hover)]:hover:bg-red-700 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-500 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 disabled:active:scale-100">
                                            <i data-lucide="trash-2" class="w-4 h-4 mr-1"></i>
                                            {{ __('admin.button.delete') }}
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    @endforeach
                    </div>
                </details>
                @endforeach
            @endif
        </div>
    </div>
@endsection
