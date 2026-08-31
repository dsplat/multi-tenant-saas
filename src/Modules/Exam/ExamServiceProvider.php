<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Exam;

use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;

/**
 * Exam 模块（考试/测评）
 *
 * 题库/题目/试卷/答卷四实体 + 客观题自动判分 + 组卷（固定/随机）。
 * 事件扩展点：ExamPassed（项目层监听做证书颁发/画像聚合）。
 * 主观题不在本模块：作答走项目层 submissions（subject=exam_question）。
 */
class ExamServiceProvider extends ModuleServiceProvider
{
    protected string $moduleName = 'exam';
}
