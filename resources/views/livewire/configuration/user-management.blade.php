<div>
    <x-page-back :href="route('configuration.index')" label="返回配置中心" class="mb-4" />
    <section class="crm-section-header">
        <div>
            <p class="text-xs font-medium text-zinc-400">配置中心 · 账号权限</p>
            <h2 class="mt-1 text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-50">内部用户管理</h2>
            <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">新用户通过一次性密码设置链接激活；停用账号后，该用户将无法登录，已登录的也会立即退出。</p>
        </div>
        <a href="{{ route('audit-logs.index') }}" class="inline-flex items-center gap-1 text-sm font-semibold text-teal-700 hover:underline dark:text-teal-300" wire:navigate>
            查看全局审计日志
            <flux:icon.arrow-right class="size-4" aria-hidden="true" />
        </a>
    </section>
    @if (session('status'))<div class="mb-5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('status') }}</div>@endif
    @if (session('error'))<div class="mb-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ session('error') }}</div>@endif
    @error('userManagement')<div class="mb-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ $message }}</div>@enderror

    <form wire:submit="invite" class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <h3 class="font-semibold">邀请内部用户</h3>
        <div class="mt-4 grid gap-4 md:grid-cols-[1fr_1fr_auto_auto]">
            <flux:input wire:model="name" label="姓名" />
            <flux:input wire:model="email" type="email" label="邮箱" />
            <flux:checkbox wire:model="isSuperAdmin" label="超级管理员" class="self-end pb-2" />
            <flux:button type="submit" variant="primary" class="self-end">创建并发送邀请</flux:button>
        </div>
    </form>

    <section class="mt-6 rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <h3 class="font-semibold">账号列表</h3>
        <div class="crm-table-wrap mt-4">
            <table class="crm-table">
                <thead><tr><th>用户</th><th>角色</th><th>账号</th><th>邀请</th><th>操作</th></tr></thead>
                <tbody>
                    @foreach ($users as $user)
                        <tr>
                            <td><strong>{{ $user['name'] }}</strong><br><span class="text-xs text-zinc-500">{{ $user['email'] }}</span></td>
                            <td>{{ $user['is_super_admin'] ? '超级管理员' : '内部用户' }}</td>
                            <td>{{ $user['is_active'] ? '启用' : '停用' }}</td>
                            <td>
                                {{ $user['invitation_status'] }}
                                @if ($user['invitation_sent_at'])<br><span class="text-xs text-zinc-500">{{ $user['invitation_sent_at'] }}</span>@endif
                            </td>
                            <td>
                                <div class="flex flex-wrap gap-2">
                                    <flux:button wire:click="toggleRole({{ $user['id'] }}, {{ $user['is_super_admin'] ? 'false' : 'true' }})" variant="ghost" size="sm">
                                        {{ $user['is_super_admin'] ? '改为内部用户' : '设为超级管理员' }}
                                    </flux:button>
                                    <flux:button wire:click="toggleActive({{ $user['id'] }}, {{ $user['is_active'] ? 'false' : 'true' }})" variant="ghost" size="sm">
                                        {{ $user['is_active'] ? '停用' : '启用' }}
                                    </flux:button>
                                    @if (in_array($user['invitation_status'], ['pending', 'failed', 'sent'], true))
                                        <flux:button wire:click="resend({{ $user['id'] }})" variant="ghost" size="sm">重发邀请</flux:button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
</div>
