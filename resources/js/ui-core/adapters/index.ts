/**
 * UI 框架适配器
 */

export { elementPlusAdapter, elementPlusMetadata } from './element-plus'
export { antDesignAdapter, antDesignMetadata } from './ant-design'
export { naiveUIAdapter, naiveUIMetadata } from './naive-ui'
export { arcoDesignAdapter, arcoDesignMetadata } from './arco-design'
export { tdesignAdapter, tdesignMetadata } from './tdesign'
export { varletAdapter, varletMetadata } from './varlet'

import { elementPlusAdapter } from './element-plus'
import { antDesignAdapter } from './ant-design'
import { naiveUIAdapter } from './naive-ui'
import { arcoDesignAdapter } from './arco-design'
import { tdesignAdapter } from './tdesign'
import { varletAdapter } from './varlet'
import { uiRegistry } from '../registry'

/**
 * 注册所有内置适配器
 */
export function registerBuiltinAdapters() {
  uiRegistry.register(elementPlusAdapter)
  uiRegistry.register(antDesignAdapter)
  uiRegistry.register(naiveUIAdapter)
  uiRegistry.register(arcoDesignAdapter)
  uiRegistry.register(tdesignAdapter)
  uiRegistry.register(varletAdapter)
}
