#!/usr/bin/env python3
"""调用 ai-runner plan，将 agent-framework.md 作为需求传入"""
import sys
sys.path.insert(0, '/tmp/ai-task-runner')

from pathlib import Path
from ai_runner.commands.plan import PlanCommand

req = open('/Users/arthur/Devel/WorkSpaceAI/framework/multi_tenant_saas/agent-framework.md').read()

cmd = PlanCommand(
    project_dir=Path('/Users/arthur/Devel/WorkSpaceAI/framework/multi_tenant_saas'),
    requirement=req,
    mode='full',
    sprint_id='sprint-agent',
)
sys.exit(cmd.run())
