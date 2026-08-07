<?php

return [
    'titles' => ['center' => '主动提醒', 'create' => '新建提醒', 'history' => '提醒历史', 'configuration' => '主动提醒配置'],
    'center' => [
        'description' => '集中处理术前、到院、术后及自定义跟进任务；提醒仅发送给内部员工。', 'history' => '提醒历史', 'create' => '新建提醒', 'pending' => '待处理', 'overdue' => '已超期', 'completed' => '累计完成', 'type' => '提醒类型', 'all_types' => '全部类型', 'appointment' => '术前/到院', 'post_treatment' => '术后系列', 'date_offset' => '日期规则', 'fixed_cycle' => '周期规则', 'custom' => '客服自定义', 'unknown_customer' => '未知客户', 'no_script' => '无固定话术，请员工自行填写。', 'due' => '已到期', 'complete_notes' => '完成/关闭备注', 'snooze_until' => '延期至', 'snooze_reason' => '延期原因', 'transfer_to' => '转交给', 'select' => '请选择', 'mark_complete' => '标记完成', 'snooze' => '延期', 'transfer' => '转交', 'cancel' => '关闭', 'retry_notification' => '重试钉钉', 'dingtalk' => '钉钉：:status', 'empty' => '当前没有待处理提醒。',
    ],
    'statuses' => ['pending' => '待处理', 'completed' => '已完成', 'cancelled' => '已关闭', 'overdue' => '已到期'],
    'notification_statuses' => ['pending' => '待下发', 'queued' => '队列中', 'sent' => '已发送', 'failed' => '发送失败', 'disabled' => '未启用'],
    'create' => [
        'back' => '返回主动提醒', 'eyebrow' => '客户跟进', 'description' => '创建一次性或周期提醒，也可从模板快速开始。', 'template' => '提醒模板', 'without_template' => '不使用模板', 'customer' => '关联客户', 'select_customer' => '请选择', 'title' => '提醒标题', 'assignee' => '负责人', 'due_at' => '提醒时间', 'suggestion' => '建议方向（不是固定话术）', 'notes' => '空白话术/工作备注', 'recurrence' => '重复周期', 'once' => '仅一次', 'day' => '每 N 天', 'week' => '每 N 周', 'month' => '每 N 月', 'interval' => '周期数 N', 'save_template' => '保存为我的个人模板', 'template_name' => '个人模板名称', 'submit' => '创建提醒',
    ],
    'history' => ['back' => '返回主动提醒', 'eyebrow' => '客户跟进', 'description' => '查看已完成和已关闭的提醒记录。', 'reminder' => '提醒', 'customer' => '客户', 'due_at' => '计划时间', 'status' => '状态', 'completed_at' => '完成时间', 'unknown_customer' => '未知客户', 'empty' => '暂无历史提醒。'],
    'configuration' => [
        'back' => '返回配置中心', 'eyebrow' => '配置中心 · 主动提醒', 'heading' => '主动提醒规则与模板', 'description' => '从固定的触发类型和适用范围中选择来配置提醒规则，暂不支持自定义脚本或复杂条件。', 'dingtalk' => '钉钉通知：:status', 'enabled' => '已启用', 'disabled_hint' => '未启用；站内提醒仍正常运行', 'new_rule' => '新增规则', 'rule_name' => '规则名称', 'title' => '提醒标题', 'trigger_type' => '触发类型', 'date_offset' => '日期字段偏移', 'status_change' => '客户状态变化', 'fixed_cycle' => '固定周期', 'manual' => '仅手动', 'trigger_time' => '触发时间', 'date_field' => '日期字段', 'created_at' => '建档日期', 'appointment_at' => '到店日期', 'completed_on' => '施术日期', 'birth_date' => '生日', 'wechat_added_on' => '加微信日期', 'offset_days' => '偏移天数（可为负）', 'interval_days' => '每 N 天', 'scope' => '适用范围', 'all_customers' => '全部客户', 'agent' => '指定代理商 ID', 'project' => '指定项目', 'owner' => '指定负责人 ID', 'cooperation_status' => '代理商合作状态', 'scope_value' => '范围值（全部客户可留空）', 'suggestion' => '建议方向', 'priority' => '优先级（1最高）', 'save_rule_edit' => '保存规则修改', 'save_rule' => '保存规则', 'global_templates' => '全局模板', 'template_name' => '模板名称', 'template_suggestion' => '建议方向', 'add_template' => '新增模板', 'template' => '模板', 'type' => '类型', 'status' => '状态', 'system' => '系统', 'global' => '全局', 'enabled_status' => '启用', 'disabled_status' => '停用', 'edit' => '编辑', 'copy' => '复制', 'enable' => '启用', 'disable' => '停用', 'configured_rules' => '已配置规则', 'trigger_scope' => '触发/范围', 'empty' => '尚未配置规则。',
    ],
    'trigger_types' => ['date_offset' => '日期字段偏移', 'status_change' => '客户状态变化', 'fixed_cycle' => '固定周期', 'manual' => '仅手动'],
    'scope_types' => ['all_customers' => '全部客户', 'agent' => '指定代理商 ID', 'project' => '指定项目', 'owner' => '指定负责人 ID', 'cooperation_status' => '代理商合作状态'],
    'copy_suffix' => ':name 副本',
    'toasts' => ['completed' => '提醒已完成。', 'snoozed' => '提醒已延期。', 'transferred' => '提醒已转交。', 'cancelled' => '提醒已关闭。', 'retry_notification' => '钉钉通知已重新进入队列。', 'created' => '提醒已创建。', 'rule_saved' => '主动提醒规则已保存。', 'template_saved' => '全局提醒模板已保存。'],
    'notifications' => [
        'unassigned' => '未分配',
        'no_script' => '无固定话术，请员工自行填写',
        'body' => "客户：:customer\n\n负责人：:owner\n\n计划时间：:due_at\n\n建议方向：:suggestion",
    ],
];
