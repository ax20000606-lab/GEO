@php
    $isCreate = ($mode ?? 'create') === 'create';
    $model = $model ?? null;
    $normalizeScalar = static function (mixed $value, mixed $fallback = ''): string {
        if (is_string($value) || is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return is_string($fallback) || is_int($fallback) || is_float($fallback)
            ? (string) $fallback
            : '';
    };
    $fieldValue = static function (string $field, mixed $createDefault = '') use ($isCreate, $model, $normalizeScalar): string {
        $fallback = $isCreate ? $createDefault : data_get($model, $field, $createDefault);

        return $normalizeScalar(old($field, $fallback), $fallback);
    };
    $selectedModelType = $fieldValue('model_type', 'chat');
    $selectedStatus = $fieldValue('status', 'active');
    $selectedAccessScope = $fieldValue('access_scope', \App\Models\AiModel::ACCESS_SCOPE_USER_CONTENT);
    $maxTokensVisible = ($supportsModelMaxTokens ?? false) && $selectedModelType === 'chat';
@endphp

<div class="space-y-8">
    <fieldset>
        <legend class="text-sm font-semibold text-gray-900">{{ __('admin.ai_models.basic_section') }}</legend>
        <div class="mt-4 grid grid-cols-1 gap-5 md:grid-cols-2">
            <div>
                <label for="name" class="block text-sm font-medium text-gray-700">{{ __('admin.ai_models.field_name') }}</label>
                <input type="text" name="name" id="name" required value="{{ $fieldValue('name') }}" @error('name') aria-invalid="true" @enderror class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm" placeholder="{{ __('admin.ai_models.placeholder_name') }}" autocomplete="off">
                @error('name')
                    <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>
            @if ($actorIsSuperAdmin ?? false)
                <div>
                    <label for="access_scope" class="block text-sm font-medium text-gray-700">{{ __('admin.ai_models.field_access_scope') }}</label>
                    <select name="access_scope" id="access_scope" class="mt-1 block min-h-10 w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                        <option value="{{ \App\Models\AiModel::ACCESS_SCOPE_USER_CONTENT }}" @selected($selectedAccessScope === \App\Models\AiModel::ACCESS_SCOPE_USER_CONTENT)>{{ __('admin.ai_models.access_scope_user_content') }}</option>
                        <option value="{{ \App\Models\AiModel::ACCESS_SCOPE_SYSTEM_ONLY }}" @selected($selectedAccessScope === \App\Models\AiModel::ACCESS_SCOPE_SYSTEM_ONLY)>{{ __('admin.ai_models.access_scope_system_only') }}</option>
                    </select>
                    <p class="mt-1.5 text-xs leading-5 text-gray-500">{{ __('admin.ai_models.access_scope_help') }}</p>
                    @error('access_scope')
                        <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>
            @endif
            <div>
                <label for="version" class="block text-sm font-medium text-gray-700">{{ __('admin.ai_models.field_version') }}</label>
                <input type="text" name="version" id="version" value="{{ $fieldValue('version') }}" @error('version') aria-invalid="true" @enderror class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm" placeholder="{{ __('admin.ai_models.placeholder_version') }}" autocomplete="off">
                @error('version')
                    <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>
            <div>
                <label for="model_type" class="block text-sm font-medium text-gray-700">{{ __('admin.ai_models.field_type') }}</label>
                <select name="model_type" id="model_type" required class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                    <option value="chat" @selected($selectedModelType === 'chat')>{{ __('admin.ai_models.type_chat_option') }}</option>
                    <option value="embedding" @selected($selectedModelType === 'embedding')>{{ __('admin.ai_models.type_embedding_option') }}</option>
                </select>
                <p class="mt-1.5 text-xs leading-5 text-gray-500">{{ $isCreate ? __('admin.ai_models.type_help_create') : __('admin.ai_models.type_help') }}</p>
                @error('model_type')
                    <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>
            <div>
                <label for="model_id" class="block text-sm font-medium text-gray-700">{{ __('admin.ai_models.field_model_id') }}</label>
                <input type="text" name="model_id" id="model_id" required value="{{ $fieldValue('model_id') }}" @error('model_id') aria-invalid="true" @enderror class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm" placeholder="{{ __('admin.ai_models.placeholder_model_id') }}" autocomplete="off" spellcheck="false">
                @error('model_id')
                    <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>
        </div>
    </fieldset>

    <fieldset class="border-t border-gray-200 pt-7">
        <legend class="text-sm font-semibold text-gray-900">{{ __('admin.ai_models.connection_section') }}</legend>
        {{-- 【本地扩展】服务商预设：点一下自动填接口地址 + 模型 ID 提示。
             原生表单只给了一个 api_url 输入框（默认 DeepSeek），换个服务商要自己去查地址和
             model_id 该填什么，很容易卡住。这里把常用几家的正确写法固化成按钮。 --}}
        <div class="mt-4 rounded-lg border border-gray-200 bg-gray-50 p-4">
            <div class="text-xs font-semibold text-gray-700">服务商预设（点一下自动填下面的接口地址）</div>
            <div class="mt-2 flex flex-wrap gap-2" data-model-presets>
                <button type="button" data-url="https://api.deepseek.com" data-model="deepseek-chat" data-mid-hint="deepseek-chat 或 deepseek-reasoner" data-hint="API Key 在 platform.deepseek.com 创建。模型 ID 填 deepseek-chat（对话）或 deepseek-reasoner（推理）。"
                    class="inline-flex min-h-9 items-center rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-xs font-semibold text-gray-700 transition hover:bg-gray-100 active:scale-[0.97]">DeepSeek</button>
                <button type="button" data-url="https://ark.cn-beijing.volces.com/api/v3" data-model="" data-mid-hint="ep-2025xxxxxx-xxxxx 或 doubao-pro-32k" data-hint="豆包走火山方舟 OpenAI 兼容接口。模型 ID 有两种填法：① 推理接入点 ID（ep- 开头，在方舟控制台「在线推理」里创建接入点后拿到，最稳）；② 直接填模型名（如 doubao-pro-32k、doubao-1-5-pro-32k-250115，需要账号已开通该模型）。API Key 在方舟控制台「API Key 管理」创建。"
                    class="inline-flex min-h-9 items-center rounded-lg border border-violet-300 bg-violet-50 px-3 py-1.5 text-xs font-semibold text-violet-700 transition hover:bg-violet-100 active:scale-[0.97]">豆包（火山方舟）</button>
                <button type="button" data-url="https://dashscope.aliyuncs.com/compatible-mode/v1" data-model="qwen-plus" data-mid-hint="qwen-plus 或 qwen-max" data-hint="通义千问走百炼 OpenAI 兼容模式。模型 ID 填 qwen-plus / qwen-max / qwen-turbo。注意：百炼的 embedding 和 chat 是两个地址，这里只用于对话模型。"
                    class="inline-flex min-h-9 items-center rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-xs font-semibold text-gray-700 transition hover:bg-gray-100 active:scale-[0.97]">通义千问</button>
                <button type="button" data-url="https://api.moonshot.cn/v1" data-model="moonshot-v1-8k" data-mid-hint="moonshot-v1-8k / 32k / 128k" data-hint="Kimi 走 Moonshot 开放平台。模型 ID 按上下文长度选：moonshot-v1-8k / moonshot-v1-32k / moonshot-v1-128k。"
                    class="inline-flex min-h-9 items-center rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-xs font-semibold text-gray-700 transition hover:bg-gray-100 active:scale-[0.97]">Kimi</button>
                <button type="button" data-url="https://open.bigmodel.cn/api/paas/v4" data-model="glm-4-plus" data-mid-hint="glm-4-plus / glm-4-air" data-hint="智谱 GLM 走开放平台 v4 接口。模型 ID 填 glm-4-plus / glm-4-air / glm-4-flash。"
                    class="inline-flex min-h-9 items-center rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-xs font-semibold text-gray-700 transition hover:bg-gray-100 active:scale-[0.97]">智谱 GLM</button>
            </div>
            <div id="modelPresetHint" class="mt-2 hidden rounded-md bg-white p-2.5 text-xs leading-5 text-gray-600 ring-1 ring-gray-200"></div>
        </div>
        <div class="mt-4 grid grid-cols-1 gap-5">
            <div>
                <label for="api_url" class="block text-sm font-medium text-gray-700">{{ __('admin.ai_models.field_api_url') }}</label>
                <input type="url" name="api_url" id="api_url" value="{{ $fieldValue('api_url', 'https://api.deepseek.com') }}" @error('api_url') aria-invalid="true" @enderror class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm" placeholder="{{ __('admin.ai_models.placeholder_api_url') }}" autocomplete="url" spellcheck="false">
                <p class="mt-1.5 text-xs leading-5 text-gray-500">{{ $isCreate ? __('admin.ai_models.api_url_help_create') : __('admin.ai_models.api_url_help') }}</p>
                @error('api_url')
                    <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="api_key" class="block text-sm font-medium text-gray-700">{{ __('admin.ai_models.field_api_key') }}</label>
                <div class="relative mt-1">
                    <input type="password" name="api_key" id="api_key" @required($isCreate) @error('api_key') aria-invalid="true" @enderror class="block w-full rounded-lg border-gray-300 {{ $isCreate ? 'pr-24' : '' }} shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm" placeholder="{{ $isCreate ? __('admin.ai_models.placeholder_api_key') : __('admin.ai_models.placeholder_api_key_keep') }}" autocomplete="new-password" spellcheck="false">
                    @if ($isCreate)
                        <button type="button" class="absolute inset-y-0 right-1 my-1 inline-flex min-w-20 items-center justify-center rounded-md px-3 text-xs font-semibold text-gray-500 transition-[color,background-color,transform] duration-150 hover:bg-gray-100 hover:text-gray-800 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500" data-api-key-toggle aria-controls="api_key" aria-pressed="false">
                            {{ __('admin.ai_models.show_api_key') }}
                        </button>
                    @endif
                </div>
                <p id="apiKeyHelp" class="mt-1.5 text-xs leading-5 text-gray-500">{{ $isCreate ? __('admin.ai_models.api_key_help_create') : __('admin.ai_models.api_key_help_edit') }}</p>
                @error('api_key')
                    <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>
        </div>
    </fieldset>

    <fieldset class="border-t border-gray-200 pt-7">
        <legend class="text-sm font-semibold text-gray-900">{{ __('admin.ai_models.advanced_section') }}</legend>
        <div @class(['mt-4 grid grid-cols-1 gap-5 md:grid-cols-2', 'lg:grid-cols-3' => $isCreate])>
            <div>
                <label for="failover_priority" class="block text-sm font-medium text-gray-700">{{ __('admin.ai_models.field_failover_priority') }}</label>
                <input type="number" name="failover_priority" id="failover_priority" min="1" value="{{ $fieldValue('failover_priority', 100) }}" @error('failover_priority') aria-invalid="true" @enderror class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm tabular-nums">
                <p class="mt-1.5 text-xs leading-5 text-gray-500">{{ __('admin.ai_models.failover_priority_help') }}</p>
                @error('failover_priority')
                    <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>
            <div>
                <label for="daily_limit" class="block text-sm font-medium text-gray-700">{{ __('admin.ai_models.field_daily_limit') }}</label>
                <input type="number" name="daily_limit" id="daily_limit" min="0" value="{{ $fieldValue('daily_limit', 0) }}" @error('daily_limit') aria-invalid="true" @enderror class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm tabular-nums">
                <p class="mt-1.5 text-xs leading-5 text-gray-500">{{ __('admin.ai_models.limit_help') }}</p>
                @error('daily_limit')
                    <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>
            <div data-max-tokens-field class="{{ $maxTokensVisible ? '' : 'hidden' }}">
                <label for="max_tokens" class="block text-sm font-medium text-gray-700">{{ __('admin.ai_models.field_max_tokens') }}</label>
                <input type="number" name="max_tokens" id="max_tokens" min="1" value="{{ $fieldValue('max_tokens') }}" @error('max_tokens') aria-invalid="true" @enderror class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm tabular-nums" placeholder="{{ __('admin.ai_models.max_tokens_placeholder', ['tokens' => (int) ($contentMaxTokens ?? 16384)]) }}">
                <p class="mt-1.5 text-xs leading-5 text-gray-500">{{ $isCreate ? __('admin.ai_models.max_tokens_help_create') : __('admin.ai_models.max_tokens_help') }}</p>
                @error('max_tokens')
                    <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>
            @unless ($isCreate)
                <div id="statusField">
                    <label for="status" class="block text-sm font-medium text-gray-700">{{ __('admin.ai_models.field_status') }}</label>
                    <select name="status" id="status" class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                        <option value="active" @selected($selectedStatus === 'active')>{{ __('admin.ai_models.status_active') }}</option>
                        <option value="inactive" @selected($selectedStatus === 'inactive')>{{ __('admin.ai_models.status_inactive') }}</option>
                    </select>
                    @error('status')
                        <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>
            @endunless
        </div>
    </fieldset>
</div>

<script>
(function () {
    var wrap = document.querySelector('[data-model-presets]');
    if (!wrap) return;
    var urlEl = document.getElementById('api_url');
    var midEl = document.getElementById('model_id');
    var hintEl = document.getElementById('modelPresetHint');

    wrap.addEventListener('click', function (ev) {
        var btn = ev.target.closest('button[data-url]');
        if (!btn) return;

        if (urlEl) {
            urlEl.value = btn.getAttribute('data-url') || '';
            // 手动改过的值不要覆盖：只在模型 ID 为空、或者上次是自动填的时候才替换
            urlEl.dispatchEvent(new Event('input', { bubbles: true }));
        }

        var mid = btn.getAttribute('data-model') || '';
        if (midEl) {
            var auto = midEl.getAttribute('data-auto-filled') === '1';
            if (midEl.value.trim() === '' || auto) {
                midEl.value = mid;
                midEl.setAttribute('data-auto-filled', '1');
            }
            var midHint = btn.getAttribute('data-mid-hint');
            if (midHint) midEl.placeholder = midHint;
        }

        var hint = btn.getAttribute('data-hint') || '';
        if (hintEl) {
            hintEl.textContent = hint;
            hintEl.classList.toggle('hidden', hint === '');
        }
    });

    // 用户自己敲了模型 ID，就别再被预设覆盖
    if (midEl) {
        midEl.addEventListener('input', function () {
            midEl.setAttribute('data-auto-filled', '0');
        });
    }
})();
</script>
