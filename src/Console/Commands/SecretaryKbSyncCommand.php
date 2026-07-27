<?php

namespace MultiTenantSaas\Console\Commands;

use Illuminate\Console\Command;
use MultiTenantSaas\Modules\Ai\Services\SystemKb\SystemKbIndexer;

/**
 * secretary:kb:sync — 系统知识库索引同步（checksum 增量）
 *
 * 发现 docs-as-knowledge 约定目录下的全部 kb 文档并同步进
 * system_kb_documents / system_kb_chunks。未变化文档跳过，
 * 已消失文档清除。部署后与 migrate 同批执行。
 */
class SecretaryKbSyncCommand extends Command
{
    protected $signature = 'secretary:kb:sync';

    protected $description = '同步系统知识库索引（发现 kb 文档 → checksum 增量 → 分块 + embedding）';

    public function handle(SystemKbIndexer $indexer): int
    {
        $this->info('开始同步系统知识库…');

        $stats = $indexer->sync();

        $this->table(
            ['新增', '更新', '删除', '未变化'],
            [[$stats['added'], $stats['updated'], $stats['removed'], $stats['unchanged']]],
        );

        return self::SUCCESS;
    }
}
