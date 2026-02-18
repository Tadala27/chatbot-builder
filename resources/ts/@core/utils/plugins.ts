import type { App } from 'vue'

/**
 * This is helper function to register plugins like a nuxt
 * To register a plugin just export a const function `defineVuePlugin` that takes `app` as argument and call `app.use`
 * For Scanning plugins it will include all files in `src/plugins` and `src/plugins/**\/index.ts`
 *
 *
 * @param {App} app Vue app instance
 *
 */

export function registerPlugins(app: App) {
  const imports = import.meta.glob<{ default: (app: App) => void }>(['../../plugins/*.{ts,js}', '../../plugins/*/index.{ts,js}'], {
    eager: true,
  })

  const importPaths = Object.keys(imports).sort()

  importPaths.forEach(path => {
    const pluginImportModule = imports[path]

    if (pluginImportModule.default && typeof pluginImportModule.default === 'function')
      pluginImportModule.default(app)
  })
}
