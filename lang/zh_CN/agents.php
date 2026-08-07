<?php

return [
    'titles' => ['list' => '代理商管理', 'form' => '代理商档案', 'detail' => '代理商详情'],
    'list' => [
        'description' => '统一维护合作档案、政策等级与订单推广费依据。', 'configuration' => '代理商配置', 'create' => '新建代理商',
        'search' => '搜索名称或编号', 'all_statuses' => '全部状态', 'all_types' => '全部类型', 'all_policies' => '全部政策体系', 'all_grades' => '全部等级', 'clear' => '清除',
        'columns' => ['agent' => '代理商', 'type' => '类型', 'policy' => '政策体系', 'grade' => '当前等级', 'status' => '合作状态', 'created_at' => '建档时间'], 'empty' => '没有符合条件的代理商。',
    ],
    'form' => [
        'back_detail' => '返回代理商详情', 'back_list' => '返回代理商管理', 'profile' => '代理商档案', 'edit' => '编辑代理商', 'create' => '新建代理商',
        'edit_description' => '代理商编号建立后永久不变；等级调整从下月起生效。', 'create_description' => '建立合作档案并分配当前政策等级。', 'basic' => '基本资料', 'cooperation' => '合作与政策',
        'type' => '代理商类型', 'select' => '请选择', 'code_immutable' => '代理商编号（永久不变）', 'code_prefix' => '编号简称', 'code_description' => '2–8 位字母或数字，系统自动附加类型代码', 'name' => '代理商名称', 'business_role' => '业务角色', 'contact_name' => '联系人', 'contact_value' => '联系方式', 'policy_grade' => '政策等级', 'status' => '合作状态', 'active' => '合作中', 'paused' => '暂停', 'terminated' => '已终止（永久只读）', 'started' => '合作开始日期', 'ended' => '合作终止日期', 'notes' => '备注', 'save' => '保存代理商档案',
    ],
    'detail' => [
        'back' => '返回代理商管理', 'profile' => '合作档案', 'edit' => '编辑档案', 'name' => '代理商名称', 'code' => '代理商编号', 'type' => '类型', 'business_role' => '业务角色', 'policy_system' => '政策体系', 'unset_policy' => '未设置政策', 'grade' => '当前等级', 'unset_grade' => '未设置等级', 'contact_name' => '联系人', 'contact_value' => '联系方式', 'started' => '合作开始', 'ended' => '合作结束', 'effective_month' => '等级生效月', 'notes' => '备注', 'customers' => '来源客户', 'customer' => '客户', 'created_at' => '建档时间', 'no_customers' => '暂无来源客户。', 'orders' => '关联订单', 'project_amount' => '项目/金额', 'status' => '状态', 'promotion_fee' => '推广费', 'completed' => '已完成', 'pending' => '待完成', 'no_orders' => '暂无关联订单。', 'empty' => '—',
    ],
    'messages' => ['created' => '代理商档案已创建。', 'updated' => '代理商档案已更新；等级变化将在下月生效。'],
];
