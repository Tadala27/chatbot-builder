import 'vue-router'
declare module 'vue-router' {
  interface RouteMeta {
    layout?: 'blank' | 'default' | 'component'
    unauthenticatedOnly?: boolean
    public?: boolean
  }
}
