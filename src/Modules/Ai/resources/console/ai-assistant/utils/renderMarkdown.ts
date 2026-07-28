/**
 * renderMarkdown — AI 消息轻量 Markdown 渲染器
 *
 * 零依赖、XSS 安全（先整体转义再生成受控标签）。
 * 支持：标题、加粗、行内代码、代码块、有序/无序列表、链接、换行。
 *
 * 链接约定：
 *  - [文案](/站内路径) → <a data-route> 由 ChatMessage 事件委托走 router.push（面板不关闭）
 *  - [文案](https://…) → 新窗口打开
 *  - `/以斜杠开头的行内代码` → 同样渲染为站内链接（模型偶尔用反引号包路径的兜底）
 */

function escapeHtml(s: string): string {
  return s
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;')
}

/** 行内元素：粗体 / 链接 / 行内代码（输入已转义） */
function renderInline(text: string): string {
  let out = text

  // 行内代码（先处理，避免内部字符被粗体/链接规则误伤）
  out = out.replace(/`([^`\n]+)`/g, (_m, code: string) => {
    const trimmed = code.trim()
    // 反引号包的站内路径 → 可点击链接（不暴露裸路径的兜底渲染）
    if (/^\/[\w\-/?=&#%]*$/.test(trimmed)) {
      return `<a class="md-link" data-route="${trimmed}" href="javascript:;">${trimmed}</a>`
    }
    return `<code class="md-code">${code}</code>`
  })

  // Markdown 链接 [text](url)
  out = out.replace(/\[([^\]\n]+)\]\(([^)\s]+)\)/g, (_m, label: string, url: string) => {
    if (url.startsWith('/')) {
      return `<a class="md-link" data-route="${url}" href="javascript:;">${label}</a>`
    }
    if (/^https?:\/\//.test(url)) {
      return `<a class="md-link" href="${url}" target="_blank" rel="noopener noreferrer">${label}</a>`
    }
    // 其他协议一律不生成链接（安全）
    return label
  })

  // 粗体 **text**
  out = out.replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>')

  return out
}

/**
 * 渲染 Markdown 文本为受控 HTML。
 */
export function renderMarkdown(raw: string): string {
  if (!raw) return ''

  const escaped = escapeHtml(raw)
  const lines = escaped.split('\n')
  const html: string[] = []

  let listTag: 'ul' | 'ol' | null = null
  let inCodeBlock = false
  const codeLines: string[] = []

  function closeList() {
    if (listTag) {
      html.push(`</${listTag}>`)
      listTag = null
    }
  }

  for (const line of lines) {
    // 代码块围栏
    if (/^```/.test(line.trim())) {
      if (inCodeBlock) {
        html.push(`<pre class="md-pre">${codeLines.join('\n')}</pre>`)
        codeLines.length = 0
        inCodeBlock = false
      } else {
        closeList()
        inCodeBlock = true
      }
      continue
    }
    if (inCodeBlock) {
      codeLines.push(line)
      continue
    }

    // 标题（面板空间小，统一渲染为加粗小节标题）
    const heading = line.match(/^(#{1,6})\s+(.*)$/)
    if (heading) {
      closeList()
      html.push(`<div class="md-heading">${renderInline(heading[2])}</div>`)
      continue
    }

    // 无序列表
    const ul = line.match(/^\s*[-*]\s+(.*)$/)
    if (ul) {
      if (listTag !== 'ul') {
        closeList()
        html.push('<ul class="md-list">')
        listTag = 'ul'
      }
      html.push(`<li>${renderInline(ul[1])}</li>`)
      continue
    }

    // 有序列表
    const ol = line.match(/^\s*\d+[.、]\s+(.*)$/)
    if (ol) {
      if (listTag !== 'ol') {
        closeList()
        html.push('<ol class="md-list">')
        listTag = 'ol'
      }
      html.push(`<li>${renderInline(ol[1])}</li>`)
      continue
    }

    closeList()

    if (line.trim() === '') {
      html.push('<div class="md-gap"></div>')
    } else {
      html.push(`<p class="md-p">${renderInline(line)}</p>`)
    }
  }

  // 收尾未闭合结构
  closeList()
  if (inCodeBlock && codeLines.length) {
    html.push(`<pre class="md-pre">${codeLines.join('\n')}</pre>`)
  }

  return html.join('')
}
